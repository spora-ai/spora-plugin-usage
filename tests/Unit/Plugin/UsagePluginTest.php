<?php

declare(strict_types=1);

use Delight\Auth\Auth;
use DI\ContainerBuilder;
use FastRoute\DataGenerator\GroupCountBased;
use FastRoute\RouteParser\Std;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spora\Auth\AuthService;
use Spora\Core\MiddlewareRouteCollector;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Metering\NullUsageMeter;
use Spora\Metering\UsageMeterInterface;
use Spora\Plugins\Usage\Http\UsageAdminController;
use Spora\Plugins\Usage\Http\UsageController;
use Spora\Plugins\Usage\Settings\UsageSettingsProvider;
use Spora\Plugins\Usage\UsageApp;
use Spora\Plugins\Usage\UsageMeter;
use Spora\Plugins\Usage\UsagePlugin;
use Spora\Services\PrincipalResolver;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

it('advertises Usage as its name', function (): void {
    $plugin = new UsagePlugin();

    expect($plugin->getName())->toBe('Usage');
});

it('subscribes to the two lifecycle events it needs', function (): void {
    // PSR-14 opt-in: the plugin class itself declares which dispatchers it
    // listens to. Catching an accidental drop preserves the runtime wiring
    // seen in production.
    $plugin = new UsagePlugin();

    expect($plugin)->toBeInstanceOf(EventSubscriberInterface::class);

    $events = UsagePlugin::getSubscribedEvents();
    expect($events)->toHaveKeys([
        ContainerBuildingEvent::class,
        RoutesRegisteringEvent::class,
    ]);
    expect($events[ContainerBuildingEvent::class])->toBe('onContainerBuilding');
    expect($events[RoutesRegisteringEvent::class])->toBe('onRoutesRegistering');
});

it('declares its migrations and its schema version', function (): void {
    $plugin = new UsagePlugin();

    expect(realpath($plugin->migrationsPath()))->toBe(realpath(BASE_PATH . '/database/migrations'));
    expect($plugin->schemaVersion())->toBe(1);
});

it('contributes the usage app and the settings provider', function (): void {
    $plugin = new UsagePlugin();

    expect($plugin->apps())->toBe([UsageApp::class]);
    expect($plugin->pluginSettingProviders())->toBe([UsageSettingsProvider::class]);
});

it('replaces the core null object with the plugin meter', function (): void {
    // The core binds the metering seam to a null object so an install
    // without a metering plugin behaves exactly as it did before the seam
    // existed. The container keeps the LAST definition for a key, and the
    // plugin loader dispatches `ContainerBuildingEvent` after the core's
    // definitions are added — so the plugin's binding is the one that wins.
    //
    // Without it the plugin would install cleanly, register its routes and
    // never meter a single call: the seam would stay invisible.
    $builder = usageTestBuilder();
    $builder->addDefinitions([
        UsageMeterInterface::class => static fn(): UsageMeterInterface => new NullUsageMeter(),
    ]);

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new UsagePlugin());
    $dispatcher->dispatch(new ContainerBuildingEvent($builder));

    $container = $builder->build();

    $meter = $container->get(UsageMeterInterface::class);

    expect($meter)->toBeInstanceOf(UsageMeter::class);
    expect($meter instanceof NullUsageMeter)->toBeFalse();
});

it('wires the controllers into the container', function (): void {
    // The routes dispatch into these two classes by name, so the container
    // has to be able to build them: a controller the container cannot
    // resolve is a 500 on every call to its route.
    $container = usageTestBuilder()->build();

    expect($container->get(UsageController::class))->toBeInstanceOf(UsageController::class);
    expect($container->get(UsageAdminController::class))->toBeInstanceOf(UsageAdminController::class);
});

it('registers the usage routes with the right method, path and middleware', function (): void {
    // The route table is the plugin's contract with the host: a path that
    // moves is a frontend that silently stops working, and a route that
    // loses `AdminMiddleware` is a route anybody signed in may call.
    $routes = new MiddlewareRouteCollector(new Std(), new GroupCountBased());

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new UsagePlugin());
    $dispatcher->dispatch(new RoutesRegisteringEvent($routes));

    $records = usageRegisteredRoutes($routes);

    expect(array_map(
        static fn(array $record): string => $record['method'] . ' ' . $record['path'],
        $records,
    ))->toEqualCanonicalizing([
        'GET /api/v1/usage/me',
        'GET /api/v1/usage/me/series',
        'GET /api/v1/usage/principals',
        'GET /api/v1/usage/principals/{id}',
        'PUT /api/v1/usage/principals/{id}/limits',
        'PUT /api/v1/usage/principals/{id}/credits',
        'GET /api/v1/usage/prices',
        'PUT /api/v1/usage/prices',
        'GET /api/v1/usage/unpriced',
    ]);

    $adminRoutes = [
        'GET /api/v1/usage/principals',
        'GET /api/v1/usage/principals/{id}',
        'PUT /api/v1/usage/principals/{id}/limits',
        'PUT /api/v1/usage/principals/{id}/credits',
        'GET /api/v1/usage/prices',
        'PUT /api/v1/usage/prices',
        'GET /api/v1/usage/unpriced',
    ];

    foreach ($records as $record) {
        $key = $record['method'] . ' ' . $record['path'];

        expect($record['middleware'])->toContain(AuthMiddleware::class);
        expect($record['middleware'])->toContain(CsrfMiddleware::class);

        if (in_array($key, $adminRoutes, true)) {
            expect($record['middleware'])->toContain(AdminMiddleware::class);
            expect($record['controller'])->toBe(UsageAdminController::class);
        } else {
            expect($record['middleware'])->not->toContain(AdminMiddleware::class);
            expect($record['controller'])->toBe(UsageController::class);
        }
    }
});

/**
 * A container builder with the host's own bindings the plugin's graph needs.
 */
function usageTestBuilder(): ContainerBuilder
{
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->addDefinitions([
        AuthService::class => \DI\factory(static fn(): AuthService => usageUnitAuthService()),
        PrincipalResolver::class => \DI\factory(static fn(): PrincipalResolver => new PrincipalResolver()),
        LoggerInterface::class => \DI\factory(static fn(): LoggerInterface => new NullLogger()),
    ]);

    return $builder;
}

/**
 * An {@see AuthService} over the booted connection.
 *
 * `Delight\Auth\Auth` declares an implicitly nullable parameter, and PHP
 * attributes that deprecation to the file that loads the class — so the
 * plugin's bootstrap, which filters by the vendor path, cannot catch it.
 * The handler below swallows it for the load.
 */
function usageUnitAuthService(): AuthService
{
    set_error_handler(static fn(int $errno): bool => true, E_DEPRECATED);

    try {
        return new AuthService(new Auth(Capsule::connection()->getPdo(), null, null, false));
    } finally {
        restore_error_handler();
    }
}

/**
 * Walk FastRoute's compiled structure for a {@see MiddlewareRouteCollector}
 * and yield each registered route's method, path, handler and middleware.
 *
 * A route without a variable lands in the static half of the structure and
 * a route with one in the variable half, so both are walked.
 *
 * @return list<array{method: string, path: string, controller: class-string, action: string, middleware: list<string>}>
 */
function usageRegisteredRoutes(MiddlewareRouteCollector $routes): array
{
    /** @var array{0: array<string, mixed>, 1: array<string, mixed>} $data */
    $data = $routes->getData();
    [$staticRoutes, $variableRoutes] = $data;

    $records = [];

    foreach ($staticRoutes as $method => $methodRoutes) {
        foreach ($methodRoutes as $path => $handlerEntry) {
            $records[] = usageRouteRecord((string) $method, (string) $path, $handlerEntry);
        }
    }

    foreach ($variableRoutes as $method => $chunks) {
        foreach ($chunks as $chunk) {
            $branches = usageRegexBranches((string) $chunk['regex']);
            $index = 0;

            foreach ($chunk['routeMap'] as $entry) {
                $records[] = usageRouteRecord(
                    (string) $method,
                    usageRoutePath($branches[$index] ?? '', $entry[1]),
                    $entry[0],
                );
                $index++;
            }
        }
    }

    return $records;
}

/**
 * One route's method, path, handler and middleware.
 *
 * @param array{handler: array{0: class-string, 1: string}, middleware: list<class-string>} $handlerEntry
 * @return array{method: string, path: string, controller: class-string, action: string, middleware: list<string>}
 */
function usageRouteRecord(string $method, string $path, array $handlerEntry): array
{
    $handler = $handlerEntry['handler'];

    return [
        'method' => $method,
        'path' => $path,
        'controller' => $handler[0],
        'action' => $handler[1],
        'middleware' => $handlerEntry['middleware'],
    ];
}

/**
 * The alternatives a compiled FastRoute regex stands for.
 *
 * FastRoute groups the routes that capture the same number of variables
 * into one chunk and joins their patterns with `|`, so a chunk holds one
 * branch per route it carries.
 *
 * @return list<string>
 */
function usageRegexBranches(string $regex): array
{
    $body = (string) preg_replace('#^~\^\(\?\|(.*)\)\$~$#', '$1', $regex);

    return explode('|', $body);
}

/**
 * The path a compiled branch stands for, with its variables named.
 *
 * @param array<string, string> $vars
 */
function usageRoutePath(string $branch, array $vars): string
{
    // A branch is padded with empty groups so every branch of a chunk
    // numbers its variables the same way; the padding is not part of the path.
    $branch = (string) preg_replace('/(?:\(\))+$/', '', $branch);
    $names = array_keys($vars);
    $index = 0;

    return (string) preg_replace_callback(
        '/\(\[\^\/\]\+\)/',
        static function () use (&$index, $names): string {
            $name = $names[$index] ?? 'id';
            $index++;

            return '{' . $name . '}';
        },
        $branch,
    );
}

it('gives the meter the logger, so a meter failure is not swallowed in silence', function (): void {
    // PHP-DI does not resolve an optional nullable constructor parameter:
    // `?LoggerInterface $logger = null` stays null whether or not the
    // container has a binding for the type. Left to autowiring, the meter
    // would swallow its own failures in silence — and a meter that fails
    // silently is the one failure mode the fail-open rule exists to make
    // visible.
    $recorder = new class implements LoggerInterface {
        /** @var list<string> */
        public array $messages = [];

        public function emergency(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function alert(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function critical(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function error(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function warning(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function notice(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function info(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function debug(string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }

        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };

    $builder = usageTestBuilder();
    $builder->addDefinitions([
        LoggerInterface::class => static fn(): LoggerInterface => $recorder,
    ]);

    $dispatcher = new EventDispatcher();
    $dispatcher->addSubscriber(new UsagePlugin());
    $dispatcher->dispatch(new ContainerBuildingEvent($builder));

    $meter = $builder->build()->get(UsageMeterInterface::class);

    // The limits table is gone, so the limit service's query fails and it
    // logs the failure. With no logger the failure would vanish.
    Capsule::schema()->drop('usage_limits');

    $meter->evaluate(
        new Spora\Metering\MeteringContext(
            taskId: 1,
            agentId: 1,
            principalId: 1,
            triggerUserId: null,
            isSystemRun: false,
        ),
        new Spora\Metering\LlmSubject('openai_compatible', 'gpt-4o'),
    );

    expect($recorder->messages)->not->toBeEmpty();
});
