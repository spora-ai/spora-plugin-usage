<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Plugin-local test helpers and global Pest hooks. The host spora-core test
| suite defines the same `bootAuthLayer` / `bootAuth` / `jsonRequest` /
| `simulateLoggedInSession` / `clearSession` helpers; we redefine them here
| so the plugin's pest suite is self-contained.
|
*/

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

/**
 * Whether a diagnostic is one of the `delight-im` deprecations.
 *
 * `delight-im/auth` v9.0.0 declares implicitly nullable parameters, which
 * PHP 8.4+ deprecates. The upstream maintainer has acknowledged the issue
 * and defers the fix, so the vendor package stays as it is and the suite
 * filters the notices rather than patching it.
 *
 * The message is matched as well as the path: PHP blames the file that
 * *loads* a class for a parameter the class *declares*, so the vendor
 * directory alone never matches the diagnostic.
 */
function delightDeprecation(int $errno, string $errstr, string $errfile): bool
{
    return $errno === E_DEPRECATED
        && (
            str_contains($errfile, \DIRECTORY_SEPARATOR . 'delight-im' . \DIRECTORY_SEPARATOR)
            || str_contains($errstr, 'Implicitly marking parameter')
        );
}

set_error_handler('delightDeprecation', E_DEPRECATED);

/**
 * Run a callback with the `delight-im` deprecations filtered out.
 *
 * PHPUnit installs its own error handler above the one this file
 * registers, and it records whatever it sees before it forwards — so a
 * deprecation only stays out of the report while this filter is the
 * *active* handler, which means around the load and not only at the
 * bootstrap.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function withDelightDeprecationsFiltered(callable $callback): mixed
{
    set_error_handler('delightDeprecation', E_DEPRECATED);

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

/**
 * Boot a fresh in-memory SQLite database and return a ready-to-use AuthService.
 * Throttling is disabled so tests never hit rate limits.
 */
function bootAuthLayer(): Spora\Auth\AuthService
{
    $pdo = Illuminate\Database\Capsule\Manager::connection()->getPdo();

    $auth = withDelightDeprecationsFiltered(
        static fn(): Delight\Auth\Auth => new Delight\Auth\Auth($pdo, null, null, false /* throttling off */),
    );

    return new Spora\Auth\AuthService($auth);
}

/**
 * Create a JSON Request with an optional body array.
 */
function jsonRequest(string $method, string $uri, array $body = []): Symfony\Component\HttpFoundation\Request
{
    $content = $body !== [] ? json_encode($body) : '';

    return Symfony\Component\HttpFoundation\Request::create(
        $uri,
        strtoupper($method),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $content,
    );
}

/**
 * Simulate a logged-in session by populating the PHP session superglobal
 * the same way delight-im/auth does internally.
 */
function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_LOGGED_IN] = true;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID]   = $userId;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_EMAIL]     = $email;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USERNAME]  = null;
}

/**
 * Clear the session, simulating a not-logged-in state.
 */
function clearSession(): void
{
    $_SESSION = [];
}

/**
 * Register a new user and simulate their session.
 * Returns the user ID.
 */
function bootAuth(Spora\Auth\AuthService $authService, string $email = 'test@example.com', string $password = 'Password1!', string $displayName = 'Test User'): int
{
    $userId = $authService->register($email, $password, $displayName);
    simulateLoggedInSession($userId, $email);

    return $userId;
}

/**
 * Materialise a user-principal row for the given user id and return the
 * principal id.
 *
 * The metering domain is anchored on `principal_id` (post-0067), so every
 * test that touches it goes through this helper. Idempotent.
 */
function createUserPrincipal(int $userId): int
{
    $service = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());

    return (int) $service->ensureUserPrincipal($userId)->id;
}

/**
 * Materialise a group with `$userId` as its owner, and return the group's
 * principal id.
 *
 * The metering domain charges a group pool for a shared agent, so the tests
 * need a principal that is not a user. Idempotent on the group's name.
 */
function createGroupPrincipal(int $userId, string $name = 'Usage Test Group'): int
{
    $group = Spora\Models\Group::query()->where('name', $name)->first();

    if ($group === null) {
        $group = Spora\Models\Group::create([
            'name'              => $name,
            'created_by_user_id' => $userId,
        ]);

        Spora\Models\GroupMembership::create([
            'group_id' => $group->id,
            'user_id'  => $userId,
            'role'     => Spora\Models\GroupMembership::ROLE_OWNER,
        ]);
    }

    $service = new Spora\Services\PrincipalService(new Spora\Services\PrincipalResolver());

    return (int) $service->ensureGroupPrincipal((int) $group->id)->id;
}

/**
 * Materialise an Agent row backed by a freshly-built user-principal for the
 * given user id. Returns the new agent id.
 *
 * `llm_provider` / `llm_model` are intentionally NOT written: migration 0012
 * dropped both columns.
 */
function createAgentWithPrincipal(int $userId, string $name = 'Test Agent', array $extra = []): int
{
    $principalId = createUserPrincipal($userId);

    return (int) Illuminate\Database\Capsule\Manager::table('agents')->insertGetId($extra + [
        'principal_id' => $principalId,
        'name'         => $name,
        'max_steps'    => 5,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
}

uses()
    ->beforeEach(function () {
        Spora\Core\Database::resetBootState();
        $db = new Spora\Core\Database(new Spora\Core\Config(['SPORA_DB_DRIVER' => 'sqlite', 'SPORA_DB_PATH' => ':memory:']));
        $db->boot();
        Illuminate\Database\Capsule\Manager::connection()->beginTransaction();

        // The plugin's tables live in the plugin's own migrations. The host
        // DatabaseSchemaInstaller only runs plugin migrations when a
        // PluginLoader is wired up — which the plugin's pest suite does not
        // do. Replay them here so the test code can read and write them.
        $migrationPath = __DIR__ . '/../database/migrations';
        foreach (glob($migrationPath . '/*.php') ?: [] as $migrationFile) {
            $migration = require $migrationFile;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    })
    ->afterEach(function () {
        if (Illuminate\Database\Capsule\Manager::connection()->transactionLevel() > 0) {
            Illuminate\Database\Capsule\Manager::connection()->rollBack();
        }
        Spora\Core\Database::resetBootState();
        Mockery::close();
    })
    ->in(__DIR__);
