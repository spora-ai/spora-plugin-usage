<?php

declare(strict_types=1);

use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\Services\UsageAttribution;
use Spora\Plugins\Usage\Tests\Unit\Metering\Support\UsageFixtures;

it('charges the pool of the group that owns the agent', function (): void {
    $userId = UsageFixtures::createUser();
    $groupPrincipalId = createGroupPrincipal($userId);

    $scopes = (new UsageAttribution())->scopesFor(UsageFixtures::context($groupPrincipalId));

    expect($scopes)->toHaveCount(1)
        ->and($scopes[0])->toEqual(OwnerScope::principal($groupPrincipalId));
});

it('charges the individual in addition to the owner of an interactive run', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    $scopes = (new UsageAttribution())->scopesFor(UsageFixtures::context($principalId, $userId));

    expect($scopes)->toHaveCount(2)
        ->and($scopes[0])->toEqual(OwnerScope::principal($principalId))
        ->and($scopes[1])->toEqual(OwnerScope::user($userId));
});

it('charges the principal alone for a platform-started run', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    $scopes = (new UsageAttribution())->scopesFor(
        UsageFixtures::context($principalId, $userId, true),
    );

    expect($scopes)->toHaveCount(1)
        ->and($scopes[0])->toEqual(OwnerScope::principal($principalId));
});

it('charges the principal alone when nobody triggered the run', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    $scopes = (new UsageAttribution())->scopesFor(UsageFixtures::context($principalId));

    expect($scopes)->toHaveCount(1)
        ->and($scopes[0])->toEqual(OwnerScope::principal($principalId));
});
