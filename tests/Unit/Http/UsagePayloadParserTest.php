<?php

declare(strict_types=1);

use Spora\Plugins\Usage\Http\UsagePayloadParser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The refusal a payload earns, as the two facts a caller reads.
 *
 * @return array{status: int, code: string}
 */
function usageRefusal(JsonResponse $response): array
{
    $decoded = json_decode((string) $response->getContent(), true);
    $body = is_array($decoded) ? $decoded : [];

    return [
        'status' => $response->getStatusCode(),
        'code' => (string) ($body['error']['code'] ?? ''),
    ];
}

it('reads a whole set of limits', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [
            ['window' => 'day', 'unit' => 'credits', 'cap' => 100],
            ['window' => 'rolling', 'window_size' => 5, 'unit' => 'llm_calls', 'cap' => 50, 'mode' => 'soft', 'enabled' => false],
        ],
    ]));

    expect($parsed)->toBe([
        ['window' => 'day', 'window_size' => 1, 'unit' => 'credits', 'cap' => 100.0, 'mode' => 'hard', 'enabled' => true],
        ['window' => 'rolling', 'window_size' => 5, 'unit' => 'llm_calls', 'cap' => 50, 'mode' => 'soft', 'enabled' => false],
    ]);
});

it('refuses a limits payload that is not a list', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => ['window' => 'day'],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 400, 'code' => 'BAD_REQUEST']);
});

it('refuses a limit that is not an object', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => ['day'],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 400, 'code' => 'BAD_REQUEST']);
});

it('refuses a window the domain does not have', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [['window' => 'fortnight', 'unit' => 'credits', 'cap' => 10]],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_WINDOW']);
});

it('refuses a unit the domain does not have', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [['window' => 'day', 'unit' => 'widgets', 'cap' => 10]],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_UNIT']);
});

it('refuses a mode the domain does not have', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [['window' => 'day', 'unit' => 'credits', 'cap' => 10, 'mode' => 'fuzzy']],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_MODE']);
});

it('refuses two limits on the same window and unit', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [
            ['window' => 'day', 'unit' => 'credits', 'cap' => 10],
            ['window' => 'day', 'unit' => 'credits', 'cap' => 20],
        ],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 409, 'code' => 'CONFLICT']);
});

it('refuses a cap that is negative', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [['window' => 'day', 'unit' => 'credits', 'cap' => -1]],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_CAP']);
});

it('refuses a fractional cap on a counted unit', function (): void {
    // A credit cap is a decimal; a count cap is a whole number.
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [['window' => 'day', 'unit' => 'llm_calls', 'cap' => 1.5]],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_CAP']);
});

it('refuses an enabled flag that is not a boolean', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [['window' => 'day', 'unit' => 'credits', 'cap' => 10, 'enabled' => 'yes']],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 400, 'code' => 'BAD_REQUEST']);
});

it('refuses a rolling window without a window size', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [['window' => 'rolling', 'unit' => 'credits', 'cap' => 10]],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_WINDOW_SIZE']);
});

it('refuses a named window that claims a size of its own', function (): void {
    // The named windows are one hour wide by definition.
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseLimits(jsonRequest('PUT', '/api/v1/usage/principals/1/limits', [
        'limits' => [['window' => 'day', 'window_size' => 24, 'unit' => 'credits', 'cap' => 10]],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_WINDOW_SIZE']);
});

it('refuses a body that is not JSON', function (): void {
    $parser = new UsagePayloadParser();

    $request = Request::create(
        '/api/v1/usage/principals/1/limits',
        'PUT',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{not json',
    );

    $parsed = $parser->parseLimits($request);

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 400, 'code' => 'INVALID_JSON']);
});

it('reads a grant and trims its note', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseGrant(jsonRequest('PUT', '/api/v1/usage/principals/1/credits', [
        'amount' => 250,
        'note' => '  Quarterly top-up  ',
    ]));

    expect($parsed)->toBe(['amount' => 250.0, 'note' => 'Quarterly top-up']);
});

it('refuses a grant of nothing', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseGrant(jsonRequest('PUT', '/api/v1/usage/principals/1/credits', ['amount' => 0]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_AMOUNT']);
});

it('refuses an amount that is not a number', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseGrant(jsonRequest('PUT', '/api/v1/usage/principals/1/credits', ['amount' => 'lots']));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 400, 'code' => 'BAD_REQUEST']);
});

it('refuses a note that is not a string', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parseGrant(jsonRequest('PUT', '/api/v1/usage/principals/1/credits', [
        'amount' => 10,
        'note' => 42,
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 400, 'code' => 'BAD_REQUEST']);
});

it('reads a price book', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', [
        'prices' => [
            ['kind' => 'model', 'provider' => 'openai_compatible', 'key' => 'gpt-priced', 'input_price' => 0.5],
            ['kind' => 'tool', 'key' => 'Spora\\Tools\\EchoTool', 'call_price' => 2],
        ],
    ]));

    expect($parsed)->toBe([
        [
            'kind' => 'model',
            'provider' => 'openai_compatible',
            'key' => 'gpt-priced',
            'operation' => '',
            'input_price' => 0.5,
            'output_price' => 0.0,
            'cached_price' => 0.0,
            'call_price' => 0.0,
        ],
        [
            'kind' => 'tool',
            'provider' => '',
            'key' => 'Spora\\Tools\\EchoTool',
            'operation' => '',
            'input_price' => 0.0,
            'output_price' => 0.0,
            'cached_price' => 0.0,
            'call_price' => 2.0,
        ],
    ]);
});

it('refuses a price kind the domain does not have', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', [
        'prices' => [['kind' => 'subscription', 'key' => 'gpt-priced']],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_KIND']);
});

it('refuses a price without a key', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', [
        'prices' => [['kind' => 'model', 'provider' => 'openai_compatible', 'key' => '  ']],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_KEY']);
});

it('refuses a tool price that names a provider', function (): void {
    // A row that carries the other half would sit in the price book and
    // never match a lookup.
    $parser = new UsagePayloadParser();

    $parsed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', [
        'prices' => [['kind' => 'tool', 'provider' => 'openai_compatible', 'key' => 'gpt-priced']],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_PROVIDER']);
});

it('refuses a model price that names an operation', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', [
        'prices' => [['kind' => 'model', 'provider' => 'openai_compatible', 'key' => 'gpt-priced', 'operation' => 'chat']],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_OPERATION']);
});

it('refuses a price that is negative', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', [
        'prices' => [['kind' => 'model', 'provider' => 'openai_compatible', 'key' => 'gpt-priced', 'input_price' => -1]],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 422, 'code' => 'INVALID_PRICE']);
});

it('refuses two prices on the same key', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', [
        'prices' => [
            ['kind' => 'model', 'provider' => 'openai_compatible', 'key' => 'gpt-priced'],
            ['kind' => 'model', 'provider' => 'openai_compatible', 'key' => 'gpt-priced'],
        ],
    ]));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 409, 'code' => 'CONFLICT']);
});

it('refuses a price book that is not a list', function (): void {
    $parser = new UsagePayloadParser();

    $parsed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', ['prices' => 'none']));

    expect($parsed)->toBeInstanceOf(JsonResponse::class)
        ->and(usageRefusal($parsed))->toBe(['status' => 400, 'code' => 'BAD_REQUEST']);
});

it('answers a malformed body with the status the API promises', function (): void {
    // A malformed value is a 400 and a value the server reads but will not
    // store is a 422.
    $parser = new UsagePayloadParser();

    $malformed = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', ['prices' => 42]));
    $unreadable = $parser->parsePrices(jsonRequest('PUT', '/api/v1/usage/prices', [
        'prices' => [['kind' => 'model', 'provider' => 'openai_compatible', 'key' => 'gpt-priced', 'input_price' => -1]],
    ]));

    expect($malformed)->toBeInstanceOf(JsonResponse::class)
        ->and($unreadable)->toBeInstanceOf(JsonResponse::class)
        ->and($malformed->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST)
        ->and($unreadable->getStatusCode())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY);
});
