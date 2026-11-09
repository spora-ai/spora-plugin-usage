<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Services;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Metering\MeteringContext;
use Spora\Metering\UsageDecision;
use stdClass;
use Throwable;

/**
 * Answers "has this owner spent too much?" for one owner scope.
 */
final class UsageLimitService
{
    /**
     * The bucket counter every unit is measured by.
     *
     * The unit comes out of the database, so it is mapped through a
     * whitelist instead of being interpolated into the query.
     */
    private const COUNTERS = [
        'credits'       => 'credits',
        'total_tokens'  => 'total_tokens',
        'output_tokens' => 'output_tokens',
        'llm_calls'     => 'llm_calls',
        'tool_calls'    => 'tool_calls',
    ];

    public function __construct(
        private readonly WindowCalculator $windows,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * The strictest breach among the owner's enabled limits.
     *
     * A hard breach beats a soft one and, among equals, the first one found
     * wins. Null when no limit is breached.
     */
    public function evaluate(OwnerScope $owner, MeteringContext $context): ?UsageDecision
    {
        $worst = null;

        foreach ($this->enabledLimits($owner, $context) as $limit) {
            $decision = $this->decisionFor($owner, $limit, $context);

            if ($decision === null) {
                continue;
            }

            if ($worst === null || ($decision->isHard() && !$worst->isHard())) {
                $worst = $decision;
            }
        }

        return $worst;
    }

    /**
     * @return array<int, stdClass>
     */
    private function enabledLimits(OwnerScope $owner, MeteringContext $context): array
    {
        try {
            return Capsule::table('usage_limits')
                ->where('owner_type', $owner->type)
                ->where('owner_id', $owner->id)
                ->where('enabled', true)
                ->get()
                ->all();
        } catch (Throwable $e) {
            $this->logFailure('limits', $owner, $context, $e);

            return [];
        }
    }

    private function decisionFor(OwnerScope $owner, stdClass $limit, MeteringContext $context): ?UsageDecision
    {
        $unit = $this->asString($limit->unit ?? null);
        $counter = self::COUNTERS[$unit] ?? null;

        if ($counter === null) {
            $this->logger?->warning('Usage limit carries an unknown unit — it cannot be evaluated', [
                'owner_type' => $owner->type,
                'owner_id'   => $owner->id,
                'unit'       => $unit,
            ]);

            return null;
        }

        $window = $this->asString($limit->window ?? null);
        $windowSize = (int) ($limit->window_size ?? 1);
        $cap = $this->asFloat($limit->cap ?? null);
        $spend = $this->spendFor($owner, $window, $windowSize, $counter, $context);

        if ($spend === null) {
            return null;
        }

        if (round($spend, 8) < round($cap, 8)) {
            return null;
        }

        $mode = $this->asString($limit->mode ?? null) === UsageDecision::MODE_SOFT
            ? UsageDecision::MODE_SOFT
            : UsageDecision::MODE_HARD;

        return new UsageDecision(
            reason: $this->reason($window, $windowSize, $unit, $cap, $spend),
            mode: $mode,
            scope: $window . ':' . $unit,
        );
    }

    /**
     * The spend of one unit in one window, or null when it could not be read.
     */
    private function spendFor(
        OwnerScope $owner,
        string $window,
        int $windowSize,
        string $counter,
        MeteringContext $context,
    ): ?float {
        try {
            $range = $this->windows->rangeFor($window, $windowSize, new DateTimeImmutable());

            $query = Capsule::table('usage_buckets')
                ->where('owner_type', $owner->type)
                ->where('owner_id', $owner->id);

            // The window that has no lower bound covers everything ever recorded.
            if ($range->from !== null) {
                $query->where('bucket_start', '>=', $range->from);
            }

            $sum = $query
                ->where('bucket_start', '<=', $range->to)
                ->sum($counter);

            return $this->asFloat($sum);
        } catch (Throwable $e) {
            $this->logFailure('spend', $owner, $context, $e);

            return null;
        }
    }

    private function reason(string $window, int $windowSize, string $unit, float $cap, float $spend): string
    {
        return sprintf(
            'Usage limit reached: the %s window spent %s %s against a cap of %s.',
            $this->windowLabel($window, $windowSize),
            $this->amount($spend),
            $this->unitLabel($unit),
            $this->amount($cap),
        );
    }

    private function windowLabel(string $window, int $windowSize): string
    {
        return match ($window) {
            'hour'    => 'hourly',
            'day'     => 'daily',
            'week'    => 'weekly',
            'month'   => 'monthly',
            'rolling' => sprintf('rolling %d-hour', $windowSize),
            'total'   => 'all-time',
            default   => $window,
        };
    }

    private function unitLabel(string $unit): string
    {
        return match ($unit) {
            'credits'       => 'credits',
            'total_tokens'  => 'total tokens',
            'output_tokens' => 'output tokens',
            'llm_calls'     => 'LLM calls',
            'tool_calls'    => 'tool calls',
            default         => $unit,
        };
    }

    /**
     * A credit amount without the trailing zeroes a decimal column carries.
     */
    private function amount(float $value): string
    {
        $rounded = round($value, 8);

        if ($rounded === floor($rounded)) {
            return number_format($rounded, 0, '.', '');
        }

        return rtrim(rtrim(number_format($rounded, 8, '.', ''), '0'), '.');
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function logFailure(
        string $operation,
        OwnerScope $owner,
        MeteringContext $context,
        Throwable $e,
    ): void {
        $this->logger?->error('Usage limit evaluation failed — the call proceeds', [
            'operation'    => $operation,
            'owner_type'   => $owner->type,
            'owner_id'     => $owner->id,
            'task_id'      => $context->taskId,
            'principal_id' => $context->principalId,
            'exception'    => $e::class,
            'message'      => $e->getMessage(),
        ]);
    }
}
