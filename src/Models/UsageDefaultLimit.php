<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One cap on one scope of principals, for the owners who have none.
 *
 * The same shape as {@see UsageLimit}, and the same meaning for the owner
 * it covers: the cap is measured against that owner's own spend.
 *
 * `scope` says who the set covers — everybody, or one principal type. The
 * type's set replaces the general one for that type, so an operator who
 * wants users held to a tighter rule can say so without losing the rule
 * that holds everybody else.
 *
 * @property int $id
 * @property string $scope 'all' | 'user' | 'group'
 * @property string $window 'hour' | 'day' | 'week' | 'month' | 'rolling' | 'total'
 * @property int $window_size
 * @property string $unit 'credits' | 'total_tokens' | 'output_tokens' | 'llm_calls' | 'tool_calls'
 * @property float $cap
 * @property string $mode 'hard' | 'soft'
 * @property bool $enabled
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class UsageDefaultLimit extends Model
{
    protected $table = 'usage_default_limits';

    /** Every principal, whatever its type. */
    public const SCOPE_ALL = 'all';

    /** The principals of a user. */
    public const SCOPE_USER = 'user';

    /** The principals of a group. */
    public const SCOPE_GROUP = 'group';

    /** @var list<string> */
    public const SCOPES = [
        self::SCOPE_ALL,
        self::SCOPE_USER,
        self::SCOPE_GROUP,
    ];

    /** @var list<string> */
    protected $fillable = [
        'scope',
        'window',
        'window_size',
        'unit',
        'cap',
        'mode',
        'enabled',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'window_size' => 'integer',
        'cap'         => 'float',
        'enabled'     => 'boolean',
    ];
}
