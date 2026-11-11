<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use Spora\Plugins\Usage\ValueObjects\WindowRange;

/**
 * The ranges a window covers and the slots the buckets fall into.
 *
 * Every window is rolling, never a calendar boundary: a day cap is the last
 * 24 hours, so a run at 23:59 and one at 00:01 are treated alike and the
 * boundary never moves under the operator's feet.
 */
final class WindowCalculator
{
    public const WINDOW_HOUR = 'hour';
    public const WINDOW_DAY = 'day';
    public const WINDOW_WEEK = 'week';
    public const WINDOW_MONTH = 'month';
    public const WINDOW_ROLLING = 'rolling';
    public const WINDOW_TOTAL = 'total';

    public const BUCKET_MINUTES = 15;

    /**
     * The range a window covers.
     *
     * The bounds are bucket-aligned so that the sum of the buckets in
     * `[from, to]` is the window's spend: a bucket that only partly overlaps
     * the window counts whole, which is the safe direction for a cap.
     *
     * @throws InvalidArgumentException On a window nobody knows.
     */
    public function rangeFor(string $window, int $windowSize, DateTimeImmutable $now): WindowRange
    {
        $to = $this->bucketStart($now);

        $from = match ($window) {
            self::WINDOW_HOUR => $this->bucketStart($now->modify('-1 hour')),
            self::WINDOW_DAY => $this->bucketStart($now->modify('-24 hours')),
            self::WINDOW_WEEK => $this->bucketStart($now->modify('-7 days')),
            self::WINDOW_MONTH => $this->bucketStart($now->modify('-30 days')),
            self::WINDOW_ROLLING => $this->bucketStart($now->modify($this->rollingOffset($windowSize))),
            self::WINDOW_TOTAL => null,
            default => throw new InvalidArgumentException("Unknown usage window '{$window}'."),
        };

        return new WindowRange($from, $to);
    }

    /**
     * The start of the slot a moment falls into.
     */
    public function bucketStart(DateTimeImmutable $at): DateTimeImmutable
    {
        $minute = (int) $at->format('i');

        return $at->setTime(
            (int) $at->format('H'),
            intdiv($minute, self::BUCKET_MINUTES) * self::BUCKET_MINUTES,
            0,
        );
    }

    /**
     * A rolling window of zero hours would measure nothing, so the smallest
     * measurable window is one hour.
     */
    private function rollingOffset(int $windowSize): string
    {
        return sprintf('-%d hours', max(1, $windowSize));
    }
}
