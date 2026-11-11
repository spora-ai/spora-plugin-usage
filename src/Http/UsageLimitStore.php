<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Principal;
use Spora\Plugins\Usage\Models\UsageDefaultLimit;
use Spora\Plugins\Usage\Models\UsageLimit;
use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\ValueObjects\CreditAmount;

/**
 * The limits of an owner scope, and the default sets that stand in for
 * them.
 *
 * A limit row is the ceiling of one window for one owner. A default row is
 * the same thing for every owner that has none of their own: the ceiling a
 * principal falls back to, so that a principal nobody configured is not
 * left unlimited by accident.
 *
 * The scope says who a default set covers: everybody, or one principal
 * type. The type's set replaces the general one for that type, so an
 * operator who wants users held to a tighter rule can say so without
 * losing the rule that holds everybody else.
 *
 * This class is the only place that knows these tables' column names, so a
 * schema change lands in one file instead of in every controller.
 */
final class UsageLimitStore
{
    private const TABLE_LIMITS = 'usage_limits';

    private const TABLE_DEFAULT_LIMITS = 'usage_default_limits';

    /**
     * The limits of one owner scope.
     *
     * @return list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}>
     */
    public function limits(string $ownerType, int $ownerId): array
    {
        $rows = Capsule::table(self::TABLE_LIMITS)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('window')
            ->orderBy('window_size')
            ->orderBy('unit')
            ->get();

        $limits = [];
        foreach ($rows as $row) {
            $limits[] = [
                'window' => (string) $row->window,
                'window_size' => (int) $row->window_size,
                'unit' => (string) $row->unit,
                'cap' => $this->numericCap((string) $row->unit, $row->cap),
                'mode' => (string) $row->mode,
                'enabled' => (bool) $row->enabled,
            ];
        }

        return $limits;
    }

    /**
     * Replace the limits of one owner scope with the given set.
     *
     * The set is replaced whole: a limit that is absent from the new set is
     * deleted, so the payload is the truth about which limits exist.
     *
     * @param list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}> $limits
     */
    public function replaceLimits(string $ownerType, int $ownerId, array $limits): void
    {
        Capsule::connection()->transaction(function () use ($ownerType, $ownerId, $limits): void {
            Capsule::table(self::TABLE_LIMITS)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->delete();

            foreach ($limits as $limit) {
                Capsule::table(self::TABLE_LIMITS)->insert([
                    'owner_type'  => $ownerType,
                    'owner_id'    => $ownerId,
                    'window'      => $limit['window'],
                    'window_size' => $limit['window_size'],
                    'unit'        => $limit['unit'],
                    'cap'         => $limit['cap'],
                    'mode'        => $limit['mode'],
                    'enabled'     => $limit['enabled'],
                ]);
            }
        });
    }

    /**
     * The limits that govern a principal.
     *
     * A principal with rows of their own is governed by them. A principal
     * with none is governed by the default for their type — the set for
     * users, the set for groups, or the general set when their type has
     * none of its own.
     *
     * The rows say where they came from, so a summary that shows a
     * principal's limits does not claim they are the principal's own when
     * they are the operator's default.
     *
     * @return list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool, source: string}>
     */
    public function effectiveLimits(int $principalId, string $principalType): array
    {
        $own = $this->limits(OwnerScope::TYPE_PRINCIPAL, $principalId);

        if ($own !== []) {
            return array_map(
                static fn(array $limit): array => $limit + ['source' => 'principal'],
                $own,
            );
        }

        $scope = $this->defaultScopeFor($principalType);
        $defaults = $this->enabledDefaultLimitsOf($scope);

        if ($defaults === [] && $scope !== UsageDefaultLimit::SCOPE_ALL) {
            $scope = UsageDefaultLimit::SCOPE_ALL;
            $defaults = $this->enabledDefaultLimitsOf($scope);
        }

        return array_map(
            static fn(array $limit): array => $limit + ['source' => 'default'],
            $defaults,
        );
    }

    /**
     * The default limit set of one scope, with the rows that are switched
     * on.
     *
     * The set that governs is the one with something switched on: a set
     * with nothing switched on is no set, and the general default stands
     * in for it. The gate decides the same way, so the summary and the
     * gate never name different sets.
     *
     * @return list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}>
     */
    private function enabledDefaultLimitsOf(string $scope): array
    {
        return array_values(array_filter(
            $this->defaultLimitsOf($scope),
            static fn(array $limit): bool => $limit['enabled'],
        ));
    }

    /**
     * The scope of the default that governs a principal.
     *
     * A user principal is governed by the set for users and a group
     * principal by the set for groups. A principal whose type cannot be
     * placed is read as a user, which is what the gate assumes for a
     * principal it cannot type either.
     */
    private function defaultScopeFor(string $principalType): string
    {
        return $principalType === Principal::TYPE_GROUP
            ? UsageDefaultLimit::SCOPE_GROUP
            : UsageDefaultLimit::SCOPE_USER;
    }

    /**
     * The default limit sets, one per scope.
     *
     * @return array{all: list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}>, user: list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}>, group: list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}>}
     */
    public function defaultLimits(): array
    {
        $sets = [];

        foreach (UsageDefaultLimit::SCOPES as $scope) {
            $sets[$scope] = $this->defaultLimitsOf($scope);
        }

        return $sets;
    }

    /**
     * The default limit set of one scope.
     *
     * @return list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}>
     */
    public function defaultLimitsOf(string $scope): array
    {
        $rows = Capsule::table(self::TABLE_DEFAULT_LIMITS)
            ->where('scope', $scope)
            ->orderBy('window')
            ->orderBy('window_size')
            ->orderBy('unit')
            ->get();

        $limits = [];
        foreach ($rows as $row) {
            $limits[] = [
                'window' => (string) $row->window,
                'window_size' => (int) $row->window_size,
                'unit' => (string) $row->unit,
                'cap' => $this->numericCap((string) $row->unit, $row->cap),
                'mode' => (string) $row->mode,
                'enabled' => (bool) $row->enabled,
            ];
        }

        return $limits;
    }

    /**
     * Replace the default limit sets that are in the payload.
     *
     * A scope that is in the payload is replaced whole: a limit that is
     * absent from it is deleted. A scope that is **not** in the payload is
     * left as it is — a payload that names one scope must not silently
     * clear the other two.
     *
     * @param array<string, list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}>> $sets
     */
    public function replaceDefaultLimits(array $sets): void
    {
        Capsule::connection()->transaction(function () use ($sets): void {
            foreach ($sets as $scope => $limits) {
                if (!in_array($scope, UsageDefaultLimit::SCOPES, true)) {
                    continue;
                }

                Capsule::table(self::TABLE_DEFAULT_LIMITS)
                    ->where('scope', $scope)
                    ->delete();

                foreach ($limits as $limit) {
                    Capsule::table(self::TABLE_DEFAULT_LIMITS)->insert([
                        'scope'       => $scope,
                        'window'      => $limit['window'],
                        'window_size' => $limit['window_size'],
                        'unit'        => $limit['unit'],
                        'cap'         => $limit['cap'],
                        'mode'        => $limit['mode'],
                        'enabled'     => $limit['enabled'],
                    ]);
                }
            }
        });
    }

    /**
     * A count cap stays an integer; a credit cap is a decimal.
     */
    private function numericCap(string $unit, mixed $cap): float|int
    {
        return $unit === UsageLimit::UNIT_CREDITS
            ? CreditAmount::round((float) $cap)
            : (int) $cap;
    }
}
