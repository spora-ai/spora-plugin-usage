<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The default limit sets.
 *
 * A limit row is the ceiling of one window for one owner. A default row is
 * the same thing for every owner that has none of their own: the ceiling a
 * principal falls back to, so that a principal nobody configured is not
 * left unlimited by accident.
 *
 * The scope says who the set covers: everyone, or one principal type. The
 * type's set replaces the general one for that type, so an operator who
 * wants users held to a tighter rule can say so without losing the rule
 * that holds everybody else.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Capsule::schema()->hasTable('usage_default_limits')) {
            return;
        }

        Capsule::schema()->create('usage_default_limits', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->string('window', 16);
            $table->integer('window_size')->default(1);
            $table->string('unit', 32);
            $table->decimal('cap', 20, 8)->default(0);
            $table->string('mode', 8)->default('hard');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['scope', 'window', 'window_size', 'unit']);
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('usage_default_limits');
    }
};
