<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Spora\Metering\LlmSubject;
use Spora\Metering\MeteringSubject;
use Spora\Metering\ToolSubject;
use Spora\Plugins\Usage\Services\ConfigurationScope;
use Spora\Plugins\Usage\Tests\Unit\Metering\Support\UsageFixtures;

/**
 * A subject of a kind the plugin does not know.
 *
 * The meter only ever builds a model or a tool subject, so this is the
 * branch that says what happens to something else: it is billed, because
 * nothing says otherwise.
 */
function unknownSubject(): MeteringSubject
{
    return new class implements MeteringSubject {
        public function kind(): string
        {
            return 'something-else';
        }
    };
}

it('calls a model on the installation when the model configuration is global', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    $agentId = createAgentWithPrincipal($userId);

    UsageFixtures::seedLlmConfiguration('The installation default', isGlobal: true);

    $scope = new ConfigurationScope();

    expect($scope->isInstallationScoped(
        UsageFixtures::context($principalId, agentId: $agentId),
        new LlmSubject('openai_compatible', 'gpt-4o'),
    ))->toBeTrue();
});

it('does not call a model on the installation when the principal has a model of their own', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    $agentId = createAgentWithPrincipal($userId);

    $own = UsageFixtures::seedLlmConfiguration('The principal own key', isGlobal: false, principalId: $principalId);
    UsageFixtures::preferLlmConfiguration($principalId, $own);

    $scope = new ConfigurationScope();

    expect($scope->isInstallationScoped(
        UsageFixtures::context($principalId, agentId: $agentId),
        new LlmSubject('openai_compatible', 'gpt-4o'),
    ))->toBeFalse();
});

it('does not call a model on the installation when the agent is gone', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    $scope = new ConfigurationScope();

    expect($scope->isInstallationScoped(
        UsageFixtures::context($principalId, agentId: 999_999),
        new LlmSubject('openai_compatible', 'gpt-4o'),
    ))->toBeFalse();
});

it('does not call a model on the installation when the agent has no configuration', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    $agentId = createAgentWithPrincipal($userId);

    $scope = new ConfigurationScope();

    expect($scope->isInstallationScoped(
        UsageFixtures::context($principalId, agentId: $agentId),
        new LlmSubject('openai_compatible', 'gpt-4o'),
    ))->toBeFalse();
});

it('calls a tool on the installation when the principal has no configuration of their own', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    $scope = new ConfigurationScope();

    expect($scope->isInstallationScoped(
        UsageFixtures::context($principalId),
        new ToolSubject('calculator', 'Spora\\Tools\\CalculatorTool', null),
    ))->toBeTrue();
});

it('does not call a tool on the installation when the principal configured it themselves', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    UsageFixtures::seedToolConfiguration($principalId, 'Spora\\Tools\\CalculatorTool');

    $scope = new ConfigurationScope();

    expect($scope->isInstallationScoped(
        UsageFixtures::context($principalId),
        new ToolSubject('calculator', 'Spora\\Tools\\CalculatorTool', null),
    ))->toBeFalse();
});

it('calls a subject it does not recognise on the installation', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    $scope = new ConfigurationScope();

    expect($scope->isInstallationScoped(
        UsageFixtures::context($principalId),
        unknownSubject(),
    ))->toBeTrue();
});

it('does not bill a call whose scope cannot be resolved, and says so', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    // The table the scope is read from is gone — an install whose core is
    // older than the plugin. The event must still be recorded, and the
    // principal must not be charged for it.
    Capsule::schema()->drop('tool_user_settings');

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once();

    $scope = new ConfigurationScope($logger);

    expect($scope->isInstallationScoped(
        UsageFixtures::context($principalId),
        new ToolSubject('calculator', 'Spora\\Tools\\CalculatorTool', null),
    ))->toBeFalse();
});
