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
use Spora\Plugins\Usage\ValueObjects\CreditAmount;

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

/**
 * The bucket write, on its own.
 *
 * `UsageLedger::addToBucket()` is private because it belongs to the
 * ledger's transaction, and the race cannot be reached through that
 * transaction: SQLite gives one writer at a time, so two writers are
 * serialised by the ledger row's insert before either of them reads the
 * bucket. Calling the private write directly is what puts two connections
 * in the window the race lives in.
 */
function writeBucketToSlot(
    UsageLedger $ledger,
    OwnerScope $scope,
    LlmUsage|ToolUsage $usage,
    float $credits,
    DateTimeImmutable $at,
): void {
    (new ReflectionMethod(UsageLedger::class, 'addToBucket'))
        ->invoke($ledger, $scope, $usage, $credits, $at);
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

it('stamps the bucket row it writes', function (): void {
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

    $bucket = Capsule::table('usage_buckets')->first();

    // The row's `created_at` says when the slot was first metered, and a
    // write that goes through the query builder stamps nothing at all.
    expect($bucket->created_at)->not->toBeNull()
        ->and($bucket->updated_at)->not->toBeNull();
});

it('keeps the credits of a bucket on the eight-decimal grid', function (): void {
    // A price that arrives as 0.1 + 0.2 is 0.30000000000000004 as a
    // float, and the balance and the cap are compared on the grid — so
    // the amount that reaches the bucket is the rounded one.
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

    $ledger->record($context, $subject, $usage, 0.1 + 0.2, true, $scopes);
    $ledger->record($context, $subject, $usage, 0.1 + 0.2, true, $scopes);

    $bucket = Capsule::table('usage_buckets')->first();
    $stored = (float) $bucket->credits;

    expect($stored)->toBe(0.6)
        // The stored value is the nearest double to a number with eight
        // decimals, not a float that drifted off the grid.
        ->and(CreditAmount::round($stored))->toBe($stored);
});

it('writes the credits of a bucket with every decimal it was given', function (): void {
    // A float prints with fourteen significant digits, so an amount with
    // more than that is where a rounded write shows: the bucket would
    // hold 12345678.123457 instead of the amount that was charged.
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

    $ledger->record($context, $subject, $usage, 12345678.12345678, true, $scopes);
    $ledger->record($context, $subject, $usage, 12345678.12345678, true, $scopes);

    $bucket = Capsule::table('usage_buckets')->first();

    expect((float) $bucket->credits)->toBe(12345678.12345678 + 12345678.12345678);
});

it('survives a rival writer that commits the same slot first', function (): void {
    // Two connections on one file-backed SQLite, and a rendezvous that
    // puts the rival's commit between the writer's read and the writer's
    // write: the window a read-then-insert loses. The writer had seen an
    // empty slot, and by the time it wrote, the slot was taken.
    //
    // WAL is what makes the test possible. SQLite gives one writer at a
    // time, so a reader must not block the writer that commits behind
    // its back.
    $path = sys_get_temp_dir() . '/spora-usage-race-' . bin2hex(random_bytes(8)) . '.sqlite';

    // The connector refuses a database that is not there yet.
    touch($path);

    // The suite's own connection is an in-memory one, and a second
    // connection to it would share the same database — so the race needs
    // its own file, and its own manager to hold both connections.
    $core = Spora\Core\Database::getCapsule();

    $capsule = new Capsule();

    foreach (['writer', 'rival'] as $name) {
        $capsule->addConnection([
            'driver'                  => 'sqlite',
            'database'                => $path,
            'prefix'                  => '',
            'foreign_key_constraints' => true,
            'busy_timeout'            => 5000,
            'journal_mode'            => 'wal',
            'synchronous'             => 'NORMAL',
        ], $name);
    }

    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $manager = $capsule->getDatabaseManager();
    $manager->setDefaultConnection('writer');

    try {
        $migration = require __DIR__ . '/../../../database/migrations/usage_000001_create_usage_tables.php';

        if (is_object($migration) && method_exists($migration, 'up')) {
            $migration->up();
        }

        $scope = OwnerScope::principal(1);
        $usage = new LlmUsage(
            usage: new Usage(inputTokens: 100, outputTokens: 0),
            provider: 'openai_compatible',
            model: 'gpt-4o',
        );
        $at = new DateTimeImmutable('2026-01-01 12:00:00');

        $ledger = new UsageLedger();
        $writerConnection = $manager->connection('writer');
        $rivalConnection = $manager->connection('rival');
        $rivalHasWritten = false;

        $writerConnection->beforeExecuting(function (string $query) use (
            &$rivalHasWritten,
            $manager,
            $rivalConnection,
            $ledger,
            $scope,
            $usage,
            $at,
        ): void {
            $sql = strtolower($query);

            // The writer's write to the slot, whatever shape it takes.
            // The read before it is not the moment: the rival has to
            // land between the read and the write.
            if ($rivalHasWritten || ! str_contains($sql, 'usage_buckets') || str_starts_with($sql, 'select')) {
                return;
            }

            $rivalHasWritten = true;
            $manager->setDefaultConnection('rival');

            try {
                $rivalConnection->transaction(function () use ($ledger, $scope, $usage, $at): void {
                    writeBucketToSlot($ledger, $scope, $usage, 2.0, $at);
                });
            } finally {
                $manager->setDefaultConnection('writer');
            }
        });

        $writerConnection->transaction(function () use ($ledger, $scope, $usage, $at): void {
            writeBucketToSlot($ledger, $scope, $usage, 1.0, $at);
        });

        $bucket = $manager->connection('writer')->table('usage_buckets')->first();

        expect((float) $bucket->credits)->toBe(3.0)
            ->and((int) $bucket->llm_calls)->toBe(2)
            ->and((int) $bucket->total_tokens)->toBe(200);
    } finally {
        $manager->purge('writer');
        $manager->purge('rival');

        $core->setAsGlobal();
        $core->bootEloquent();

        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});
