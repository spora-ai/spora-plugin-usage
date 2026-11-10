<?php

declare(strict_types=1);

use Delight\Auth\Auth;
use Delight\Auth\Role;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Auth\AuthService;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Core\Router;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Exceptions\UnauthenticatedException;
use Spora\Plugins\Usage\UsagePlugin;
use Spora\Security\CsrfTokenService;
use Spora\Services\PrincipalResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

it('reports the spend over a window as a series', function (): void {
    [$router, $auth] = usageApi();

    $userId = bootAuth($auth, 'owner@example.com');
    $principalId = createUserPrincipal($userId);
    $groupPrincipalId = createGroupPrincipal($userId, 'Usage Test Group');

    $now = new DateTimeImmutable();
    $hour = $now->setTime((int) $now->format('H'), 0, 0);

    Capsule::table('usage_buckets')->insert([
        'owner_type'    => 'principal',
        'owner_id'      => $principalId,
        'bucket_start'  => $hour->format('Y-m-d H:i:s'),
        'credits'       => 4.5,
        'total_tokens'  => 300,
        'output_tokens' => 40,
        'llm_calls'     => 2,
        'tool_calls'    => 1,
    ]);
    Capsule::table('usage_buckets')->insert([
        'owner_type'   => 'principal',
        'owner_id'     => $principalId,
        'bucket_start' => $hour->modify('-2 hours')->format('Y-m-d H:i:s'),
        'credits'      => 1.0,
        'llm_calls'    => 1,
    ]);
    // The group shares the hour, so the series has to merge the two owners
    // into one line of spend rather than reporting one line each.
    Capsule::table('usage_buckets')->insert([
        'owner_type'   => 'principal',
        'owner_id'     => $groupPrincipalId,
        'bucket_start' => $hour->format('Y-m-d H:i:s'),
        'credits'      => 2.0,
        'llm_calls'    => 1,
    ]);

    $response = usageGet($router, '/api/v1/usage/me/series?window=day');

    expect($response->getStatusCode())->toBe(200);

    $body = usageBody($response);
    expect($body['data']['window'])->toBe('day');

    $points = $body['data']['points'] ?? [];
    expect($points)->toBeArray();
    expect((float) array_sum(array_column($points, 'credits')))->toEqual(7.5);
});

it('refuses a series window the panel cannot draw', function (): void {
    [$router, $auth] = usageApi();

    bootAuth($auth, 'owner@example.com');

    $response = usageGet($router, '/api/v1/usage/me/series?window=fortnight');

    expect($response->getStatusCode())->toBe(400);
    expect(usageBody($response)['error']['code'])->toBe('BAD_REQUEST');
});

it('refuses a non-admin caller on an admin route', function (): void {
    // The admin tabs of the Usage app hide themselves for a non-admin, but
    // the API is the gate that decides: without `AdminMiddleware` on the
    // route, hiding a tab is the only protection there is.
    [$router, $auth] = usageApi();

    bootAuth($auth, 'plain@example.com');

    $response = usageGet($router, '/api/v1/usage/principals');

    expect($response->getStatusCode())->toBe(403);
    expect(usageBody($response)['error']['code'])->toBe('FORBIDDEN');
});

it('refuses an unauthenticated caller on an admin route', function (): void {
    [$router] = usageApi();

    clearSession();

    $response = usageGet($router, '/api/v1/usage/principals');

    expect($response->getStatusCode())->toBe(401);
    expect(usageBody($response)['error']['code'])->toBe('UNAUTHENTICATED');
});

it('answers for the callers visible principals and not for somebody elses', function (): void {
    [$router, $auth] = usageApi();

    $userId = bootAuth($auth, 'owner@example.com');
    $ownPrincipalId = createUserPrincipal($userId);
    $groupPrincipalId = createGroupPrincipal($userId, 'Usage Test Group');

    $strangerId = bootAuth(usageApiAuthService(), 'stranger@example.com');
    $strangerPrincipalId = createUserPrincipal($strangerId);

    simulateLoggedInSession($userId, 'owner@example.com');

    Capsule::table('usage_credits')->insert([
        'owner_type' => 'principal',
        'owner_id'   => $ownPrincipalId,
        'balance'    => 75,
    ]);
    Capsule::table('usage_limits')->insert([
        'owner_type'  => 'principal',
        'owner_id'    => $ownPrincipalId,
        'window'      => 'day',
        'window_size' => 1,
        'unit'        => 'credits',
        'cap'         => 100,
        'mode'        => 'hard',
        'enabled'     => true,
    ]);

    $response = usageGet($router, '/api/v1/usage/me');

    expect($response->getStatusCode())->toBe(200);
    $ids = usagePrincipalIds($response);

    expect($ids)->toContain($ownPrincipalId);
    expect($ids)->toContain($groupPrincipalId);
    expect(in_array($strangerPrincipalId, $ids, true))->toBeFalse();

    $own = usagePrincipal($response, $ownPrincipalId);
    expect($own['balance'])->toEqual(75.0);
    expect($own['limits'][0]['remaining'])->toEqual(100.0);
    expect($own['limits'][0]['spend'])->toEqual(0.0);
});

it('replaces the limits of a principal and deletes an absent one', function (): void {
    // The payload is the whole set: a limit the operator removed from the
    // screen has to disappear from the table too, or the cap keeps blocking
    // a run the operator believes is uncapped.
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    $created = usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/limits", [
        'limits' => [
            ['window' => 'day', 'unit' => 'credits', 'cap' => 100, 'mode' => 'hard'],
            ['window' => 'week', 'unit' => 'llm_calls', 'cap' => 50, 'mode' => 'soft'],
        ],
    ]);

    expect($created->getStatusCode())->toBe(200);
    expect(usageLimitRows($principalId))->toHaveCount(2);

    $replaced = usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/limits", [
        'limits' => [
            ['window' => 'day', 'unit' => 'credits', 'cap' => 25, 'mode' => 'hard'],
        ],
    ]);

    expect($replaced->getStatusCode())->toBe(200);

    $rows = usageLimitRows($principalId);
    expect($rows)->toHaveCount(1);
    expect((string) $rows[0]->window)->toBe('day');
    expect((string) $rows[0]->unit)->toBe('credits');
});

it('grants credits to a principal and records the grant', function (): void {
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    $response = usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/credits", [
        'amount' => 250,
        'note' => 'Quarterly top-up',
    ]);

    expect($response->getStatusCode())->toBe(200);
    expect(usageBody($response)['data']['balance'])->toEqual(250.0);

    $grants = Capsule::table('usage_credit_grants')
        ->where('owner_type', 'principal')
        ->where('owner_id', $principalId)
        ->get();

    expect($grants)->toHaveCount(1);
    expect((float) $grants->first()->amount)->toEqual(250.0);
    expect((string) $grants->first()->note)->toBe('Quarterly top-up');

    $balance = Capsule::table('usage_credits')
        ->where('owner_type', 'principal')
        ->where('owner_id', $principalId)
        ->value('balance');

    expect((float) $balance)->toEqual(250.0);
});

it('subtracts from the balance when the grant is negative', function (): void {
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/credits", ['amount' => 100]);
    $response = usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/credits", ['amount' => -30]);

    expect($response->getStatusCode())->toBe(200);
    expect(usageBody($response)['data']['balance'])->toEqual(70.0);
});

it('lists the models that were metered without a price row', function (): void {
    // An unpriced model costs nothing, so it is invisible in every total.
    // The ledger's `priced` column is the only record that it was used at
    // all, which is what makes the operator able to see the free work.
    [$router, $auth] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    Capsule::table('usage_ledger')->insert([
        'principal_id'  => $principalId,
        'principal_type' => 'user',
        'kind'          => 'llm',
        'provider'      => 'openai_compatible',
        'model'         => 'mystery-model',
        'input_tokens'  => 120,
        'output_tokens' => 30,
        'credits'       => 0,
        'priced'        => false,
        'occurred_at'   => date('Y-m-d H:i:s'),
    ]);

    Capsule::table('usage_ledger')->insert([
        'principal_id'  => $principalId,
        'principal_type' => 'user',
        'kind'          => 'llm',
        'provider'      => 'openai_compatible',
        'model'         => 'priced-model',
        'credits'       => 1.5,
        'priced'        => true,
        'occurred_at'   => date('Y-m-d H:i:s'),
    ]);

    Capsule::table('usage_ledger')->insert([
        'principal_id'  => $principalId,
        'principal_type' => 'user',
        'kind'          => 'tool',
        'tool_name'     => 'media',
        'tool_class'    => 'Spora\\Tools\\MediaTool',
        'operation'     => 'render',
        'credits'       => 0,
        'priced'        => false,
        'occurred_at'   => date('Y-m-d H:i:s'),
    ]);

    $response = usageGet($router, '/api/v1/usage/unpriced');

    expect($response->getStatusCode())->toBe(200);

    $models = usageBody($response)['data']['models'] ?? [];
    expect($models)->toBeArray();

    $named = array_column($models, 'model');
    expect($named)->toContain('mystery-model');
    expect(in_array('priced-model', $named, true))->toBeFalse();

    expect(array_keys($models[0]))->toEqualCanonicalizing(['provider', 'model', 'calls']);
    expect($models[0]['calls'])->toBe(1);

    $tools = usageBody($response)['data']['tools'] ?? [];
    expect($tools)->toBeArray();
    expect(array_keys($tools[0]))->toEqualCanonicalizing(['tool_class', 'tool_name', 'calls']);
    expect($tools[0]['tool_name'])->toBe('media');
    expect($tools[0]['calls'])->toBe(1);
});

it('lists the calls that had a price and were not billed', function (): void {
    // The principal ran these with their own configuration, so the
    // installation did not pay for them. The price book had a row — the
    // call was free for a different reason than an unpriced one.
    [$router, $auth] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    Capsule::table('usage_ledger')->insert([
        'principal_id'  => $principalId,
        'principal_type' => 'user',
        'kind'          => 'llm',
        'provider'      => 'openai_compatible',
        'model'         => 'their-own-model',
        'input_tokens'  => 120,
        'output_tokens' => 30,
        'credits'       => 0,
        'priced'        => true,
        'billed'        => false,
        'occurred_at'   => date('Y-m-d H:i:s'),
    ]);

    Capsule::table('usage_ledger')->insert([
        'principal_id'  => $principalId,
        'principal_type' => 'user',
        'kind'          => 'tool',
        'tool_name'     => 'weather',
        'tool_class'    => 'Spora\\Tools\\WeatherTool',
        'operation'     => 'forecast',
        'credits'       => 0,
        'priced'        => true,
        'billed'        => false,
        'occurred_at'   => date('Y-m-d H:i:s'),
    ]);

    Capsule::table('usage_ledger')->insert([
        'principal_id'  => $principalId,
        'principal_type' => 'user',
        'kind'          => 'llm',
        'provider'      => 'openai_compatible',
        'model'         => 'the-installations-model',
        'credits'       => 1.5,
        'priced'        => true,
        'billed'        => true,
        'occurred_at'   => date('Y-m-d H:i:s'),
    ]);

    $response = usageGet($router, '/api/v1/usage/unpriced');

    expect($response->getStatusCode())->toBe(200);

    $notBilled = usageBody($response)['data']['not_billed'] ?? [];
    expect($notBilled)->toBeArray();

    $models = $notBilled['models'] ?? [];
    expect(array_column($models, 'model'))->toContain('their-own-model');
    expect(array_column($models, 'model'))->not->toContain('the-installations-model');
    expect(array_keys($models[0]))->toEqualCanonicalizing(['provider', 'model', 'calls', 'tokens']);
    expect($models[0]['calls'])->toBe(1);
    expect($models[0]['tokens'])->toBe(150);

    $tools = $notBilled['tools'] ?? [];
    expect(array_column($tools, 'tool_name'))->toContain('weather');
    expect(array_keys($tools[0]))->toEqualCanonicalizing(['tool_class', 'tool_name', 'calls']);
    expect($tools[0]['calls'])->toBe(1);

    // The unpriced report keeps its own rows to itself.
    $unpriced = usageBody($response)['data']['models'] ?? [];
    expect(array_column($unpriced, 'model'))->not->toContain('their-own-model');
});

it('shows the caller the limits that govern them, and where they came from', function (): void {
    [$router, $auth] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    Capsule::table('usage_default_limits')->insert([
        'scope'       => 'user',
        'window'      => 'month',
        'window_size' => 1,
        'unit'        => 'credits',
        'cap'         => 1000,
        'mode'        => 'hard',
        'enabled'     => true,
    ]);

    $response = usageGet($router, '/api/v1/usage/me');

    $principal = usageBody($response)['data']['principals'][0] ?? [];

    // The principal has no limits of their own, so the default governs
    // them — and the row says so rather than claiming to be theirs.
    expect($principal['limits'])->toHaveCount(1);
    expect($principal['limits'][0]['cap'])->toBe(1000);
    expect($principal['limits'][0]['source'])->toBe('default');
});

it('reports the general default when the type default has nothing switched on', function (): void {
    // The gate falls back to the general default when the type's set has
    // nothing switched on, so the summary has to name the same set: the
    // two answers must not differ.
    [$router, $auth] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);

    $groupPrincipalId = createGroupPrincipal($adminId);

    Capsule::table('usage_default_limits')->insert([
        'scope'       => 'group',
        'window'      => 'day',
        'window_size' => 1,
        'unit'        => 'credits',
        'cap'         => 1,
        'mode'        => 'hard',
        'enabled'     => false,
    ]);

    Capsule::table('usage_default_limits')->insert([
        'scope'       => 'all',
        'window'      => 'month',
        'window_size' => 1,
        'unit'        => 'credits',
        'cap'         => 1000,
        'mode'        => 'hard',
        'enabled'     => true,
    ]);

    $response = usageGet($router, '/api/v1/usage/principals');

    $principals = usageBody($response)['data']['principals'] ?? [];
    $group = null;
    foreach ($principals as $principal) {
        if ($principal['id'] === $groupPrincipalId) {
            $group = $principal;
        }
    }

    expect($group)->not->toBeNull();
    expect($group['limits'])->toHaveCount(1);
    expect($group['limits'][0]['cap'])->toBe(1000);
    expect($group['limits'][0]['source'])->toBe('default');
});

it('shows the caller their own limits when they have them', function (): void {
    [$router, $auth] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    Capsule::table('usage_default_limits')->insert([
        'scope'       => 'user',
        'window'      => 'month',
        'window_size' => 1,
        'unit'        => 'credits',
        'cap'         => 1000,
        'mode'        => 'hard',
        'enabled'     => true,
    ]);

    Capsule::table('usage_limits')->insert([
        'owner_type'  => 'principal',
        'owner_id'    => $principalId,
        'window'      => 'day',
        'window_size' => 1,
        'unit'        => 'credits',
        'cap'         => 100,
        'mode'        => 'hard',
        'enabled'     => true,
    ]);

    $response = usageGet($router, '/api/v1/usage/me');

    $principal = usageBody($response)['data']['principals'][0] ?? [];

    expect($principal['limits'])->toHaveCount(1);
    expect($principal['limits'][0]['cap'])->toBe(100);
    expect($principal['limits'][0]['source'])->toBe('principal');
});

it('reads the default limit sets', function (): void {
    [$router, $auth] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);

    Capsule::table('usage_default_limits')->insert([
        'scope' => 'all',
        'window' => 'month',
        'window_size' => 1,
        'unit' => 'credits',
        'cap' => 1000,
        'mode' => 'hard',
        'enabled' => true,
    ]);

    $response = usageGet($router, '/api/v1/usage/defaults');

    expect($response->getStatusCode())->toBe(200);

    $defaults = usageBody($response)['data']['defaults'] ?? [];
    expect(array_keys($defaults))->toEqualCanonicalizing(['all', 'user', 'group']);
    expect($defaults['all'])->toHaveCount(1);
    expect($defaults['all'][0]['window'])->toBe('month');
    expect($defaults['all'][0]['cap'])->toBe(1000);
    expect($defaults['user'])->toBe([]);
});

it('replaces the default limit sets that are in the payload', function (): void {
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);

    $created = usagePut($router, $csrf, '/api/v1/usage/defaults', [
        'defaults' => [
            'all' => [
                ['window' => 'month', 'unit' => 'credits', 'cap' => 1000],
            ],
            'group' => [
                ['window' => 'day', 'unit' => 'llm_calls', 'cap' => 500],
            ],
        ],
    ]);

    expect($created->getStatusCode())->toBe(200);

    $defaults = usageBody($created)['data']['defaults'] ?? [];
    expect($defaults['all'])->toHaveCount(1);
    expect($defaults['group'])->toHaveCount(1);
    expect($defaults['user'])->toBe([]);

    // A scope that is not in the payload is left as it is: a payload that
    // names one scope must not clear the other two.
    $replaced = usagePut($router, $csrf, '/api/v1/usage/defaults', [
        'defaults' => [
            'user' => [
                ['window' => 'day', 'unit' => 'credits', 'cap' => 50],
            ],
        ],
    ]);

    expect($replaced->getStatusCode())->toBe(200);

    $defaults = usageBody($replaced)['data']['defaults'] ?? [];
    expect($defaults['all'])->toHaveCount(1);
    expect($defaults['group'])->toHaveCount(1);
    expect($defaults['user'])->toHaveCount(1);
    expect($defaults['user'][0]['cap'])->toBe(50);
});

it('refuses a default limit set that is not one', function (): void {
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);

    $unknownScope = usagePut($router, $csrf, '/api/v1/usage/defaults', [
        'defaults' => [
            'nobody' => [],
        ],
    ]);

    expect($unknownScope->getStatusCode())->toBe(400);

    $notAList = usagePut($router, $csrf, '/api/v1/usage/defaults', [
        'defaults' => [
            'user' => ['window' => 'day'],
        ],
    ]);

    expect($notAList->getStatusCode())->toBe(400);

    $notAWindow = usagePut($router, $csrf, '/api/v1/usage/defaults', [
        'defaults' => [
            'user' => [['window' => 'fortnight', 'unit' => 'credits', 'cap' => 10]],
        ],
    ]);

    expect($notAWindow->getStatusCode())->toBe(422);

    // The refusal names the path it was reading, so the operator knows
    // which set is wrong.
    expect(usageBody($notAWindow)['error']['message'] ?? '')
        ->toContain('defaults.user[0].window');
});

it('replaces the price book with the given rows', function (): void {
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);

    $created = usagePut($router, $csrf, '/api/v1/usage/prices', [
        'prices' => [
            [
                'kind' => 'model',
                'provider' => 'openai_compatible',
                'key' => 'gpt-priced',
                'input_price' => 0.5,
                'output_price' => 1.5,
            ],
            [
                'kind' => 'tool',
                'key' => 'Spora\\Tools\\EchoTool',
                'call_price' => 2,
            ],
        ],
    ]);

    expect($created->getStatusCode())->toBe(200);
    expect(Capsule::table('usage_prices')->get())->toHaveCount(2);

    // The payload is the whole book: a row that is absent from it is gone.
    $replaced = usagePut($router, $csrf, '/api/v1/usage/prices', [
        'prices' => [
            [
                'kind' => 'model',
                'provider' => 'openai_compatible',
                'key' => 'gpt-priced',
                'input_price' => 0.25,
                'output_price' => 0.75,
            ],
        ],
    ]);

    expect($replaced->getStatusCode())->toBe(200);

    $rows = Capsule::table('usage_prices')->get();
    expect($rows)->toHaveCount(1);
    expect((string) $rows->first()->key)->toBe('gpt-priced');
    expect((float) $rows->first()->input_price)->toEqual(0.25);
});

it('refuses a limit whose window is not a window', function (): void {
    // A malformed value is a 400; a value the server reads and will not
    // store is a 422. A window outside the vocabulary is the second kind.
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    $response = usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/limits", [
        'limits' => [
            ['window' => 'fortnight', 'unit' => 'credits', 'cap' => 10],
        ],
    ]);

    expect($response->getStatusCode())->toBe(422);
    expect(usageBody($response)['error']['code'])->toBe('INVALID_WINDOW');
});

it('refuses a grant of nothing', function (): void {
    // A grant of zero is a value the server reads and will not store, so it
    // is a 422 rather than a broken request.
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    $response = usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/credits", ['amount' => 0]);

    expect($response->getStatusCode())->toBe(422);
    expect(usageBody($response)['error']['code'])->toBe('INVALID_AMOUNT');
});

it('refuses a price book that is not a list', function (): void {
    // A price book that is not a list is a malformed body, so it is a 400.
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);

    $response = usagePut($router, $csrf, '/api/v1/usage/prices', ['prices' => 'none']);

    expect($response->getStatusCode())->toBe(400);
    expect(usageBody($response)['error']['code'])->toBe('BAD_REQUEST');
});

it('answers 404 for a principal that does not exist', function (): void {
    [$router, $auth] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);

    $response = usageGet($router, '/api/v1/usage/principals/999999');

    expect($response->getStatusCode())->toBe(404);
    expect(usageBody($response)['error']['code'])->toBe('NOT_FOUND');
});

it('names the callers own overview the way the contract names it', function (): void {
    // The key names are the contract: the app reads `balance` and `spend`,
    // and a name that drifts back to the old one is a number the panel
    // cannot read.
    [$router, $auth] = usageApi();

    $userId = bootAuth($auth, 'owner@example.com');
    $principalId = createUserPrincipal($userId);

    Capsule::table('usage_limits')->insert([
        'owner_type'  => 'principal',
        'owner_id'    => $principalId,
        'window'      => 'day',
        'window_size' => 1,
        'unit'        => 'credits',
        'cap'         => 100,
        'mode'        => 'hard',
        'enabled'     => true,
    ]);

    $response = usageGet($router, '/api/v1/usage/me');

    expect($response->getStatusCode())->toBe(200);

    $body = usageBody($response);
    expect(array_keys($body['data']))->toEqualCanonicalizing(['principals']);

    $principal = $body['data']['principals'][0];
    expect(array_keys($principal))->toEqualCanonicalizing([
        'id', 'type', 'name', 'balance', 'spend_30d', 'limits',
    ]);
    expect(array_keys($principal['limits'][0]))->toEqualCanonicalizing([
        'window', 'window_size', 'unit', 'cap', 'mode', 'enabled', 'source', 'spend', 'remaining',
    ]);
});

it('names the series the way the contract names it', function (): void {
    // The point of a series is its order, so the timestamp has to say which
    // instant a slot is: an ISO-8601 one, oldest point first.
    [$router, $auth] = usageApi();

    $userId = bootAuth($auth, 'owner@example.com');
    $principalId = createUserPrincipal($userId);

    $now = new DateTimeImmutable();
    $hour = $now->setTime((int) $now->format('H'), 0, 0);

    Capsule::table('usage_buckets')->insert([
        'owner_type'    => 'principal',
        'owner_id'      => $principalId,
        'bucket_start'  => $hour->format('Y-m-d H:i:s'),
        'credits'       => 4.5,
        'total_tokens'  => 300,
        'output_tokens' => 40,
        'llm_calls'     => 2,
        'tool_calls'    => 1,
    ]);

    $response = usageGet($router, '/api/v1/usage/me/series?window=day');

    expect($response->getStatusCode())->toBe(200);

    $body = usageBody($response);
    expect(array_keys($body['data']))->toEqualCanonicalizing(['window', 'points']);

    $points = $body['data']['points'];
    expect(array_keys($points[0]))->toEqualCanonicalizing([
        'at', 'credits', 'total_tokens', 'output_tokens', 'llm_calls', 'tool_calls',
    ]);
    expect($points[0]['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    expect(DateTimeImmutable::createFromFormat(DATE_ATOM, $points[0]['at']))->not->toBeFalse();

    $times = array_column($points, 'at');
    $ordered = $times;
    sort($ordered);
    expect($times)->toBe($ordered);
});

it('names the operator listing and the principal detail the way the contract names them', function (): void {
    [$router, $auth] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    $listing = usageGet($router, '/api/v1/usage/principals');
    expect($listing->getStatusCode())->toBe(200);

    $listed = usagePrincipal($listing, $principalId);
    expect(array_keys($listed))->toEqualCanonicalizing([
        'id', 'type', 'name', 'balance', 'spend_30d', 'limits',
    ]);

    $detail = usageGet($router, "/api/v1/usage/principals/{$principalId}");
    expect($detail->getStatusCode())->toBe(200);

    $data = usageBody($detail)['data'];
    expect(array_keys($data))->toEqualCanonicalizing(['principal', 'balance', 'limits', 'spend']);
    expect(array_keys($data['principal']))->toEqualCanonicalizing(['id', 'type', 'name']);
    expect(array_keys($data['spend']))->toEqualCanonicalizing(['credits_30d', 'total_tokens_30d']);
});

it('names the limit save and the grant the way the contract names them', function (): void {
    [$router, $auth, $csrf] = usageApi();

    $adminId = bootAuth($auth, 'admin@example.com');
    $auth->grantRole($adminId, Role::ADMIN);
    $principalId = createUserPrincipal($adminId);

    $limits = usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/limits", [
        'limits' => [
            ['window' => 'day', 'unit' => 'credits', 'cap' => 100, 'mode' => 'hard'],
        ],
    ]);

    expect($limits->getStatusCode())->toBe(200);

    $data = usageBody($limits)['data'];
    expect(array_keys($data))->toEqualCanonicalizing(['limits']);
    expect(array_keys($data['limits'][0]))->toEqualCanonicalizing([
        'window', 'window_size', 'unit', 'cap', 'mode', 'enabled', 'source', 'spend', 'remaining',
    ]);

    $grant = usagePut($router, $csrf, "/api/v1/usage/principals/{$principalId}/credits", [
        'amount' => 100,
    ]);

    expect($grant->getStatusCode())->toBe(200);
    expect(array_keys(usageBody($grant)['data']))->toEqualCanonicalizing(['balance']);
});

/**
 * The plugin's routes behind the plugin's middleware, in a real container.
 *
 * @return array{0: Router, 1: AuthService, 2: CsrfTokenService}
 */
function usageApi(): array
{
    $auth = usageApiAuthService();
    $csrf = new CsrfTokenService();

    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->addDefinitions([
        AuthService::class => \DI\factory(function () use ($auth): AuthService {
            return $auth;
        }),
        CsrfTokenService::class => \DI\factory(function () use ($csrf): CsrfTokenService {
            return $csrf;
        }),
        LoggerInterface::class => \DI\factory(static fn(): LoggerInterface => new NullLogger()),
        PrincipalResolver::class => \DI\factory(static fn(): PrincipalResolver => new PrincipalResolver()),
    ]);

    $plugin = new UsagePlugin();
    $dispatcher = new Symfony\Component\EventDispatcher\EventDispatcher();
    $dispatcher->addSubscriber($plugin);
    $dispatcher->dispatch(new ContainerBuildingEvent($builder));

    $container = $builder->build();

    $router = new Router($container, static function (MiddlewareRouteCollector $routes) use ($plugin): void {
        $plugin->onRoutesRegistering(new RoutesRegisteringEvent($routes));
    });

    return [$router, $auth, $csrf];
}

/**
 * An {@see AuthService} over the booted connection.
 *
 * `Delight\Auth\Auth` declares implicitly nullable parameters, which PHP
 * deprecates when the class is loaded. The filter keeps that notice out of
 * the report.
 */
function usageApiAuthService(): AuthService
{
    $auth = withDelightDeprecationsFiltered(
        static fn(): Auth => new Auth(Capsule::connection()->getPdo(), null, null, false),
    );

    return new AuthService($auth);
}

/**
 * Dispatch a request and answer the way the host does.
 *
 * `AuthMiddleware` refuses an anonymous caller by throwing
 * `UnauthenticatedException`; the Kernel maps that to a 401. The router
 * alone does not, so the mapping is done here — the assertion is on the
 * status the caller sees.
 */
function usageDispatch(Router $router, Request $request): Response
{
    try {
        return $router->dispatch($request);
    } catch (UnauthenticatedException) {
        return new JsonResponse(
            ['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.']],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}

function usageGet(Router $router, string $uri): Response
{
    return usageDispatch($router, Request::create($uri, 'GET'));
}

function usagePut(Router $router, CsrfTokenService $csrf, string $uri, array $body): Response
{
    $request = Request::create(
        $uri,
        'PUT',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        (string) json_encode($body),
    );
    $request->headers->set('X-CSRF-Token', $csrf->getOrCreateToken());

    return usageDispatch($router, $request);
}

/**
 * @return array<string, mixed>
 */
function usageBody(Response $response): array
{
    $decoded = json_decode((string) $response->getContent(), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * The principals the answer named.
 *
 * @return list<int>
 */
function usagePrincipalIds(Response $response): array
{
    $principals = usageBody($response)['data']['principals'] ?? [];

    if (!is_array($principals)) {
        return [];
    }

    $ids = [];
    foreach ($principals as $principal) {
        if (is_array($principal)) {
            $ids[] = (int) ($principal['id'] ?? 0);
        }
    }

    return $ids;
}

/**
 * The principal the answer named.
 *
 * @return array<string, mixed>
 */
function usagePrincipal(Response $response, int $principalId): array
{
    $principals = usageBody($response)['data']['principals'] ?? [];

    if (!is_array($principals)) {
        return [];
    }

    foreach ($principals as $principal) {
        if (is_array($principal) && (int) ($principal['id'] ?? 0) === $principalId) {
            return $principal;
        }
    }

    return [];
}

/**
 * The limit rows stored for a principal.
 *
 * @return list<object>
 */
function usageLimitRows(int $principalId): array
{
    return Capsule::table('usage_limits')
        ->where('owner_type', 'principal')
        ->where('owner_id', $principalId)
        ->orderBy('id')
        ->get()
        ->all();
}
