<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One movement of a credit pool.
 *
 * A grant may be negative: the admin API subtracts as readily as it adds.
 *
 * @property int $id
 * @property string $owner_type 'principal' | 'user'
 * @property int $owner_id
 * @property float $amount
 * @property \Carbon\Carbon $granted_at
 * @property string|null $note
 * @property string|null $period
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class UsageCreditGrant extends Model
{
    protected $table = 'usage_credit_grants';

    /** @var list<string> */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'amount',
        'granted_at',
        'note',
        'period',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'owner_id'   => 'integer',
        'amount'     => 'float',
        'granted_at' => 'datetime',
    ];
}
