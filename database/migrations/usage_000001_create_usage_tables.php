<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The plugin's six metering tables.
 *
 * The ledger and the buckets are append-heavy audit tables and carry no
 * foreign keys on purpose:
 *
 *  - `usage_ledger.principal_id` is a *snapshot* of the owner, not a live
 *    reference. `AgentPrincipalService::bulkPropagateToTasks()` re-points
 *    `tasks.principal_id` when an agent is transferred, so a row that
 *    followed its principal would silently move a group's history onto its
 *    new owner. A cascading foreign key would instead delete the history of
 *    every scope the principal ever paid for.
 *  - every other table is keyed on the polymorphic `(owner_type, owner_id)`
 *    pair, which no single foreign key can express.
 *
 * `usage_prices.provider` and `usage_prices.operation` are `NOT NULL` with an
 * empty-string default rather than nullable: MySQL's unique index treats
 * NULLs as distinct, so a nullable column would let two rows for the same
 * model coexist.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->createLedger();
        $this->createBuckets();
        $this->createLimits();
        $this->createCredits();
        $this->createCreditGrants();
        $this->createPrices();
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('usage_prices');
        Capsule::schema()->dropIfExists('usage_credit_grants');
        Capsule::schema()->dropIfExists('usage_credits');
        Capsule::schema()->dropIfExists('usage_limits');
        Capsule::schema()->dropIfExists('usage_buckets');
        Capsule::schema()->dropIfExists('usage_ledger');
    }

    private function createLedger(): void
    {
        if (Capsule::schema()->hasTable('usage_ledger')) {
            return;
        }

        Capsule::schema()->create('usage_ledger', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('principal_id');
            $table->string('principal_type', 16);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kind', 16);
            $table->string('provider', 64)->nullable();
            $table->string('model', 191)->nullable();
            $table->string('tool_name', 191)->nullable();
            $table->string('tool_class', 191)->nullable();
            $table->string('operation', 191)->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('cached_tokens')->default(0);
            $table->decimal('credits', 20, 8)->default(0);
            $table->boolean('priced')->default(true);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->dateTime('occurred_at');
            $table->string('source', 16)->default('live');
            $table->timestamps();

            $table->index('principal_id');
            $table->index('user_id');
            $table->index('task_id');
            $table->index('agent_id');
            $table->index('occurred_at');
            $table->index(['principal_id', 'occurred_at']);
        });
    }

    private function createBuckets(): void
    {
        if (Capsule::schema()->hasTable('usage_buckets')) {
            return;
        }

        Capsule::schema()->create('usage_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->dateTime('bucket_start');
            $table->decimal('credits', 20, 8)->default(0);
            $table->integer('total_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('llm_calls')->default(0);
            $table->integer('tool_calls')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'bucket_start']);
        });
    }

    private function createLimits(): void
    {
        if (Capsule::schema()->hasTable('usage_limits')) {
            return;
        }

        Capsule::schema()->create('usage_limits', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->string('window', 16);
            $table->integer('window_size')->default(1);
            $table->string('unit', 32);
            $table->decimal('cap', 20, 8)->default(0);
            $table->string('mode', 8)->default('hard');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'window', 'window_size', 'unit']);
        });
    }

    private function createCredits(): void
    {
        if (Capsule::schema()->hasTable('usage_credits')) {
            return;
        }

        Capsule::schema()->create('usage_credits', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->decimal('balance', 20, 8)->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    private function createCreditGrants(): void
    {
        if (Capsule::schema()->hasTable('usage_credit_grants')) {
            return;
        }

        Capsule::schema()->create('usage_credit_grants', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->decimal('amount', 20, 8)->default(0);
            $table->dateTime('granted_at');
            $table->string('note', 500)->nullable();
            $table->string('period', 32)->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'granted_at']);
        });
    }

    private function createPrices(): void
    {
        if (Capsule::schema()->hasTable('usage_prices')) {
            return;
        }

        Capsule::schema()->create('usage_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('provider', 64)->default('');
            $table->string('key', 191);
            $table->string('operation', 191)->default('');
            $table->decimal('input_price', 20, 8)->default(0);
            $table->decimal('output_price', 20, 8)->default(0);
            $table->decimal('cached_price', 20, 8)->default(0);
            $table->decimal('call_price', 20, 8)->default(0);
            $table->timestamps();

            $table->unique(['kind', 'provider', 'key', 'operation']);
        });
    }
};
