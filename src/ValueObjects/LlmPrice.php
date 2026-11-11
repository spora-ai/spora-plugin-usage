<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\ValueObjects;

use Spora\Metering\LlmUsage;

/**
 * What one model costs.
 *
 * The three rates are per token. A model has no per-call rate: the price
 * book carries a `call_price` column for tools, and the API contract marks
 * it as tools-only.
 */
final readonly class LlmPrice
{
    public function __construct(
        public float $inputPrice,
        public float $outputPrice,
        public float $cachedPrice,
    ) {}

    /**
     * What one provider request costs.
     *
     * The cached tokens are a subset of the input tokens for OpenAI, whose
     * `Usage::$cachedTokens` is `prompt_tokens_details.cached_tokens`, and
     * are zero for Anthropic, whose separate counters are not surfaced here.
     * Subtracting them before charging the discounted rate is therefore
     * right for OpenAI and degrades to the plain input rate for Anthropic.
     */
    public function creditsFor(LlmUsage $usage): float
    {
        $cached = min($usage->cachedTokens(), $usage->inputTokens());

        return CreditAmount::round(
            ($usage->inputTokens() - $cached) * $this->inputPrice
            + $cached * $this->cachedPrice
            + $usage->outputTokens() * $this->outputPrice,
        );
    }
}
