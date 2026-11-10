<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One metered event, recorded once and never rewritten.
 *
 * `principal_id` and `principal_type` are a snapshot of the owner at the
 * time of the event: an agent transfer re-points the task's principal, and a
 * ledger that followed it would move a group's history onto its new owner.
 *
 * @property int $id
 * @property int $principal_id
 * @property string $principal_type 'user' | 'group'
 * @property int|null $user_id
 * @property string $kind 'llm' | 'tool'
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $tool_name
 * @property string|null $tool_class
 * @property string|null $operation
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cached_tokens
 * @property float $credits
 * @property bool $priced Whether the price book had a row for the event.
 * @property bool $billed Whether the credits were charged to a credit pool.
 *                        A principal who ran the event with their own
 *                        configuration has a price and was not charged.
 * @property int|null $task_id
 * @property int|null $agent_id
 * @property \Carbon\Carbon $occurred_at
 * @property string $source
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class UsageLedger extends Model
{
    protected $table = 'usage_ledger';

    /** @var list<string> */
    protected $fillable = [
        'principal_id',
        'principal_type',
        'user_id',
        'kind',
        'provider',
        'model',
        'tool_name',
        'tool_class',
        'operation',
        'input_tokens',
        'output_tokens',
        'cached_tokens',
        'credits',
        'priced',
        'billed',
        'task_id',
        'agent_id',
        'occurred_at',
        'source',
    ];

    /**
     * Credit amounts are cast to `float` because the API contract carries
     * them as JSON numbers, and Eloquent applies a cast on read only — the
     * eight-decimal rounding is the writer's job.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'principal_id'  => 'integer',
        'user_id'       => 'integer',
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'cached_tokens' => 'integer',
        'credits'       => 'float',
        'priced'        => 'boolean',
        'billed'        => 'boolean',
        'task_id'       => 'integer',
        'agent_id'      => 'integer',
        'occurred_at'   => 'datetime',
    ];
}
