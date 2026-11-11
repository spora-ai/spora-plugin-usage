<?php

declare(strict_types=1);

use Spora\Plugins\Usage\Models\UsagePrice;
use Spora\Plugins\Usage\Services\PriceBook;

/**
 * @param array<string, float> $prices
 */
function seedModelPrice(string $provider, string $model, array $prices = []): void
{
    UsagePrice::create([
        'kind'         => UsagePrice::KIND_MODEL,
        'provider'     => $provider,
        'key'          => $model,
        'operation'    => '',
        'input_price'  => $prices['input'] ?? 0.0,
        'output_price' => $prices['output'] ?? 0.0,
        'cached_price' => $prices['cached'] ?? 0.0,
        'call_price'   => 0.0,
    ]);
}

/**
 * @param array<string, float> $prices
 */
function seedToolPrice(string $toolClass, string $operation, array $prices = []): void
{
    UsagePrice::create([
        'kind'         => UsagePrice::KIND_TOOL,
        'provider'     => '',
        'key'          => $toolClass,
        'operation'    => $operation,
        'input_price'  => 0.0,
        'output_price' => 0.0,
        'cached_price' => 0.0,
        'call_price'   => $prices['call'] ?? 0.0,
    ]);
}

it('prices a model on the provider and the model name', function (): void {
    seedModelPrice('openai_compatible', 'gpt-4o', ['input' => 0.01, 'output' => 0.02]);
    seedModelPrice('anthropic', 'claude-sonnet', ['input' => 0.03]);

    $price = (new PriceBook())->priceForLlm('openai_compatible', 'gpt-4o');

    expect($price)->not->toBeNull()
        ->and($price->inputPrice)->toBe(0.01)
        ->and($price->outputPrice)->toBe(0.02);
});

it('falls back to the price of any provider when the driver has none of its own', function (): void {
    seedModelPrice('', 'gpt-4o', ['input' => 0.02]);
    seedModelPrice('anthropic', 'claude-sonnet', ['input' => 0.03]);

    $price = (new PriceBook())->priceForLlm('anthropic', 'gpt-4o');

    expect($price)->not->toBeNull()
        ->and($price->inputPrice)->toBe(0.02);
});

it('prefers the price of the driver over the price of any provider', function (): void {
    seedModelPrice('', 'gpt-4o', ['input' => 0.02]);
    seedModelPrice('openai_compatible', 'gpt-4o', ['input' => 0.01]);

    $price = (new PriceBook())->priceForLlm('openai_compatible', 'gpt-4o');

    expect($price)->not->toBeNull()
        ->and($price->inputPrice)->toBe(0.01);
});

it('leaves a model without a price row unpriced', function (): void {
    seedModelPrice('openai_compatible', 'gpt-4o', ['input' => 0.01]);

    expect((new PriceBook())->priceForLlm('openai_compatible', 'gpt-4o-mini'))->toBeNull()
        ->and((new PriceBook())->priceForLlm('anthropic', 'gpt-4o'))->toBeNull();
});

it('prices a tool on its class name', function (): void {
    seedToolPrice('Spora\\Tools\\MediaTool', '', ['call' => 1.5]);

    $price = (new PriceBook())->priceForTool('Spora\\Tools\\MediaTool', null);

    expect($price)->not->toBeNull()
        ->and($price->callPrice)->toBe(1.5);
});

it('narrows a tool price to one operation of a multi-operation tool', function (): void {
    seedToolPrice('Spora\\Tools\\MediaTool', '', ['call' => 1.5]);
    seedToolPrice('Spora\\Tools\\MediaTool', 'resize', ['call' => 2.5]);

    $book = new PriceBook();

    expect($book->priceForTool('Spora\\Tools\\MediaTool', 'resize')?->callPrice)->toBe(2.5)
        ->and($book->priceForTool('Spora\\Tools\\MediaTool', 'crop')?->callPrice)->toBe(1.5)
        ->and($book->priceForTool('Spora\\Tools\\MediaTool', null)?->callPrice)->toBe(1.5);
});

it('leaves a tool without a price row unpriced', function (): void {
    seedToolPrice('Spora\\Tools\\MediaTool', '', ['call' => 1.5]);

    expect((new PriceBook())->priceForTool('Spora\\Tools\\OtherTool', null))->toBeNull();
});
