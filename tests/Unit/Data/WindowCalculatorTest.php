<?php

declare(strict_types=1);

use Spora\Plugins\Usage\Services\WindowCalculator;

it('measures the hour window as the last hour, not as the hour since midnight', function (): void {
    $now = new DateTimeImmutable('2026-03-15 13:47:33');
    $calculator = new WindowCalculator();

    $range = $calculator->rangeFor(WindowCalculator::WINDOW_HOUR, 1, $now);

    expect($range->from)->not->toBeNull()
        ->and($range->from->format('Y-m-d H:i:s'))->toBe('2026-03-15 12:45:00')
        ->and($range->to->format('Y-m-d H:i:s'))->toBe('2026-03-15 13:45:00');
});

it('measures the day window as the last 24 hours', function (): void {
    $now = new DateTimeImmutable('2026-03-15 13:47:33');
    $calculator = new WindowCalculator();

    $range = $calculator->rangeFor(WindowCalculator::WINDOW_DAY, 1, $now);
    $span = $range->from?->diff($range->to);

    expect($span)->not->toBeNull()
        ->and($span->days)->toBe(1)
        ->and($span->h)->toBe(0)
        ->and($span->i)->toBe(0);
});

it('measures the week window as the last 7 days', function (): void {
    $now = new DateTimeImmutable('2026-03-15 13:47:33');
    $calculator = new WindowCalculator();

    $range = $calculator->rangeFor(WindowCalculator::WINDOW_WEEK, 1, $now);
    $span = $range->from?->diff($range->to);

    expect($span)->not->toBeNull()
        ->and($span->days)->toBe(7)
        ->and($span->h)->toBe(0);
});

it('measures the month window as the last 30 days', function (): void {
    $now = new DateTimeImmutable('2026-03-15 13:47:33');
    $calculator = new WindowCalculator();

    $range = $calculator->rangeFor(WindowCalculator::WINDOW_MONTH, 1, $now);
    $span = $range->from?->diff($range->to);

    expect($span)->not->toBeNull()
        ->and($span->days)->toBe(30)
        ->and($span->h)->toBe(0);
});

it('measures the rolling window as the last window_size hours', function (): void {
    $now = new DateTimeImmutable('2026-03-15 13:47:33');
    $calculator = new WindowCalculator();

    $range = $calculator->rangeFor(WindowCalculator::WINDOW_ROLLING, 5, $now);
    $span = $range->from?->diff($range->to);

    expect($span)->not->toBeNull()
        ->and($span->h)->toBe(5)
        ->and($span->i)->toBe(0);
});

it('measures the total window as everything ever recorded', function (): void {
    $now = new DateTimeImmutable('2026-03-15 13:47:33');
    $calculator = new WindowCalculator();

    $range = $calculator->rangeFor(WindowCalculator::WINDOW_TOTAL, 1, $now);

    expect($range->from)->toBeNull()
        ->and($range->to->format('Y-m-d H:i:s'))->toBe('2026-03-15 13:45:00');
});

it('refuses a window nobody knows', function (): void {
    (new WindowCalculator())->rangeFor('fortnight', 1, new DateTimeImmutable('2026-03-15 13:47:33'));
})->throws(InvalidArgumentException::class);

it('truncates a moment to the start of its 15-minute slot', function (): void {
    $calculator = new WindowCalculator();

    expect($calculator->bucketStart(new DateTimeImmutable('2026-03-15 13:47:33'))->format('H:i:s'))->toBe('13:45:00')
        ->and($calculator->bucketStart(new DateTimeImmutable('2026-03-15 13:00:00'))->format('H:i:s'))->toBe('13:00:00')
        ->and($calculator->bucketStart(new DateTimeImmutable('2026-03-15 13:59:59'))->format('H:i:s'))->toBe('13:45:00')
        ->and($calculator->bucketStart(new DateTimeImmutable('2026-03-15 00:01:00'))->format('H:i:s'))->toBe('00:00:00');
});
