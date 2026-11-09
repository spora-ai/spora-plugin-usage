<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Services;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Metering\LlmUsage;
use Spora\Metering\MeteringContext;
use Spora\Metering\MeteringSubject;
use Spora\Metering\ToolUsage;
use Spora\Models\Principal;
use Spora\Plugins\Usage\Models\UsageBucket;
use Spora\Plugins\Usage\Models\UsageCredit;
use Spora\Plugins\Usage\Models\UsageLedger as LedgerRow;
use Spora\Plugins\Usage\ValueObjects\CreditAmount;

/**
 * The writer of the metering record.
 *
 * One metered event produces one ledger row, one bucket row per owner scope
 * and one debit per scope that has a credit pool. They move together in a
 * single transaction: a ledger row without its buckets would understate the
 * spend, and a bucket without its ledger row would be spend nobody can
 * explain.
 */
final class UsageLedger
{
    private const DEBIT_ATTEMPTS = 5;

    /**
     * The type of a principal whose row has gone.
     *
     * The task's principal is a foreign key, so the row is there — but a
     * deleted principal must not be guessed at, and a wrong type would file
     * a group's spend under a user.
     */
    private const PRINCIPAL_TYPE_UNKNOWN = 'unknown';

    /** @var array<int, string> */
    private array $principalTypes = [];

    public function __construct(
        private readonly WindowCalculator $windowCalculator = new WindowCalculator(),
    ) {}

    /**
     * @param  OwnerScope[]  $scopes  The scopes the event is charged to.
     */
    public function record(
        MeteringContext $context,
        MeteringSubject $subject,
        LlmUsage|ToolUsage $usage,
        float $credits,
        bool $priced,
        array $scopes,
    ): void {
        Capsule::connection()->transaction(function () use ($context, $subject, $usage, $credits, $priced, $scopes): void {
            $occurredAt = new DateTimeImmutable();

            $this->writeLedgerRow($context, $subject, $usage, $credits, $priced, $occurredAt);

            foreach ($scopes as $scope) {
                $this->addToBucket($scope, $usage, $credits, $occurredAt);
                $this->debitCredits($scope, $credits);
            }
        });
    }

    private function writeLedgerRow(
        MeteringContext $context,
        MeteringSubject $subject,
        LlmUsage|ToolUsage $usage,
        float $credits,
        bool $priced,
        DateTimeImmutable $occurredAt,
    ): void {
        $isLlm = $usage instanceof LlmUsage;

        LedgerRow::create([
            'principal_id'  => $context->principalId,
            'principal_type' => $this->principalType($context->principalId),
            'user_id'       => $context->isSystemRun ? null : $context->triggerUserId,
            'kind'          => $subject->kind(),
            'provider'      => $isLlm ? $usage->provider : null,
            'model'         => $isLlm ? $usage->model : null,
            'tool_name'     => $isLlm ? null : $usage->toolName,
            'tool_class'    => $isLlm ? null : $usage->toolClass,
            'operation'     => $isLlm ? null : $usage->operation,
            'input_tokens'  => $isLlm ? $usage->inputTokens() : 0,
            'output_tokens' => $isLlm ? $usage->outputTokens() : 0,
            'cached_tokens' => $isLlm ? $usage->cachedTokens() : 0,
            'credits'       => CreditAmount::round($credits),
            'priced'        => $priced,
            'task_id'       => $context->taskId,
            'agent_id'      => $context->agentId,
            'occurred_at'   => $occurredAt,
            'source'        => 'live',
        ]);
    }

    /**
     * Add one event to the owner's bucket for the slot it falls into.
     *
     * The row is **created without reading it first**, and the counters are
     * then incremented in one statement.
     *
     * A read-then-insert loses a race. `lockForUpdate()` locks only the rows
     * that exist, so two tasks writing the first event of a slot both read
     * nothing and both insert — and the loser of that race takes the whole
     * transaction down with it, ledger row and debit included. The meter
     * catches the failure and only logs it, so the usage silently vanishes.
     * The gap locks InnoDB takes on the missing row deadlock the pair as
     * well.
     *
     * An insert that cannot conflict, followed by `col = col + x`, cannot
     * lose: the second writer's insert is ignored and its increment lands on
     * the row the first one made.
     */
    private function addToBucket(
        OwnerScope $scope,
        LlmUsage|ToolUsage $usage,
        float $credits,
        DateTimeImmutable $occurredAt,
    ): void {
        $bucketStart = $this->windowCalculator->bucketStart($occurredAt)->format('Y-m-d H:i:s');
        $isLlm = $usage instanceof LlmUsage;

        UsageBucket::query()->insertOrIgnore([
            'owner_type'    => $scope->type,
            'owner_id'      => $scope->id,
            'bucket_start'  => $bucketStart,
            'credits'       => 0,
            'total_tokens'  => 0,
            'output_tokens' => 0,
            'llm_calls'     => 0,
            'tool_calls'    => 0,
        ]);

        UsageBucket::query()
            ->where('owner_type', $scope->type)
            ->where('owner_id', $scope->id)
            ->where('bucket_start', $bucketStart)
            ->incrementEach([
                'credits'       => CreditAmount::round($credits),
                'total_tokens'  => $isLlm ? $usage->totalTokens() : 0,
                'output_tokens' => $isLlm ? $usage->outputTokens() : 0,
                'llm_calls'     => $isLlm ? 1 : 0,
                'tool_calls'    => $isLlm ? 0 : 1,
            ]);
    }

    /**
     * Debit the credit pool of one scope.
     *
     * The write is a compare-and-set on the balance that was read: two
     * concurrent debits that both read the same balance cannot both write
     * their own difference, so one of them loses the race and reads the
     * balance the winner left behind.
     */
    private function debitCredits(OwnerScope $scope, float $credits): void
    {
        if ($credits <= 0.0) {
            return;
        }

        for ($attempt = 0; $attempt < self::DEBIT_ATTEMPTS; $attempt++) {
            $target = $this->debitTarget($scope, $credits);

            if ($target === null) {
                return;
            }

            $applied = UsageCredit::query()
                ->where('owner_type', $scope->type)
                ->where('owner_id', $scope->id)
                ->where('balance', $target['observed'])
                ->update(['balance' => CreditAmount::format($target['next'])]);

            if ($applied > 0) {
                return;
            }
        }
    }

    /**
     * The balance to write, or null when there is nothing to debit.
     *
     * @return array{observed: mixed, next: float}|null
     */
    private function debitTarget(OwnerScope $scope, float $credits): ?array
    {
        $row = UsageCredit::query()
            ->where('owner_type', $scope->type)
            ->where('owner_id', $scope->id)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return null;
        }

        $observed = $row->getRawOriginal('balance');
        $next = CreditAmount::round((float) $observed - $credits);

        // A debit that rounds away to nothing is not worth a write: the
        // balance would not change, and a write that changes nothing is
        // indistinguishable from a lost race.
        if ($next === (float) $observed) {
            return null;
        }

        return ['observed' => $observed, 'next' => $next];
    }

    /**
     * The kind of principal an event was charged to.
     *
     * The metering context carries the principal's id alone, so the type is
     * resolved here and cached: a principal's type never changes, and one
     * lookup per metered event would be one query per provider call.
     */
    private function principalType(int $principalId): string
    {
        if (!isset($this->principalTypes[$principalId])) {
            $principal = Principal::query()->find($principalId);

            $this->principalTypes[$principalId] = $principal->type ?? self::PRINCIPAL_TYPE_UNKNOWN;
        }

        return $this->principalTypes[$principalId];
    }
}
