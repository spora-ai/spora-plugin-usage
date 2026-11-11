<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One cap on one owner scope.
 *
 * `window` and `window_size` together name the range the cap covers:
 * `window_size` is the hour count for the `rolling` window and 1 for the
 * named ones.
 *
 * @property int $id
 * @property string $owner_type 'principal' | 'user'
 * @property int $owner_id
 * @property string $window 'hour' | 'day' | 'week' | 'month' | 'rolling' | 'total'
 * @property int $window_size
 * @property string $unit 'credits' | 'total_tokens' | 'output_tokens' | 'llm_calls' | 'tool_calls'
 * @property float $cap
 * @property string $mode 'hard' | 'soft'
 * @property bool $enabled
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class UsageLimit extends Model
{
    protected $table = 'usage_limits';

    public const MODE_HARD = 'hard';
    public const MODE_SOFT = 'soft';

    public const UNIT_CREDITS = 'credits';
    public const UNIT_TOTAL_TOKENS = 'total_tokens';
    public const UNIT_OUTPUT_TOKENS = 'output_tokens';
    public const UNIT_LLM_CALLS = 'llm_calls';
    public const UNIT_TOOL_CALLS = 'tool_calls';

    /** @var list<string> */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'window',
        'window_size',
        'unit',
        'cap',
        'mode',
        'enabled',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'owner_id'    => 'integer',
        'window_size' => 'integer',
        'cap'         => 'float',
        'enabled'     => 'boolean',
    ];
}
