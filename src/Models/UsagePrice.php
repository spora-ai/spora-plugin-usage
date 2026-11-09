<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the price book.
 *
 * `provider` and `operation` are empty strings rather than nulls: MySQL's
 * unique index treats NULLs as distinct, so a nullable column would let two
 * rows for the same model coexist.
 *
 * @property int $id
 * @property string $kind 'model' | 'tool'
 * @property string $provider The driver's protocol name; '' means any provider.
 * @property string $key The model name, or the tool's fully-qualified class name.
 * @property string $operation The tool operation; '' means any operation.
 * @property float $input_price
 * @property float $output_price
 * @property float $cached_price
 * @property float $call_price
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class UsagePrice extends Model
{
    protected $table = 'usage_prices';

    public const KIND_MODEL = 'model';
    public const KIND_TOOL = 'tool';

    /** @var list<string> */
    protected $fillable = [
        'kind',
        'provider',
        'key',
        'operation',
        'input_price',
        'output_price',
        'cached_price',
        'call_price',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'input_price'  => 'float',
        'output_price' => 'float',
        'cached_price' => 'float',
        'call_price'   => 'float',
    ];
}
