<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Services;

use Spora\Plugins\Usage\Models\UsagePrice;
use Spora\Plugins\Usage\ValueObjects\LlmPrice;
use Spora\Plugins\Usage\ValueObjects\ToolPrice;

/**
 * The price book: what a model or a tool costs.
 *
 * A model is priced on the pair `(provider, model)`. The row for the
 * driver's own protocol wins; the row with an empty provider is the
 * fallback for every provider. A model with no row at all is *unpriced*: it
 * costs nothing, and the ledger records the event as unpriced so the admin
 * API can list what has been seen and is silently free.
 *
 * A tool is priced on its fully-qualified class name — the wire name comes
 * from the `#[Tool]` attribute and changes between plugin releases, the
 * class does not — and an operation narrows the price to one operation of a
 * multi-operation tool.
 */
final class PriceBook
{
    public function priceForLlm(string $provider, string $model): ?LlmPrice
    {
        $row = UsagePrice::query()
            ->where('kind', UsagePrice::KIND_MODEL)
            ->where('key', $model)
            ->whereIn('provider', [$provider, ''])
            ->orderByRaw('CASE WHEN provider = ? THEN 0 ELSE 1 END', [$provider])
            ->first();

        if ($row === null) {
            return null;
        }

        return new LlmPrice(
            inputPrice: $row->input_price,
            outputPrice: $row->output_price,
            cachedPrice: $row->cached_price,
        );
    }

    public function priceForTool(string $toolClass, ?string $operation): ?ToolPrice
    {
        $operation ??= '';

        $row = UsagePrice::query()
            ->where('kind', UsagePrice::KIND_TOOL)
            ->where('key', $toolClass)
            ->whereIn('operation', [$operation, ''])
            ->orderByRaw('CASE WHEN operation = ? THEN 0 ELSE 1 END', [$operation])
            ->orderBy('provider')
            ->first();

        if ($row === null) {
            return null;
        }

        return new ToolPrice($row->call_price);
    }
}
