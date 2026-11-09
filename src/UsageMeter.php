<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage;

use Psr\Log\LoggerInterface;
use Spora\Metering\LlmSubject;
use Spora\Metering\LlmUsage;
use Spora\Metering\MeteringContext;
use Spora\Metering\MeteringSubject;
use Spora\Metering\ToolSubject;
use Spora\Metering\ToolUsage;
use Spora\Metering\UsageDecision;
use Spora\Metering\UsageMeterInterface;
use Spora\Plugins\Usage\Services\CreditService;
use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\Services\PriceBook;
use Spora\Plugins\Usage\Services\UsageAttribution;
use Spora\Plugins\Usage\Services\UsageLedger;
use Spora\Plugins\Usage\Services\UsageLimitService;
use Throwable;

/**
 * The plugin's implementation of the core's metering seam.
 *
 * The core already refuses to let a meter break a run, but a meter that
 * threw on every call would fill the log with failures nobody can act on.
 * This one therefore swallows its own failures and lets the call proceed.
 */
final class UsageMeter implements UsageMeterInterface
{
    public function __construct(
        private readonly UsageAttribution $attribution,
        private readonly UsageLimitService $limits,
        private readonly CreditService $credits,
        private readonly PriceBook $priceBook,
        private readonly UsageLedger $ledger,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * The strictest breach among the scopes this event is charged to.
     *
     * A hard breach beats a soft one and, among equals, the first one found
     * wins: the pool of a scope is checked before that scope's limits, and
     * the principal before the individual.
     */
    public function evaluate(MeteringContext $context, MeteringSubject $subject): ?UsageDecision
    {
        try {
            $worst = null;

            foreach ($this->attribution->scopesFor($context) as $scope) {
                foreach ($this->breachesFor($scope, $context) as $decision) {
                    if ($worst === null || ($decision->isHard() && !$worst->isHard())) {
                        $worst = $decision;
                    }
                }
            }

            return $worst;
        } catch (Throwable $e) {
            $this->logFailure('evaluate', $context, $subject->kind(), $e);

            return null;
        }
    }

    public function recordLlmUsage(MeteringContext $context, LlmUsage $usage): void
    {
        try {
            $price = $this->priceBook->priceForLlm($usage->provider, $usage->model);

            $this->ledger->record(
                $context,
                new LlmSubject($usage->provider, $usage->model),
                $usage,
                $price === null ? 0.0 : $price->creditsFor($usage),
                $price !== null,
                $this->attribution->scopesFor($context),
            );
        } catch (Throwable $e) {
            $this->logFailure('recordLlmUsage', $context, MeteringSubject::KIND_LLM, $e);
        }
    }

    public function recordToolUsage(MeteringContext $context, ToolUsage $usage): void
    {
        try {
            $price = $this->priceBook->priceForTool($usage->toolClass, $usage->operation);

            $this->ledger->record(
                $context,
                new ToolSubject($usage->toolName, $usage->toolClass, $usage->operation),
                $usage,
                $price === null ? 0.0 : $price->creditsFor($usage),
                $price !== null,
                $this->attribution->scopesFor($context),
            );
        } catch (Throwable $e) {
            $this->logFailure('recordToolUsage', $context, MeteringSubject::KIND_TOOL, $e);
        }
    }

    /**
     * The breaches of one owner scope.
     *
     * @return list<UsageDecision>
     */
    private function breachesFor(OwnerScope $scope, MeteringContext $context): array
    {
        $breaches = [];

        $balance = $this->credits->balance($scope);

        if ($balance !== null && round($balance, 8) <= 0.0) {
            $breaches[] = new UsageDecision(
                reason: sprintf(
                    'Credit pool exhausted: the %s pool of %d has a balance of %s.',
                    $scope->type,
                    $scope->id,
                    $balance,
                ),
                mode: UsageDecision::MODE_HARD,
                scope: 'credits:balance',
            );
        }

        $decision = $this->limits->evaluate($scope, $context);

        if ($decision !== null) {
            $breaches[] = $decision;
        }

        return $breaches;
    }

    private function logFailure(string $operation, MeteringContext $context, string $kind, Throwable $e): void
    {
        $this->logger?->error('Usage meter failed — the call proceeds', [
            'operation'    => $operation,
            'kind'         => $kind,
            'task_id'      => $context->taskId,
            'principal_id' => $context->principalId,
            'exception'    => $e::class,
            'message'      => $e->getMessage(),
        ]);
    }
}
