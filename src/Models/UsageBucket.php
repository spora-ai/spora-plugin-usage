<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The counters of one 15-minute slot, for one owner scope.
 *
 * Every window is a range sum over these rows, so the row is keyed by the
 * slot it covers rather than by the event that filled it.
 *
 * @property int $id
 * @property string $owner_type 'principal' | 'user'
 * @property int $owner_id
 * @property \Carbon\Carbon $bucket_start
 * @property float $credits
 * @property int $total_tokens
 * @property int $output_tokens
 * @property int $llm_calls
 * @property int $tool_calls
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class UsageBucket extends Model
{
    protected $table = 'usage_buckets';

    /** @var list<string> */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'bucket_start',
        'credits',
        'total_tokens',
        'output_tokens',
        'llm_calls',
        'tool_calls',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'owner_id'      => 'integer',
        'bucket_start'  => 'datetime',
        'credits'       => 'float',
        'total_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'llm_calls'     => 'integer',
        'tool_calls'    => 'integer',
    ];
}
