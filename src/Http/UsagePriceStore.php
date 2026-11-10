<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Http;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Plugins\Usage\ValueObjects\CreditAmount;

/**
 * The price book, and the calls that had no price.
 *
 * The two belong together: the book says what the install charges, and the
 * unpriced report says what it charged nothing for — a gap in the book that
 * no other view would show.
 *
 * This class is the only place that knows the price book's column names,
 * so a schema change lands in one file instead of in every controller.
 */
final class UsagePriceStore
{
    private const TABLE_LEDGER = 'usage_ledger';

    /**
     * The aggregate that counts the calls of a group of rows.
     */
    private const COUNT_CALLS = 'count(*) as calls';

    private const TABLE_PRICES = 'usage_prices';

    /**
     * The whole price book.
     *
     * @return list<array<string, mixed>>
     */
    public function prices(): array
    {
        $rows = Capsule::table(self::TABLE_PRICES)
            ->orderBy('kind')
            ->orderBy('provider')
            ->orderBy('key')
            ->orderBy('operation')
            ->get();

        return array_map(static fn(object $row): array => [
            'kind' => (string) $row->kind,
            'provider' => (string) $row->provider,
            'key' => (string) $row->key,
            'operation' => (string) $row->operation,
            'input_price' => CreditAmount::round((float) $row->input_price),
            'output_price' => CreditAmount::round((float) $row->output_price),
            'cached_price' => CreditAmount::round((float) $row->cached_price),
            'call_price' => CreditAmount::round((float) $row->call_price),
        ], $rows->all());
    }

    /**
     * Replace the price book with the given rows.
     *
     * @param list<array<string, mixed>> $prices
     */
    public function replacePrices(array $prices): void
    {
        Capsule::connection()->transaction(function () use ($prices): void {
            Capsule::table(self::TABLE_PRICES)->delete();

            foreach ($prices as $price) {
                Capsule::table(self::TABLE_PRICES)->insert([
                    'kind' => $price['kind'],
                    'provider' => $price['provider'],
                    'key' => $price['key'],
                    'operation' => $price['operation'],
                    'input_price' => CreditAmount::format((float) $price['input_price']),
                    'output_price' => CreditAmount::format((float) $price['output_price']),
                    'cached_price' => CreditAmount::format((float) $price['cached_price']),
                    'call_price' => CreditAmount::format((float) $price['call_price']),
                ]);
            }
        });
    }

    /**
     * The models and tools that were metered without a price row, and the
     * ones that had a price but were not billed.
     *
     * The two are different problems and the report keeps them apart:
     *
     *  - **unpriced** — the price book has no row for the call, so it
     *    cost nothing and nobody was charged. A gap in the book.
     *  - **not billed** — the book has a price and the call was not
     *    charged, because the principal ran it with their own
     *    configuration and the installation did not pay for it.
     *
     * The ledger's `priced` and `billed` columns are what make this cheap:
     * they are the record of a call that cost nothing, which is invisible
     * in every other view.
     *
     * @return array{
     *     models: list<array{provider: string, model: string, calls: int}>,
     *     tools: list<array{tool_class: string, tool_name: string, calls: int}>,
     *     not_billed: array{
     *         models: list<array{provider: string, model: string, calls: int, tokens: int}>,
     *         tools: list<array{tool_class: string, tool_name: string, calls: int}>
     *     }
     * }
     */
    public function unpriced(): array
    {
        return [
            'models' => $this->unpricedModels(),
            'tools' => $this->unpricedTools(),
            'not_billed' => [
                'models' => $this->notBilledModels(),
                'tools' => $this->notBilledTools(),
            ],
        ];
    }

    /**
     * The models that were used for free, with the number of calls each.
     *
     * @return list<array{provider: string, model: string, calls: int}>
     */
    private function unpricedModels(): array
    {
        $rows = Capsule::table(self::TABLE_LEDGER)
            ->where('priced', false)
            ->where('kind', '!=', 'tool')
            ->select(['provider', 'model'])
            ->selectRaw(self::COUNT_CALLS)
            ->groupBy('provider', 'model')
            ->get();

        $models = [];
        foreach ($rows as $row) {
            $models[] = [
                'provider' => (string) ($row->provider ?? ''),
                'model' => (string) ($row->model ?? ''),
                'calls' => (int) ($row->calls ?? 0),
            ];
        }

        return $models;
    }

    /**
     * The tools that were used for free, with the number of calls each.
     *
     * @return list<array{tool_class: string, tool_name: string, calls: int}>
     */
    private function unpricedTools(): array
    {
        $rows = Capsule::table(self::TABLE_LEDGER)
            ->where('priced', false)
            ->where('kind', 'tool')
            ->select(['tool_name', 'tool_class'])
            ->selectRaw(self::COUNT_CALLS)
            ->groupBy('tool_name', 'tool_class')
            ->get();

        $tools = [];
        foreach ($rows as $row) {
            $tools[] = [
                'tool_class' => (string) ($row->tool_class ?? ''),
                'tool_name' => (string) ($row->tool_name ?? ''),
                'calls' => (int) ($row->calls ?? 0),
            ];
        }

        return $tools;
    }

    /**
     * The models that had a price and were not charged.
     *
     * The principal ran them with their own provider key, so the
     * installation did not pay for them. The token count is the input and
     * output tokens the provider reported — the two the ledger stores.
     *
     * @return list<array{provider: string, model: string, calls: int, tokens: int}>
     */
    private function notBilledModels(): array
    {
        $rows = Capsule::table(self::TABLE_LEDGER)
            ->where('billed', false)
            ->where('priced', true)
            ->where('kind', '!=', 'tool')
            ->select(['provider', 'model'])
            ->selectRaw(self::COUNT_CALLS)
            ->selectRaw('sum(input_tokens + output_tokens) as tokens')
            ->groupBy('provider', 'model')
            ->get();

        $models = [];
        foreach ($rows as $row) {
            $models[] = [
                'provider' => (string) ($row->provider ?? ''),
                'model' => (string) ($row->model ?? ''),
                'calls' => (int) ($row->calls ?? 0),
                'tokens' => (int) ($row->tokens ?? 0),
            ];
        }

        return $models;
    }

    /**
     * The tools that had a price and were not charged.
     *
     * @return list<array{tool_class: string, tool_name: string, calls: int}>
     */
    private function notBilledTools(): array
    {
        $rows = Capsule::table(self::TABLE_LEDGER)
            ->where('billed', false)
            ->where('priced', true)
            ->where('kind', 'tool')
            ->select(['tool_name', 'tool_class'])
            ->selectRaw(self::COUNT_CALLS)
            ->groupBy('tool_name', 'tool_class')
            ->get();

        $tools = [];
        foreach ($rows as $row) {
            $tools[] = [
                'tool_class' => (string) ($row->tool_class ?? ''),
                'tool_name' => (string) ($row->tool_name ?? ''),
                'calls' => (int) ($row->calls ?? 0),
            ];
        }

        return $tools;
    }
}
