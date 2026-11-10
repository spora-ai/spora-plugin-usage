<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Drivers\ValueObjects\Usage;
use Spora\Metering\LlmSubject;
use Spora\Metering\LlmUsage;
use Spora\Metering\ToolUsage;
use Spora\Metering\UsageDecision;
use Spora\Plugins\Usage\Services\ConfigurationScope;
use Spora\Plugins\Usage\Services\CreditService;
use Spora\Plugins\Usage\Services\PriceBook;
use Spora\Plugins\Usage\Services\UsageAttribution;
use Spora\Plugins\Usage\Services\UsageLedger;
use Spora\Plugins\Usage\Services\UsageLimitService;
use Spora\Plugins\Usage\Services\WindowCalculator;
use Spora\Plugins\Usage\Tests\Unit\Metering\Support\UsageFixtures;
use Spora\Plugins\Usage\UsageMeter;

function usageMeter(): UsageMeter
{
    return new UsageMeter(
        new UsageAttribution(),
        new UsageLimitService(new WindowCalculator()),
        new CreditService(),
        new PriceBook(),
        new UsageLedger(),
        new ConfigurationScope(),
    );
}

it('blocks a run whose credit pool is exhausted', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedPool('principal', $principalId, 0.0);

    $decision = usageMeter()->evaluate(
        UsageFixtures::context($principalId),
        new LlmSubject('openai_compatible', 'gpt-4o-mini'),
    );

    expect($decision)->not->toBeNull()
        ->and($decision?->scope)->toBe('credits:balance')
        ->and($decision?->mode)->toBe(UsageDecision::MODE_HARD)
        ->and($decision?->isHard())->toBeTrue();
});

it('does not block an owner that has no credit pool at all', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    $decision = usageMeter()->evaluate(
        UsageFixtures::context($principalId),
        new LlmSubject('openai_compatible', 'gpt-4o-mini'),
    );

    expect($decision)->toBeNull();
});

it('blocks an owner whose pool is overdrawn', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedPool('principal', $principalId, -0.5);

    $decision = usageMeter()->evaluate(
        UsageFixtures::context($principalId),
        new LlmSubject('openai_compatible', 'gpt-4o-mini'),
    );

    expect($decision)->not->toBeNull()
        ->and($decision?->scope)->toBe('credits:balance');
});

it('prefers a hard breach over a soft one', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'credits', 100, 'soft');
    UsageFixtures::seedPool('user', $userId, 0.0);

    $decision = usageMeter()->evaluate(
        UsageFixtures::context($principalId, $userId),
        new LlmSubject('openai_compatible', 'gpt-4o-mini'),
    );

    expect($decision)->not->toBeNull()
        ->and($decision?->mode)->toBe(UsageDecision::MODE_HARD)
        ->and($decision?->scope)->toBe('credits:balance');
});

it('keeps the first of two breaches of the same weight', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedBucket(
        'principal',
        $principalId,
        UsageFixtures::bucketStart(new DateTimeImmutable()),
        ['credits' => 100],
    );
    UsageFixtures::seedLimit('principal', $principalId, 'day', 1, 'credits', 100);
    UsageFixtures::seedPool('user', $userId, 0.0);

    $decision = usageMeter()->evaluate(
        UsageFixtures::context($principalId, $userId),
        new LlmSubject('openai_compatible', 'gpt-4o-mini'),
    );

    expect($decision)->not->toBeNull()
        ->and($decision?->scope)->toBe('day:credits');
});

it('lets a run through when the database cannot be read', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    UsageFixtures::seedPool('principal', $principalId, 0.0);

    Capsule::schema()->drop('usage_credits');

    $decision = usageMeter()->evaluate(
        UsageFixtures::context($principalId),
        new LlmSubject('openai_compatible', 'gpt-4o-mini'),
    );

    expect($decision)->toBeNull();
});

it('records the usage of a provider request', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    $agentId = createAgentWithPrincipal($userId);
    UsageFixtures::seedLlmConfiguration('The installation default', isGlobal: true);
    Capsule::table('usage_prices')->insert([
        'kind'         => 'model',
        'provider'     => 'openai_compatible',
        'key'          => 'gpt-4o-mini',
        'operation'    => '',
        'input_price'  => 0.001,
        'output_price' => 0.002,
        'cached_price' => 0.0005,
        'call_price'   => 0,
    ]);

    usageMeter()->recordLlmUsage(
        UsageFixtures::context($principalId, agentId: $agentId),
        new LlmUsage(
            new Usage(inputTokens: 1000, outputTokens: 500),
            'openai_compatible',
            'gpt-4o-mini',
        ),
    );

    expect(UsageFixtures::floatValue(Capsule::table('usage_ledger')->value('credits')))->toBe(2.0)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('input_tokens')))->toBe(1000)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('output_tokens')))->toBe(500)
        ->and(Capsule::table('usage_buckets')->count())->toBe(1);
});

it('records an unpriced provider request as free', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    usageMeter()->recordLlmUsage(
        UsageFixtures::context($principalId),
        new LlmUsage(
            new Usage(inputTokens: 1000, outputTokens: 500),
            'openai_compatible',
            'never-priced',
        ),
    );

    expect(UsageFixtures::floatValue(Capsule::table('usage_ledger')->value('credits')))->toBe(0.0)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('priced')))->toBe(0);
});

it('records the price of a tool call', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    Capsule::table('usage_prices')->insert([
        'kind'         => 'tool',
        'provider'     => '',
        'key'          => 'Spora\\Tools\\CalculatorTool',
        'operation'    => '',
        'input_price'  => 0,
        'output_price' => 0,
        'cached_price' => 0,
        'call_price'   => 0.5,
    ]);

    usageMeter()->recordToolUsage(
        UsageFixtures::context($principalId),
        new ToolUsage('calculator', 'Spora\\Tools\\CalculatorTool', null, true),
    );

    expect(UsageFixtures::floatValue(Capsule::table('usage_ledger')->value('credits')))->toBe(0.5)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('priced')))->toBe(1)
        ->and(Capsule::table('usage_ledger')->value('kind'))->toBe('tool')
        ->and(Capsule::table('usage_buckets')->value('tool_calls'))->toBe(1);
});

it('does not bill a provider request the principal ran with their own key', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);
    $agentId = createAgentWithPrincipal($userId);

    $own = UsageFixtures::seedLlmConfiguration('The principal own key', isGlobal: false, principalId: $principalId);
    UsageFixtures::preferLlmConfiguration($principalId, $own);
    UsageFixtures::seedPool('principal', $principalId, 10.0);

    Capsule::table('usage_prices')->insert([
        'kind'         => 'model',
        'provider'     => 'openai_compatible',
        'key'          => 'gpt-4o-mini',
        'operation'    => '',
        'input_price'  => 0.001,
        'output_price' => 0.002,
        'cached_price' => 0.0005,
        'call_price'   => 0,
    ]);

    usageMeter()->recordLlmUsage(
        UsageFixtures::context($principalId, agentId: $agentId),
        new LlmUsage(
            new Usage(inputTokens: 1000, outputTokens: 500),
            'openai_compatible',
            'gpt-4o-mini',
        ),
    );

    expect(UsageFixtures::floatValue(Capsule::table('usage_ledger')->value('credits')))->toBe(0.0)
        // The price book has a row for it — the principal paid for it
        // themselves.
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('priced')))->toBe(1)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('billed')))->toBe(0)
        // The tokens are a fact about the run, whatever the price.
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('input_tokens')))->toBe(1000)
        // A token limit counts the call; a credit limit does not.
        ->and(UsageFixtures::intValue(Capsule::table('usage_buckets')->value('total_tokens')))->toBe(1500)
        ->and(UsageFixtures::floatValue(Capsule::table('usage_buckets')->value('credits')))->toBe(0.0)
        // The pool is untouched.
        ->and(UsageFixtures::floatValue(Capsule::table('usage_credits')->value('balance')))->toBe(10.0);
});

it('does not bill a tool call the principal configured for themselves', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    UsageFixtures::seedToolConfiguration($principalId, 'Spora\\Tools\\CalculatorTool');
    UsageFixtures::seedPool('principal', $principalId, 10.0);

    Capsule::table('usage_prices')->insert([
        'kind'         => 'tool',
        'provider'     => '',
        'key'          => 'Spora\\Tools\\CalculatorTool',
        'operation'    => '',
        'input_price'  => 0,
        'output_price' => 0,
        'cached_price' => 0,
        'call_price'   => 0.5,
    ]);

    usageMeter()->recordToolUsage(
        UsageFixtures::context($principalId),
        new ToolUsage('calculator', 'Spora\\Tools\\CalculatorTool', null, true),
    );

    expect(UsageFixtures::floatValue(Capsule::table('usage_ledger')->value('credits')))->toBe(0.0)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('priced')))->toBe(1)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('billed')))->toBe(0)
        // The call still counts as a call.
        ->and(UsageFixtures::intValue(Capsule::table('usage_buckets')->value('tool_calls')))->toBe(1)
        ->and(UsageFixtures::floatValue(Capsule::table('usage_credits')->value('balance')))->toBe(10.0);
});

it('records but does not bill a call whose agent is gone', function (): void {
    $userId = UsageFixtures::createUser();
    $principalId = createUserPrincipal($userId);

    Capsule::table('usage_prices')->insert([
        'kind'         => 'model',
        'provider'     => 'openai_compatible',
        'key'          => 'gpt-4o-mini',
        'operation'    => '',
        'input_price'  => 0.001,
        'output_price' => 0.002,
        'cached_price' => 0.0005,
        'call_price'   => 0,
    ]);

    usageMeter()->recordLlmUsage(
        UsageFixtures::context($principalId, agentId: 999_999),
        new LlmUsage(
            new Usage(inputTokens: 1000, outputTokens: 500),
            'openai_compatible',
            'gpt-4o-mini',
        ),
    );

    // Who paid cannot be told, so the principal is not charged for it.
    expect(UsageFixtures::floatValue(Capsule::table('usage_ledger')->value('credits')))->toBe(0.0)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('priced')))->toBe(1)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('billed')))->toBe(0)
        ->and(UsageFixtures::intValue(Capsule::table('usage_ledger')->value('input_tokens')))->toBe(1000);
});
