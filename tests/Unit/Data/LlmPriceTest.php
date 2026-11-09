<?php

declare(strict_types=1);

use Spora\Drivers\ValueObjects\Usage;
use Spora\Metering\LlmUsage;
use Spora\Metering\ToolUsage;
use Spora\Plugins\Usage\ValueObjects\LlmPrice;
use Spora\Plugins\Usage\ValueObjects\ToolPrice;

function llmUsage(int $inputTokens, int $outputTokens, int $cachedTokens = 0): LlmUsage
{
    return new LlmUsage(
        usage: new Usage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cachedTokens: $cachedTokens,
        ),
        provider: 'openai_compatible',
        model: 'gpt-4o',
    );
}

it('charges the cached tokens at the discounted rate and the rest at the input rate', function (): void {
    $price = new LlmPrice(inputPrice: 0.01, outputPrice: 0.02, cachedPrice: 0.005);

    $credits = $price->creditsFor(llmUsage(inputTokens: 1000, outputTokens: 200, cachedTokens: 400));

    expect($credits)->toBe(12.0);
});

it('charges the plain input rate when the provider reports no cached tokens', function (): void {
    $price = new LlmPrice(inputPrice: 0.01, outputPrice: 0.02, cachedPrice: 0.005);

    $credits = $price->creditsFor(llmUsage(inputTokens: 1000, outputTokens: 200));

    expect($credits)->toBe(14.0);
});

it('never charges a negative amount when the cached count exceeds the input count', function (): void {
    $price = new LlmPrice(inputPrice: 0.01, outputPrice: 0.02, cachedPrice: 0.005);

    $credits = $price->creditsFor(llmUsage(inputTokens: 100, outputTokens: 0, cachedTokens: 400));

    expect($credits)->toBe(0.5);
});

it('charges a tool per call, whether the call succeeded or not', function (): void {
    $price = new ToolPrice(callPrice: 1.5);

    $succeeded = new ToolUsage(
        toolName: 'media',
        toolClass: 'Spora\\Tools\\MediaTool',
        operation: 'resize',
        succeeded: true,
    );
    $failed = new ToolUsage(
        toolName: 'media',
        toolClass: 'Spora\\Tools\\MediaTool',
        operation: 'resize',
        succeeded: false,
    );

    expect($price->creditsFor($succeeded))->toBe(1.5)
        ->and($price->creditsFor($failed))->toBe(1.5);
});
