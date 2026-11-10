<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The `billed` column: whether the credits were actually charged.
 *
 * `priced` says whether the price book had a row for the event. `billed`
 * says whether the event was charged to a credit pool — a principal who
 * ran it with their own provider key has a price and was not charged.
 *
 * Without the column, an event that was not billed is indistinguishable
 * from one that was free: both carry zero credits, and the report cannot
 * tell "the operator never priced this" from "the principal paid for it
 * themselves".
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Capsule::schema()->hasTable('usage_ledger')) {
            return;
        }

        if (Capsule::schema()->hasColumn('usage_ledger', 'billed')) {
            return;
        }

        Capsule::schema()->table('usage_ledger', function (Blueprint $table): void {
            $table->boolean('billed')->default(true);
        });
    }

    public function down(): void
    {
        if (!Capsule::schema()->hasTable('usage_ledger')) {
            return;
        }

        if (!Capsule::schema()->hasColumn('usage_ledger', 'billed')) {
            return;
        }

        Capsule::schema()->table('usage_ledger', function (Blueprint $table): void {
            $table->dropColumn('billed');
        });
    }
};
