<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Drivers\ValueObjects\Usage;
use Spora\Metering\LlmSubject;
use Spora\Metering\LlmUsage;
use Spora\Metering\MeteringContext;
use Spora\Metering\ToolSubject;
use Spora\Metering\ToolUsage;
use Spora\Plugins\Usage\Services\OwnerScope;
use Spora\Plugins\Usage\Services\UsageLedger;

/**
 * The row of one metered event, as the database holds it.
 */
function ledgerRow(): ?object
{
    return Capsule::table('usage_ledger')->first();
}

/**
 * @return list<object>
 */
function bucketRows(): array
{
    return Capsule::table('usage_buckets')->get()->all();
}

function poolBalance(string $ownerType, int $ownerId): float
{
    $row = Capsule::table('usage_credits')
        ->where('owner_type', $ownerType)
        ->where('owner_id', $ownerId)
        ->first(['balance']);

    return $row === null ? 0.0 : (float) $row->balance;
}

it('writes one ledger row and one bucket row per scope', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    $ledger = new UsageLedger();
    $ledger->record(
        new MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: $principalId,
            triggerUserId: $userId,
            isSystemRun: false,
        ),
        new LlmSubject('openai_compatible', 'gpt-4o'),
        new LlmUsage(
            usage: new Usage(inputTokens: 1000, outputTokens: 200, cachedTokens: 100),
            provider: 'openai_compatible',
            model: 'gpt-4o',
        ),
        1.5,
        true,
        [OwnerScope::principal($principalId), OwnerScope::user($userId)],
    );

    $row = ledgerRow();

    expect($row)->not->toBeNull()
        ->and((int) $row->principal_id)->toBe($principalId)
        ->and((string) $row->kind)->toBe('llm')
        ->and((string) $row->provider)->toBe('openai_compatible')
        ->and((string) $row->model)->toBe('gpt-4o')
        ->and((int) $row->input_tokens)->toBe(1000)
        ->and((int) $row->output_tokens)->toBe(200)
        ->and((int) $row->cached_tokens)->toBe(100)
        ->and((float) $row->credits)->toBe(1.5)
        ->and((bool) $row->priced)->toBeTrue()
        ->and((string) $row->source)->toBe('live');

    $buckets = bucketRows();

    expect($buckets)->toHaveCount(2)
        ->and((string) $buckets[0]->owner_type)->toBe('principal')
        ->and((int) $buckets[0]->owner_id)->toBe($principalId)
        ->and((int) $buckets[0]->total_tokens)->toBe(1200)
        ->and((int) $buckets[0]->output_tokens)->toBe(200)
        ->and((int) $buckets[0]->llm_calls)->toBe(1)
        ->and((int) $buckets[0]->tool_calls)->toBe(0)
        ->and((float) $buckets[0]->credits)->toBe(1.5)
        ->and((string) $buckets[1]->owner_type)->toBe('user')
        ->and((int) $buckets[1]->owner_id)->toBe($userId)
        ->and((int) $buckets[1]->llm_calls)->toBe(1);
});

it('counts a tool call as a tool call and not as a model call', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    (new UsageLedger())->record(
        new MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: $principalId,
            triggerUserId: null,
            isSystemRun: true,
        ),
        new ToolSubject('media', 'Spora\\Tools\\MediaTool', 'resize'),
        new ToolUsage(
            toolName: 'media',
            toolClass: 'Spora\\Tools\\MediaTool',
            operation: 'resize',
            succeeded: false,
        ),
        2.0,
        true,
        [OwnerScope::principal($principalId)],
    );

    $row = ledgerRow();
    $bucket = bucketRows()[0];

    expect((string) $row->kind)->toBe('tool')
        ->and((string) $row->tool_name)->toBe('media')
        ->and((string) $row->tool_class)->toBe('Spora\\Tools\\MediaTool')
        ->and((string) $row->operation)->toBe('resize')
        ->and($row->provider)->toBeNull()
        ->and((int) $row->input_tokens)->toBe(0)
        ->and($row->user_id)->toBeNull()
        ->and((int) $bucket->tool_calls)->toBe(1)
        ->and((int) $bucket->llm_calls)->toBe(0)
        ->and((int) $bucket->total_tokens)->toBe(0);
});

it('snapshots the type of the principal the event is charged to', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createGroupPrincipal($userId);

    (new UsageLedger())->record(
        new MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: $principalId,
            triggerUserId: null,
            isSystemRun: true,
        ),
        new LlmSubject('openai_compatible', 'gpt-4o'),
        new LlmUsage(
            usage: new Usage(inputTokens: 10, outputTokens: 0),
            provider: 'openai_compatible',
            model: 'gpt-4o',
        ),
        0.0,
        false,
        [OwnerScope::principal($principalId)],
    );

    $row = ledgerRow();

    expect((int) $row->principal_id)->toBe($principalId)
        ->and((string) $row->principal_type)->toBe('group');
});

it('snapshots a user principal as a user', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    (new UsageLedger())->record(
        new MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: $principalId,
            triggerUserId: $userId,
            isSystemRun: false,
        ),
        new LlmSubject('openai_compatible', 'gpt-4o'),
        new LlmUsage(
            usage: new Usage(inputTokens: 10, outputTokens: 0),
            provider: 'openai_compatible',
            model: 'gpt-4o',
        ),
        0.0,
        false,
        [OwnerScope::principal($principalId)],
    );

    $row = ledgerRow();

    expect((string) $row->principal_type)->toBe('user')
        ->and((int) $row->user_id)->toBe($userId);
});

it('keeps the owner of an event whose principal has gone', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    (new UsageLedger())->record(
        new MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: $principalId,
            triggerUserId: null,
            isSystemRun: true,
        ),
        new LlmSubject('openai_compatible', 'gpt-4o'),
        new LlmUsage(
            usage: new Usage(inputTokens: 10, outputTokens: 0),
            provider: 'openai_compatible',
            model: 'gpt-4o',
        ),
        0.0,
        false,
        [OwnerScope::principal($principalId)],
    );

    Capsule::table('principals')->where('id', $principalId)->delete();

    $row = ledgerRow();

    expect($row)->not->toBeNull()
        ->and((int) $row->principal_id)->toBe($principalId)
        ->and((string) $row->principal_type)->toBe('user');
});

it('debits the credit pool of every scope that has one', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    Capsule::table('usage_credits')->insert([
        'owner_type' => 'principal',
        'owner_id'   => $principalId,
        'balance'    => 100,
    ]);
    Capsule::table('usage_credits')->insert([
        'owner_type' => 'user',
        'owner_id'   => $userId,
        'balance'    => 10,
    ]);

    (new UsageLedger())->record(
        new MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: $principalId,
            triggerUserId: $userId,
            isSystemRun: false,
        ),
        new LlmSubject('openai_compatible', 'gpt-4o'),
        new LlmUsage(
            usage: new Usage(inputTokens: 100, outputTokens: 0),
            provider: 'openai_compatible',
            model: 'gpt-4o',
        ),
        1.5,
        true,
        [OwnerScope::principal($principalId), OwnerScope::user($userId)],
    );

    expect(poolBalance('principal', $principalId))->toBe(98.5)
        ->and(poolBalance('user', $userId))->toBe(8.5);
});

it('leaves a scope without a credit pool without one', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    (new UsageLedger())->record(
        new MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: $principalId,
            triggerUserId: null,
            isSystemRun: true,
        ),
        new LlmSubject('openai_compatible', 'gpt-4o'),
        new LlmUsage(
            usage: new Usage(inputTokens: 100, outputTokens: 0),
            provider: 'openai_compatible',
            model: 'gpt-4o',
        ),
        1.5,
        true,
        [OwnerScope::principal($principalId)],
    );

    expect(Capsule::table('usage_credits')->count())->toBe(0);
});

it('adds the second event of a slot to the bucket of the first', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    $ledger = new UsageLedger();
    $context = new MeteringContext(
        taskId: 1,
        agentId: 1,
        principalId: $principalId,
        triggerUserId: null,
        isSystemRun: true,
    );
    $usage = new LlmUsage(
        usage: new Usage(inputTokens: 100, outputTokens: 10),
        provider: 'openai_compatible',
        model: 'gpt-4o',
    );

    $ledger->record($context, new LlmSubject('openai_compatible', 'gpt-4o'), $usage, 1.0, true, [OwnerScope::principal($principalId)]);
    $ledger->record($context, new LlmSubject('openai_compatible', 'gpt-4o'), $usage, 2.0, true, [OwnerScope::principal($principalId)]);

    $buckets = bucketRows();

    expect($buckets)->toHaveCount(1)
        ->and((float) $buckets[0]->credits)->toBe(3.0)
        ->and((int) $buckets[0]->llm_calls)->toBe(2)
        ->and((int) $buckets[0]->total_tokens)->toBe(220)
        ->and((int) $buckets[0]->output_tokens)->toBe(20);
});

it('writes the bucket without reading it first', function (): void {
    // A read-then-insert loses a race. `lockForUpdate()` locks only the
    // rows that exist, so two tasks writing the first event of a slot both
    // read nothing and both insert — and the loser of that race takes the
    // whole transaction down with it, ledger row and debit included. The
    // meter catches the failure and only logs it, so the usage silently
    // vanishes.
    //
    // The invariant is that the bucket row is never *read* on the way to
    // being written. A true concurrency test needs two connections, and the
    // suite runs on one in-memory SQLite; the query log is what pins the
    // shape.
    $queries = [];

    $connection = Capsule::connection();
    $events = $connection->getEventDispatcher();
    if ($events === null) {
        $events = new Illuminate\Events\Dispatcher();
        $connection->setEventDispatcher($events);
    }
    $events->listen(
        Illuminate\Database\Events\QueryExecuted::class,
        static function ($event) use (&$queries): void {
            $queries[] = strtolower($event->sql);
        },
    );

    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    (new UsageLedger())->record(
        new MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: $principalId,
            triggerUserId: $userId,
            isSystemRun: false,
        ),
        new LlmSubject('openai_compatible', 'gpt-4o'),
        new LlmUsage(
            usage: new Usage(inputTokens: 1000, outputTokens: 200),
            provider: 'openai_compatible',
            model: 'gpt-4o',
        ),
        1.5,
        true,
        [OwnerScope::principal($principalId)],
    );

    $bucketReads = array_values(array_filter(
        $queries,
        static fn(string $sql): bool => str_contains($sql, 'usage_buckets') && str_starts_with($sql, 'select'),
    ));

    // The queries were captured at all — otherwise the assertion above
    // would pass vacuously.
    expect($queries)->not->toBeEmpty()
        ->and($bucketReads)->toBe([]);
});

it('adds to an existing bucket rather than replacing it', function (): void {
    $userId = bootAuth(bootAuthLayer());
    $principalId = createUserPrincipal($userId);

    $ledger = new UsageLedger();
    $context = new MeteringContext(
        taskId: 1,
        agentId: 1,
        principalId: $principalId,
        triggerUserId: $userId,
        isSystemRun: false,
    );
    $subject = new LlmSubject('openai_compatible', 'gpt-4o');
    $usage = new LlmUsage(
        usage: new Usage(inputTokens: 1000, outputTokens: 200),
        provider: 'openai_compatible',
        model: 'gpt-4o',
    );
    $scopes = [OwnerScope::principal($principalId)];

    $ledger->record($context, $subject, $usage, 1.5, true, $scopes);
    $ledger->record($context, $subject, $usage, 2.5, true, $scopes);

    $bucket = Capsule::table('usage_buckets')->first();

    expect(bucketRows())->toHaveCount(1)
        ->and((float) $bucket->credits)->toBe(4.0)
        // `totalTokens()` is input + output + the two cache counters, so
        // 1200 a call and 2400 for the two.
        ->and((int) $bucket->total_tokens)->toBe(2400)
        ->and((int) $bucket->llm_calls)->toBe(2);
});
