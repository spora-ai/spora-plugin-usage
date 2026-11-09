<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Plugins\Usage\Services\CreditService;
use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\Tests\Unit\Metering\Support\UsageFixtures;

it('reports no balance for an owner without a pool', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    expect((new CreditService())->balance(OwnerScope::principal($principalId)))->toBeNull();
});

it('reports the balance of a pool', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedPool('principal', $principalId, 12.5);

    expect((new CreditService())->balance(OwnerScope::principal($principalId)))->toBe(12.5);
});

it('takes the credits off the pool', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedPool('principal', $principalId, 12.5);

    (new CreditService())->debit(OwnerScope::principal($principalId), 2.25);

    expect((new CreditService())->balance(OwnerScope::principal($principalId)))->toBe(10.25);
});

it('leaves an owner without a pool without one', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    (new CreditService())->debit(OwnerScope::principal($principalId), 2.25);

    expect(Capsule::table('usage_credits')->count())->toBe(0)
        ->and((new CreditService())->balance(OwnerScope::principal($principalId)))->toBeNull();
});
