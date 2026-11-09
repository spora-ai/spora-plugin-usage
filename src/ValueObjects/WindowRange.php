<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\ValueObjects;

use DateTimeImmutable;

/**
 * The span a window covers.
 *
 * `from` is null for the window that has no lower bound — everything ever
 * recorded. Both bounds are bucket-aligned: a window is measured as the sum
 * of the buckets that overlap it, and rounding the lower bound down is what
 * makes that sum round up rather than down.
 */
final readonly class WindowRange
{
    public function __construct(
        public ?DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}
}
