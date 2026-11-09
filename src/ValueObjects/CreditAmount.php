<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\ValueObjects;

/**
 * A credit amount.
 *
 * Credits are abstract — there is no currency — but they are not arbitrary
 * floats either: every amount is rounded to eight decimals before it is
 * compared, summed or written, because float dust would otherwise let a
 * balance drift and turn a cap into a false breach.
 */
final class CreditAmount
{
    public const SCALE = 8;

    public static function round(float $amount): float
    {
        return round($amount, self::SCALE);
    }

    /**
     * The eight-decimal string form.
     *
     * The writes that go through the query builder bypass Eloquent's casts,
     * and the string keeps the rounding on SQLite, whose DECIMAL column has
     * numeric affinity and would otherwise store the raw float.
     */
    public static function format(float $amount): string
    {
        return number_format(self::round($amount), self::SCALE, '.', '');
    }
}
