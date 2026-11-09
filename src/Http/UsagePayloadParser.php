<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Http;

use Spora\Http\JsonControllerHelpers;
use Spora\Plugins\Usage\Models\UsageLimit;
use Spora\Plugins\Usage\Models\UsagePrice;
use Spora\Plugins\Usage\Services\WindowCalculator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin payloads, read and checked.
 *
 * Every entry point answers either with the parsed payload or with the
 * `JsonResponse` that carries the refusal, so the caller has one thing to
 * check: a payload is either wholly read or wholly refused.
 *
 * The parser knows the vocabulary of the domain and nothing else — which
 * windows, which units, which modes and which price kinds exist. A payload
 * that names something outside that vocabulary is refused here rather than
 * stored and discovered later.
 */
final class UsagePayloadParser
{
    use JsonControllerHelpers;

    private const LIMIT_UNITS = [
        UsageLimit::UNIT_CREDITS,
        UsageLimit::UNIT_TOTAL_TOKENS,
        UsageLimit::UNIT_OUTPUT_TOKENS,
        UsageLimit::UNIT_LLM_CALLS,
        UsageLimit::UNIT_TOOL_CALLS,
    ];

    private const LIMIT_MODES = [UsageLimit::MODE_HARD, UsageLimit::MODE_SOFT];

    private const LIMIT_WINDOWS = [
        WindowCalculator::WINDOW_HOUR,
        WindowCalculator::WINDOW_DAY,
        WindowCalculator::WINDOW_WEEK,
        WindowCalculator::WINDOW_MONTH,
        WindowCalculator::WINDOW_ROLLING,
        WindowCalculator::WINDOW_TOTAL,
    ];

    private const PRICE_KINDS = [UsagePrice::KIND_MODEL, UsagePrice::KIND_TOOL];

    private const PRICE_FIELDS = ['input_price', 'output_price', 'cached_price', 'call_price'];

    /**
     * @return JsonResponse|list<array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}>
     */
    public function parseLimits(Request $request): JsonResponse|array
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $rows = $body['limits'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return $this->error('BAD_REQUEST', 'limits must be an array.', Response::HTTP_BAD_REQUEST);
        }

        $limits = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                return $this->error('BAD_REQUEST', "limits[{$index}] must be an object.", Response::HTTP_BAD_REQUEST);
            }

            $limit = $this->parseLimit($row, (int) $index);
            if ($limit instanceof JsonResponse) {
                return $limit;
            }

            $key = $limit['window'] . '|' . $limit['window_size'] . '|' . $limit['unit'];
            if (isset($seen[$key])) {
                return $this->error(
                    'CONFLICT',
                    "limits[{$index}] duplicates an earlier limit.",
                    Response::HTTP_CONFLICT,
                );
            }

            $seen[$key] = true;
            $limits[] = $limit;
        }

        return $limits;
    }

    /**
     * @return JsonResponse|array{amount: float, note: string|null}
     */
    public function parseGrant(Request $request): JsonResponse|array
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $amount = $body['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount)) {
            return $this->error('BAD_REQUEST', 'amount must be a number.', Response::HTTP_BAD_REQUEST);
        }
        if ((float) $amount === 0.0) {
            return $this->error(
                'INVALID_AMOUNT',
                'amount must not be zero.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $note = $body['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            return $this->error('BAD_REQUEST', 'note must be a string.', Response::HTTP_BAD_REQUEST);
        }

        return [
            'amount' => round((float) $amount, 8),
            'note' => $note === null ? null : trim($note),
        ];
    }

    /**
     * @return JsonResponse|list<array<string, mixed>>
     */
    public function parsePrices(Request $request): JsonResponse|array
    {
        $body = $this->safeDecodeJson($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $rows = $body['prices'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            return $this->error('BAD_REQUEST', 'prices must be an array.', Response::HTTP_BAD_REQUEST);
        }

        $prices = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                return $this->error('BAD_REQUEST', "prices[{$index}] must be an object.", Response::HTTP_BAD_REQUEST);
            }

            $price = $this->parsePrice($row, (int) $index);
            if ($price instanceof JsonResponse) {
                return $price;
            }

            $key = $price['kind'] . '|' . $price['provider'] . '|' . $price['key'] . '|' . $price['operation'];
            if (isset($seen[$key])) {
                return $this->error(
                    'CONFLICT',
                    "prices[{$index}] duplicates an earlier price.",
                    Response::HTTP_CONFLICT,
                );
            }

            $seen[$key] = true;
            $prices[] = $price;
        }

        return $prices;
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|array{window: string, window_size: int, unit: string, cap: float|int, mode: string, enabled: bool}
     */
    private function parseLimit(array $row, int $index): JsonResponse|array
    {
        $window = $this->parseLimitWindow($row, $index);
        if ($window instanceof JsonResponse) {
            return $window;
        }

        $unit = $this->parseLimitUnit($row, $index);
        if ($unit instanceof JsonResponse) {
            return $unit;
        }

        $mode = $this->parseLimitMode($row, $index);
        if ($mode instanceof JsonResponse) {
            return $mode;
        }

        $size = $this->parseWindowSize($row, $index, $window);
        if ($size instanceof JsonResponse) {
            return $size;
        }

        $cap = $this->parseLimitCap($row, $index, $unit);
        if ($cap instanceof JsonResponse) {
            return $cap;
        }

        $enabled = $this->parseLimitEnabled($row, $index);
        if ($enabled instanceof JsonResponse) {
            return $enabled;
        }

        return [
            'window' => $window,
            'window_size' => $size,
            'unit' => $unit,
            'cap' => $cap,
            'mode' => $mode,
            'enabled' => $enabled,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|string
     */
    private function parseLimitWindow(array $row, int $index): JsonResponse|string
    {
        $window = $row['window'] ?? null;
        if (!is_string($window)) {
            return $this->error('BAD_REQUEST', "limits[{$index}].window must be a string.", Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($window, self::LIMIT_WINDOWS, true)) {
            return $this->error(
                'INVALID_WINDOW',
                "limits[{$index}].window must be one of: hour, day, week, month, rolling, total.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $window;
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|string
     */
    private function parseLimitUnit(array $row, int $index): JsonResponse|string
    {
        $unit = $row['unit'] ?? null;
        if (!is_string($unit)) {
            return $this->error('BAD_REQUEST', "limits[{$index}].unit must be a string.", Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($unit, self::LIMIT_UNITS, true)) {
            return $this->error(
                'INVALID_UNIT',
                "limits[{$index}].unit must be one of: " . implode(', ', self::LIMIT_UNITS) . '.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $unit;
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|string
     */
    private function parseLimitMode(array $row, int $index): JsonResponse|string
    {
        $mode = $row['mode'] ?? 'hard';
        if (!is_string($mode)) {
            return $this->error('BAD_REQUEST', "limits[{$index}].mode must be a string.", Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($mode, self::LIMIT_MODES, true)) {
            return $this->error(
                'INVALID_MODE',
                "limits[{$index}].mode must be one of: " . implode(', ', self::LIMIT_MODES) . '.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $mode;
    }

    /**
     * The hour count of a limit's window.
     *
     * Only the rolling window has one; the named windows are one hour wide
     * by definition, so a payload that says otherwise names a window the
     * domain does not have.
     *
     * @param array<string, mixed> $row
     * @return JsonResponse|int
     */
    private function parseWindowSize(array $row, int $index, string $window): JsonResponse|int
    {
        $size = $row['window_size'] ?? null;

        if ($size === null) {
            return $window === WindowCalculator::WINDOW_ROLLING
                ? $this->error(
                    'INVALID_WINDOW_SIZE',
                    "limits[{$index}].window_size is required for the rolling window.",
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                )
                : 1;
        }

        if (!is_int($size)) {
            return $this->error(
                'BAD_REQUEST',
                "limits[{$index}].window_size must be an integer.",
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($size < 1) {
            return $this->error(
                'INVALID_WINDOW_SIZE',
                "limits[{$index}].window_size must be at least 1.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($window !== WindowCalculator::WINDOW_ROLLING && $size !== 1) {
            return $this->error(
                'INVALID_WINDOW_SIZE',
                "limits[{$index}].window_size must be 1 for the named windows.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $size;
    }

    /**
     * A credit cap is a decimal; a count cap is a whole number.
     *
     * @param array<string, mixed> $row
     * @return JsonResponse|float|int
     */
    private function parseLimitCap(array $row, int $index, string $unit): JsonResponse|float|int
    {
        $cap = $row['cap'] ?? null;
        if (!is_int($cap) && !is_float($cap)) {
            return $this->error('BAD_REQUEST', "limits[{$index}].cap must be a number.", Response::HTTP_BAD_REQUEST);
        }
        if ((float) $cap < 0.0) {
            return $this->error(
                'INVALID_CAP',
                "limits[{$index}].cap must not be negative.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($unit === UsageLimit::UNIT_CREDITS) {
            return round((float) $cap, 8);
        }

        if (floor((float) $cap) !== (float) $cap) {
            return $this->error(
                'INVALID_CAP',
                "limits[{$index}].cap must be a whole number for the {$unit} unit.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return (int) $cap;
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|bool
     */
    private function parseLimitEnabled(array $row, int $index): JsonResponse|bool
    {
        $enabled = $row['enabled'] ?? true;
        if (!is_bool($enabled)) {
            return $this->error(
                'BAD_REQUEST',
                "limits[{$index}].enabled must be a boolean.",
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $enabled;
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|array<string, mixed>
     */
    private function parsePrice(array $row, int $index): JsonResponse|array
    {
        $kind = $this->parsePriceKind($row, $index);
        if ($kind instanceof JsonResponse) {
            return $kind;
        }

        $key = $this->parsePriceKey($row, $index);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $provider = $this->parsePriceText($row, $index, 'provider');
        if ($provider instanceof JsonResponse) {
            return $provider;
        }

        $operation = $this->parsePriceText($row, $index, 'operation');
        if ($operation instanceof JsonResponse) {
            return $operation;
        }

        $refusal = $this->refuseForeignHalf($kind, $provider, $operation, $index);
        if ($refusal instanceof JsonResponse) {
            return $refusal;
        }

        $amounts = $this->parsePriceAmounts($row, $index);
        if ($amounts instanceof JsonResponse) {
            return $amounts;
        }

        return [
            'kind' => $kind,
            'provider' => $provider,
            'key' => $key,
            'operation' => $operation,
        ] + $amounts;
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|string
     */
    private function parsePriceKind(array $row, int $index): JsonResponse|string
    {
        $kind = $row['kind'] ?? null;
        if (!is_string($kind)) {
            return $this->error('BAD_REQUEST', "prices[{$index}].kind must be a string.", Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($kind, self::PRICE_KINDS, true)) {
            return $this->error(
                'INVALID_KIND',
                "prices[{$index}].kind must be one of: " . implode(', ', self::PRICE_KINDS) . '.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $kind;
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|string
     */
    private function parsePriceKey(array $row, int $index): JsonResponse|string
    {
        $key = $row['key'] ?? null;
        if (!is_string($key)) {
            return $this->error('BAD_REQUEST', "prices[{$index}].key must be a string.", Response::HTTP_BAD_REQUEST);
        }
        if (trim($key) === '') {
            return $this->error(
                'INVALID_KEY',
                "prices[{$index}].key must not be empty.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return trim($key);
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|string
     */
    private function parsePriceText(array $row, int $index, string $field): JsonResponse|string
    {
        $value = $row[$field] ?? '';
        if (!is_string($value)) {
            return $this->error(
                'BAD_REQUEST',
                "prices[{$index}].{$field} must be a string.",
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $value;
    }

    /**
     * A provider prices a model and an operation prices a tool.
     *
     * A row that carries the other half would sit in the price book and
     * never match a lookup, which is the one outcome the operator cannot
     * see from the panel.
     */
    private function refuseForeignHalf(
        string $kind,
        string $provider,
        string $operation,
        int $index,
    ): ?JsonResponse {
        if ($kind === UsagePrice::KIND_TOOL && $provider !== '') {
            return $this->error(
                'INVALID_PROVIDER',
                "prices[{$index}].provider must be empty for a tool price.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($kind === UsagePrice::KIND_MODEL && $operation !== '') {
            return $this->error(
                'INVALID_OPERATION',
                "prices[{$index}].operation must be empty for a model price.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return JsonResponse|array<string, float>
     */
    private function parsePriceAmounts(array $row, int $index): JsonResponse|array
    {
        $amounts = [];

        foreach (self::PRICE_FIELDS as $field) {
            $value = $row[$field] ?? 0;
            if (!is_int($value) && !is_float($value)) {
                return $this->error(
                    'BAD_REQUEST',
                    "prices[{$index}].{$field} must be a number.",
                    Response::HTTP_BAD_REQUEST,
                );
            }
            if ((float) $value < 0.0) {
                return $this->error(
                    'INVALID_PRICE',
                    "prices[{$index}].{$field} must not be negative.",
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $amounts[$field] = round((float) $value, 8);
        }

        return $amounts;
    }
}
