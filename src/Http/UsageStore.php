<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Http;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Principal;
use Spora\Plugins\Usage\Models\UsageLimit;
use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\Services\WindowCalculator;
use Spora\Plugins\Usage\ValueObjects\CreditAmount;
use Spora\Plugins\Usage\ValueObjects\WindowRange;

/**
 * Read and write access to the owner's own usage: the principals, the
 * buckets, the credit balances and the credit grants.
 *
 * This class is the only place that knows their column names, so a schema
 * change lands in one file instead of in every controller. The limits and
 * the default sets are {@see UsageLimitStore}'s, the price book and the
 * unpriced report are {@see UsagePriceStore}'s.
 *
 * The windows are the meter's windows: {@see WindowCalculator} is the same
 * calculator the gate uses, so the number the panel shows and the number the
 * gate enforces are the same number.
 */
final class UsageStore
{
    private const TABLE_BUCKETS = 'usage_buckets';

    private const TABLE_CREDITS = 'usage_credits';

    private const TABLE_GRANTS = 'usage_credit_grants';

    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * The bucket timestamp on the wire.
     *
     * The database keeps a plain local timestamp and the contract asks for
     * an ISO-8601 one, so the two formats meet here.
     */
    private const SLOT_FORMAT = DATE_ATOM;

    /**
     * The hour count the rolling window falls back to when a principal has no
     * rolling limit of its own — the five-hour cap the domain names.
     */
    private const DEFAULT_ROLLING_HOURS = 5;

    /**
     * The bucket counter every unit is measured by.
     *
     * The unit comes out of the database and out of the request body, so it
     * is mapped through a whitelist instead of being interpolated into the
     * query.
     */
    private const COUNTERS = [
        UsageLimit::UNIT_CREDITS       => 'credits',
        UsageLimit::UNIT_TOTAL_TOKENS  => 'total_tokens',
        UsageLimit::UNIT_OUTPUT_TOKENS => 'output_tokens',
        UsageLimit::UNIT_LLM_CALLS     => 'llm_calls',
        UsageLimit::UNIT_TOOL_CALLS    => 'tool_calls',
    ];

    public function __construct(
        private readonly WindowCalculator $windows,
        private readonly UsageLimitStore $limits,
    ) {}

    /**
     * The ids of every principal in the install.
     *
     * @return list<int>
     */
    public function principalIds(): array
    {
        $ids = Capsule::table('principals')->orderBy('id')->pluck('id');

        return array_map(static fn(mixed $id): int => (int) $id, $ids->all());
    }

    /**
     * A principal's identity, or null when there is no such principal.
     *
     * @return array{id: int, type: string, name: string}|null
     */
    public function principal(int $principalId): ?array
    {
        $row = Capsule::table('principals')->where('id', $principalId)->first();

        if ($row === null) {
            return null;
        }

        $type = (string) $row->type;

        return [
            'id' => $principalId,
            'type' => $type,
            'name' => $type === Principal::TYPE_GROUP
                ? $this->groupLabel((int) $row->group_id)
                : $this->userLabel((int) $row->user_id),
        ];
    }

    /**
     * The whole picture for one principal: its identity, its credit balance,
     * its limits with what is left of each window, and its 30-day spend.
     *
     * One shape answers both the caller's own overview and the operator's
     * listing, so a key that reaches one of them reaches the other. The
     * balance is null when the principal has no pool at all.
     *
     * @return array{id: int, type: string, name: string, balance: float|null, spend_30d: float, limits: list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool, spend: float, remaining: float}>}|null
     */
    public function principalSummary(int $principalId, DateTimeImmutable $now): ?array
    {
        $principal = $this->principal($principalId);
        if ($principal === null) {
            return null;
        }

        $limits = $this->limits->effectiveLimits($principalId, $principal['type']);
        $balance = $this->creditBalance(OwnerScope::TYPE_PRINCIPAL, $principalId);
        $spend = $this->spendByWindow(OwnerScope::TYPE_PRINCIPAL, $principalId, $limits, $now);

        return [
            'id' => $principal['id'],
            'type' => $principal['type'],
            'name' => $principal['name'],
            'balance' => $balance,
            'spend_30d' => $spend[WindowCalculator::WINDOW_MONTH],
            'limits' => array_map(
                fn(array $limit): array => $this->limitWithSpend($limit, $principalId, $now),
                $limits,
            ),
        ];
    }



    /**
     * The limits a principal has of their own, with what each window has
     * spent.
     *
     * This is the set the panel edits, so it is the principal's own rows
     * and not the default that stands in for them.
     *
     * @return list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool, source: string, spend: float, remaining: float}>
     */
    public function ownLimits(int $principalId, DateTimeImmutable $now): array
    {
        $rows = $this->limits->limits(OwnerScope::TYPE_PRINCIPAL, $principalId);

        return array_map(
            fn(array $limit): array => $this->limitWithSpend(
                $limit + ['source' => 'principal'],
                $principalId,
                $now,
            ),
            $rows,
        );
    }







    /**
     * The credit balance of one owner scope, or null when it has no pool.
     */
    public function creditBalance(string $ownerType, int $ownerId): ?float
    {
        $balance = Capsule::table(self::TABLE_CREDITS)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->value('balance');

        return $balance === null ? null : CreditAmount::round((float) $balance);
    }

    /**
     * Add to the balance of one owner scope and record the grant.
     *
     * The grant row and the balance move together: a balance that moved
     * without a grant would leave no trace of who moved it. The row is read
     * under a lock, so a grant and a debit that land at the same time do
     * not both write the balance they read.
     */
    public function grant(string $ownerType, int $ownerId, float $amount, ?string $note): float
    {
        return Capsule::connection()->transaction(function () use ($ownerType, $ownerId, $amount, $note): float {
            $existing = Capsule::table(self::TABLE_CREDITS)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->lockForUpdate()
                ->first();

            $balance = $existing === null ? 0.0 : CreditAmount::round((float) $existing->balance);
            $updated = CreditAmount::round($balance + $amount);
            $now = date(self::TIMESTAMP_FORMAT);

            if ($existing === null) {
                Capsule::table(self::TABLE_CREDITS)->insert([
                    'owner_type' => $ownerType,
                    'owner_id'   => $ownerId,
                    'balance'    => CreditAmount::format($updated),
                ]);
            } else {
                Capsule::table(self::TABLE_CREDITS)
                    ->where('owner_type', $ownerType)
                    ->where('owner_id', $ownerId)
                    ->update(['balance' => CreditAmount::format($updated)]);
            }

            Capsule::table(self::TABLE_GRANTS)->insert([
                'owner_type' => $ownerType,
                'owner_id'   => $ownerId,
                'amount'     => CreditAmount::format($amount),
                'granted_at' => $now,
                'note'       => $note,
                'period'     => null,
            ]);

            return $updated;
        });
    }

    /**
     * The spend of one owner scope, per window, in credits.
     *
     * @param list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}> $limits
     * @return array<string, float>
     */
    public function spendByWindow(
        string $ownerType,
        int $ownerId,
        array $limits,
        DateTimeImmutable $now,
    ): array {
        $rollingHours = self::DEFAULT_ROLLING_HOURS;
        foreach ($limits as $limit) {
            if ($limit['window'] === WindowCalculator::WINDOW_ROLLING) {
                $rollingHours = $limit['window_size'];
            }
        }

        $spend = [];
        foreach ([
            WindowCalculator::WINDOW_HOUR,
            WindowCalculator::WINDOW_DAY,
            WindowCalculator::WINDOW_WEEK,
            WindowCalculator::WINDOW_MONTH,
        ] as $window) {
            $spend[$window] = $this->bucketSum($ownerType, $ownerId, $window, 1, UsageLimit::UNIT_CREDITS, $now);
        }

        $spend[WindowCalculator::WINDOW_ROLLING] = $this->bucketSum(
            $ownerType,
            $ownerId,
            WindowCalculator::WINDOW_ROLLING,
            $rollingHours,
            UsageLimit::UNIT_CREDITS,
            $now,
        );
        $spend[WindowCalculator::WINDOW_TOTAL] = $this->bucketSum(
            $ownerType,
            $ownerId,
            WindowCalculator::WINDOW_TOTAL,
            1,
            UsageLimit::UNIT_CREDITS,
            $now,
        );

        return $spend;
    }

    /**
     * The span a window covers.
     *
     * The bounds are the meter's bounds, so the spend the panel reports and
     * the spend the gate measures are the same span.
     */
    public function windowRange(string $window, int $windowSize, DateTimeImmutable $now): WindowRange
    {
        return $this->windows->rangeFor($window, $windowSize, $now);
    }

    /**
     * The bucket series of one owner scope over a window.
     *
     * The series is contiguous: a slot with no events is reported as a zero
     * rather than left out, so a chart does not read a gap as a hole in the
     * data.
     *
     * @return list<array{at: string, credits: float, total_tokens: int, output_tokens: int, llm_calls: int, tool_calls: int}>
     */
    public function series(
        string $ownerType,
        int $ownerId,
        string $window,
        int $windowSize,
        DateTimeImmutable $now,
    ): array {
        $range = $this->windowRange($window, $windowSize, $now);
        $hourly = $window !== WindowCalculator::WINDOW_MONTH;

        $query = Capsule::table(self::TABLE_BUCKETS)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('bucket_start', '<=', $range->to->format(self::TIMESTAMP_FORMAT));

        if ($range->from !== null) {
            $query->where('bucket_start', '>=', $range->from->format(self::TIMESTAMP_FORMAT));
        }

        $totals = [];

        foreach ($query->get() as $row) {
            $slot = $this->slotKey(new DateTimeImmutable((string) $row->bucket_start), $hourly);
            $totals[$slot] ??= [
                'credits' => 0.0,
                'total_tokens' => 0,
                'output_tokens' => 0,
                'llm_calls' => 0,
                'tool_calls' => 0,
            ];
            $totals[$slot]['credits'] += CreditAmount::round((float) $row->credits);
            $totals[$slot]['total_tokens'] += (int) $row->total_tokens;
            $totals[$slot]['output_tokens'] += (int) $row->output_tokens;
            $totals[$slot]['llm_calls'] += (int) $row->llm_calls;
            $totals[$slot]['tool_calls'] += (int) $row->tool_calls;
        }

        $series = [];
        foreach ($this->slots($range->from, $range->to, $hourly) as $slot) {
            $series[] = ['at' => $slot] + ($totals[$slot] ?? [
                'credits' => 0.0,
                'total_tokens' => 0,
                'output_tokens' => 0,
                'llm_calls' => 0,
                'tool_calls' => 0,
            ]);
        }

        return $series;
    }

    /**
     * The sum of one counter over one window.
     */
    public function bucketSum(
        string $ownerType,
        int $ownerId,
        string $window,
        int $windowSize,
        string $unit,
        DateTimeImmutable $now,
    ): float {
        $range = $this->windowRange($window, $windowSize, $now);
        $counter = self::COUNTERS[$unit] ?? self::COUNTERS[UsageLimit::UNIT_CREDITS];

        $query = Capsule::table(self::TABLE_BUCKETS)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('bucket_start', '<=', $range->to->format(self::TIMESTAMP_FORMAT));

        if ($range->from !== null) {
            $query->where('bucket_start', '>=', $range->from->format(self::TIMESTAMP_FORMAT));
        }

        return CreditAmount::round((float) $query->sum($counter));
    }

    /**
     * @param array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool, source?: string} $limit
     * @return array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool, source: string, spend: float, remaining: float}
     */
    private function limitWithSpend(array $limit, int $principalId, DateTimeImmutable $now): array
    {
        $spend = $this->bucketSum(
            OwnerScope::TYPE_PRINCIPAL,
            $principalId,
            $limit['window'],
            $limit['window_size'],
            $limit['unit'],
            $now,
        );

        return [
            'window' => $limit['window'],
            'window_size' => $limit['window_size'],
            'unit' => $limit['unit'],
            'cap' => $limit['cap'],
            'mode' => $limit['mode'],
            'enabled' => $limit['enabled'],
            // Where the row came from: the principal's own, or the
            // operator's default that stands in for them.
            'source' => $limit['source'] ?? 'principal',
            'spend' => $spend,
            // Signed on purpose: a negative remainder is how an overshoot
            // shows up, and clamping it to zero would hide the size of it.
            'remaining' => CreditAmount::round((float) $limit['cap'] - $spend),
        ];
    }

    /**
     * The contiguous slots of a series, from the first to the last.
     *
     * @return list<string>
     */
    private function slots(?DateTimeImmutable $from, DateTimeImmutable $to, bool $hourly): array
    {
        if ($from === null) {
            return [];
        }

        $slots = [];
        $cursor = $this->slotStart($from, $hourly);
        $step = $hourly ? new DateInterval('PT1H') : new DateInterval('P1D');

        while ($cursor <= $to) {
            $slots[] = $this->slotKey($cursor, $hourly);
            $cursor = $cursor->add($step);
        }

        return $slots;
    }

    private function slotStart(DateTimeImmutable $at, bool $hourly): DateTimeImmutable
    {
        return $hourly
            ? $at->setTime((int) $at->format('H'), 0)
            : $at->setTime(0, 0);
    }

    private function slotKey(DateTimeImmutable $at, bool $hourly): string
    {
        // The instant is written in UTC, so every point carries the same
        // offset: the key order is then the time order, and the string reads
        // the same on every installation.
        return $this->slotStart($at, $hourly)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::SLOT_FORMAT);
    }


    private function userLabel(int $userId): string
    {
        $row = Capsule::table('users')->where('id', $userId)->select(['email', 'name'])->first();

        if ($row === null) {
            return 'User #' . $userId;
        }

        $name = $row->name === null ? '' : trim((string) $row->name);
        if ($name !== '') {
            return $name;
        }

        return (string) $row->email;
    }

    private function groupLabel(int $groupId): string
    {
        $name = Capsule::table('groups')->where('id', $groupId)->value('name');

        return $name === null ? 'Group #' . $groupId : (string) $name;
    }
}
