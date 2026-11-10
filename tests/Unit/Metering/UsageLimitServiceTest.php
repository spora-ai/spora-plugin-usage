<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Metering\UsageDecision;
use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\Services\UsageLimitService;
use Spora\Plugins\Usage\Services\WindowCalculator;
use Spora\Plugins\Usage\Tests\Unit\Metering\Support\UsageFixtures;

it('sums the buckets of the window it is asked about', function (string $window, int $windowSize, float $expected): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedWindowSpend($principalId, new DateTimeImmutable());

    $limitId = UsageFixtures::seedLimit('principal', $principalId, $window, $windowSize, 'credits', $expected + 1);
    $service = new UsageLimitService(new WindowCalculator());
    $scope = OwnerScope::principal($principalId);
    $context = UsageFixtures::context($principalId);

    expect($service->evaluate($scope, $context))->toBeNull();

    Capsule::table('usage_limits')->where('id', $limitId)->update(['cap' => $expected]);

    $decision = $service->evaluate($scope, $context);

    expect($decision)->not->toBeNull()
        ->and($decision?->scope)->toBe($window . ':credits')
        ->and($decision?->mode)->toBe(UsageDecision::MODE_HARD);
})->with([
    'hour'         => ['hour', 1, 10.0],
    'day'          => ['day', 1, 30.0],
    'week'         => ['week', 1, 70.0],
    'month'        => ['month', 1, 150.0],
    'rolling hour' => ['rolling', 5, 30.0],
    'total'        => ['total', 1, 310.0],
]);

it('blocks a limit that has reached its cap', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'credits', 100);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->not->toBeNull()
        ->and($decision?->isHard())->toBeTrue()
        ->and($decision?->reason)->toContain('daily')
        ->and($decision?->reason)->toContain('100');
});

it('does not block a limit that is under its cap', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 99.5],
    );
    UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'credits', 100);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->toBeNull();
});

it('records a soft limit without blocking the run', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'credits', 100, 'soft');

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->not->toBeNull()
        ->and($decision?->mode)->toBe(UsageDecision::MODE_SOFT)
        ->and($decision?->isHard())->toBeFalse();
});

it('ignores a limit that is switched off', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    $limitId = UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'credits', 100);
    Capsule::table('usage_limits')->where('id', $limitId)->update(['enabled' => false]);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->toBeNull();
});

it('counts the tokens of the unit the limit names', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 5, 'total_tokens' => 400, 'output_tokens' => 40, 'llm_calls' => 2, 'tool_calls' => 3],
    );
    UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'llm_calls', 2);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->not->toBeNull()
        ->and($decision?->scope)->toBe('day:llm_calls');
});

it('compares the spend and the cap rounded to eight decimals', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    $now = new DateTimeImmutable();
    UsageFixtures::seedBucket('principal', $principalId, UsageFixtures::bucketStart($now->modify('-30 minutes')), ['credits' => 0.1]);
    UsageFixtures::seedBucket('principal', $principalId, UsageFixtures::bucketStart($now->modify('-2 hours')), ['credits' => 0.7]);
    UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'credits', 0.8);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->not->toBeNull()
        ->and($decision?->scope)->toBe('day:credits');
});

it('holds a principal that has no limits of its own to the default', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedDefaultLimit('user', 'day', 1, 'credits', 100);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->not->toBeNull()
        ->and($decision?->isHard())->toBeTrue()
        ->and($decision?->scope)->toBe('day:credits');
});

it('leaves a principal that has limits of its own to them', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );

    // The principal's own limit is generous; the default is not. The
    // principal's row is a configuration, so the default does not stand
    // in for it.
    UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'credits', 1000);
    UsageFixtures::seedDefaultLimit('user', 'day', 1, 'credits', 100);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->toBeNull();
});

it('holds a group to the default for groups', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createGroupPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedDefaultLimit('group', 'day', 1, 'credits', 100);
    UsageFixtures::seedDefaultLimit('user', 'day', 1, 'credits', 1000);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->not->toBeNull()
        ->and($decision?->reason)->toContain('100');
});

it('falls back to the general default when the type has none', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createGroupPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedDefaultLimit('all', 'day', 1, 'credits', 100);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->not->toBeNull()
        ->and($decision?->reason)->toContain('100');
});

it('holds an individual to the default for users', function (): void {
    $userId = UsageFixtures::createUser();
    UsageFixtures::seedBucket(
        'user',
        $userId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedDefaultLimit('user', 'day', 1, 'credits', 100);
    UsageFixtures::seedDefaultLimit('group', 'day', 1, 'credits', 1000);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::user($userId), UsageFixtures::context(1, triggerUserId: $userId));

    expect($decision)->not->toBeNull()
        ->and($decision?->reason)->toContain('100');
});

it('holds nobody when there is no default at all', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100_000],
    );

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->toBeNull();
});

it('ignores a default that is switched off', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedDefaultLimit('user', 'day', 1, 'credits', 100, enabled: false);

    $decision = (new UsageLimitService(new WindowCalculator()))
        ->evaluate(OwnerScope::principal($principalId), UsageFixtures::context($principalId));

    expect($decision)->toBeNull();
});
