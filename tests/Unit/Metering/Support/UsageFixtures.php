<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Tests\Unit\Metering\Support;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Metering\MeteringContext;
use Spora\Plugins\Usage\Services\WindowCalculator;

/**
 * The rows a metering test needs: buckets, limits and credit pools.
 */
final class UsageFixtures
{
    /**
     * A user row, for the principals that need one.
     *
     * The metering domain is anchored on principals, not on accounts, so the
     * row is written directly: booting the auth library would drag a
     * deprecated vendor package into a test that never authenticates anyone.
     */
    public static function createUser(string $email = 'metering@example.com'): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'email'      => $email,
            'password'   => 'not-a-real-hash',
            'registered' => time(),
        ]);
    }

    public static function context(
        int $principalId,
        ?int $triggerUserId = null,
        bool $isSystemRun = false,
        int $agentId = 1,
    ): MeteringContext {
        return new MeteringContext(
            taskId: 1,
            agentId: $agentId,
            principalId: $principalId,
            triggerUserId: $triggerUserId,
            isSystemRun: $isSystemRun,
        );
    }

    /**
     * A model configuration, and the id of its row.
     *
     * A global one is the installation's; a principal-owned one is that
     * principal's own key. The settings are written unencrypted because
     * the meter reads the row's ownership, not its contents.
     */
    public static function seedLlmConfiguration(
        string $name,
        bool $isGlobal,
        ?int $principalId = null,
    ): int {
        return (int) Capsule::table('llm_driver_configurations')->insertGetId([
            'principal_id' => $principalId,
            'name'         => $name,
            'driver_class' => 'Spora\\Drivers\\OpenAICompatibleDriver',
            'settings'     => json_encode(['model' => 'gpt-4o', 'api_key' => 'a-test-key']),
            'is_default'   => true,
            'is_global'    => $isGlobal,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The principal's own choice of model configuration.
     *
     * It is the second tier of the resolution: an agent pinned to nothing
     * uses the principal's preference before the global default.
     */
    public static function preferLlmConfiguration(int $principalId, int $configId): void
    {
        Capsule::table('principal_preferences')->insert([
            'principal_id'            => $principalId,
            'preferred_llm_config_id' => $configId,
            'created_at'              => date('Y-m-d H:i:s'),
            'updated_at'              => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A tool configuration of the principal's own.
     */
    public static function seedToolConfiguration(int $principalId, string $toolClass): void
    {
        Capsule::table('tool_user_settings')->insert([
            'principal_id' => $principalId,
            'tool_class'   => $toolClass,
            'settings'     => json_encode(['api_key' => 'a-test-key']),
            'is_default'   => true,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A default limit set for one scope.
     */
    public static function seedDefaultLimit(
        string $scope,
        string $window,
        int $windowSize,
        string $unit,
        float|int $cap,
        bool $enabled = true,
    ): int {
        return (int) Capsule::table('usage_default_limits')->insertGetId([
            'scope'       => $scope,
            'window'      => $window,
            'window_size' => $windowSize,
            'unit'        => $unit,
            'cap'         => $cap,
            'mode'        => 'hard',
            'enabled'     => $enabled,
        ]);
    }

    /**
     * The start of the slot a moment falls into.
     */
    public static function bucketStart(DateTimeImmutable $at): DateTimeImmutable
    {
        return (new WindowCalculator())->bucketStart($at);
    }

    /**
     * @param array<string, float|int> $counters
     */
    public static function seedBucket(
        string $ownerType,
        int $ownerId,
        DateTimeImmutable $bucketStart,
        array $counters = [],
    ): void {
        Capsule::table('usage_buckets')->insert([
            'owner_type'    => $ownerType,
            'owner_id'      => $ownerId,
            'bucket_start'  => $bucketStart->format('Y-m-d H:i:s'),
            'credits'       => $counters['credits'] ?? 0,
            'total_tokens'  => $counters['total_tokens'] ?? 0,
            'output_tokens' => $counters['output_tokens'] ?? 0,
            'llm_calls'     => $counters['llm_calls'] ?? 0,
            'tool_calls'    => $counters['tool_calls'] ?? 0,
        ]);
    }

    /**
     * One bucket per age, so that every window sees a different set of them.
     *
     * The buckets sit clear of every window edge by more than the 15-minute
     * granularity, so the slot a bucket falls into cannot move it from
     * inside a window to outside it while the test runs.
     */
    public static function seedWindowSpend(int $ownerId, DateTimeImmutable $now): void
    {
        self::seedBucket('principal', $ownerId, self::bucketStart($now->modify('-30 minutes')), ['credits' => 10]);
        self::seedBucket('principal', $ownerId, self::bucketStart($now->modify('-2 hours')), ['credits' => 20]);
        self::seedBucket('principal', $ownerId, self::bucketStart($now->modify('-3 days')), ['credits' => 40]);
        self::seedBucket('principal', $ownerId, self::bucketStart($now->modify('-10 days')), ['credits' => 80]);
        self::seedBucket('principal', $ownerId, self::bucketStart($now->modify('-40 days')), ['credits' => 160]);
    }

    public static function seedLimit(
        string $ownerType,
        int $ownerId,
        string $window,
        int $windowSize,
        string $unit,
        float $cap,
        string $mode = 'hard',
    ): int {
        return (int) Capsule::table('usage_limits')->insertGetId([
            'owner_type'  => $ownerType,
            'owner_id'    => $ownerId,
            'window'      => $window,
            'window_size' => $windowSize,
            'unit'        => $unit,
            'cap'         => $cap,
            'mode'        => $mode,
            'enabled'     => true,
        ]);
    }

    public static function seedPool(string $ownerType, int $ownerId, float $balance): void
    {
        Capsule::table('usage_credits')->insert([
            'owner_type' => $ownerType,
            'owner_id'   => $ownerId,
            'balance'    => $balance,
        ]);
    }

    public static function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    public static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
