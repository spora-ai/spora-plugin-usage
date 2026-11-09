<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\ValueObjects;

use Spora\Metering\ToolUsage;

/**
 * What one tool call costs.
 *
 * A tool has no token rates: it is priced per call.
 */
final readonly class ToolPrice
{
    public function __construct(
        public float $callPrice,
    ) {}

    /**
     * What one tool execution costs.
     *
     * The outcome does not enter the price: a tool that fails still cost
     * something, so a charge that depended on the success flag would let a
     * failing tool be free.
     */
    public function creditsFor(ToolUsage $usage): float // NOSONAR php:S1172 — the price book's contract; the outcome does not change the price
    {
        return CreditAmount::round($this->callPrice);
    }
}
