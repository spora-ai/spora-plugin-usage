<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The credit pool of one owner scope.
 *
 * A scope without a row has no pool at all: only its window limits apply.
 *
 * @property int $id
 * @property string $owner_type 'principal' | 'user'
 * @property int $owner_id
 * @property float $balance
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class UsageCredit extends Model
{
    protected $table = 'usage_credits';

    /** @var list<string> */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'balance',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'owner_id' => 'integer',
        'balance'  => 'float',
    ];
}
