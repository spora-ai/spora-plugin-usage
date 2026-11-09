<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage;

use Psr\Log\LoggerInterface;
use Spora\Apps\AppInterface;
use Spora\Events\ContainerBuildingEvent;
use Spora\Events\RoutesRegisteringEvent;
use Spora\Http\Middleware\AdminMiddleware;
use Spora\Http\Middleware\AuthMiddleware;
use Spora\Http\Middleware\CsrfMiddleware;
use Spora\Metering\UsageMeterInterface;
use Spora\Plugins\AbstractPlugin;
use Spora\Plugins\Usage\Http\UsageAdminController;
use Spora\Plugins\Usage\Http\UsageController;
use Spora\Plugins\Usage\Services\UsageLimitService;
use Spora\Plugins\Usage\Settings\UsageSettingsProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin entry point.
 *
 * The binding in {@see onContainerBuilding()} is the whole reason this
 * plugin exists: core binds the metering seam to a null object, and this
 * plugin replaces it with the meter that charges for the work. The container
 * keeps the **last** definition for a key, and the plugin loader dispatches
 * `ContainerBuildingEvent` after the core's definitions are added — so the
 * plugin's binding wins.
 *
 * DI bindings and routes are registered through
 * {@see EventSubscriberInterface}: core has no per-plugin fallback hook, and
 * the loader wires subscribers outside its warm-cache branch, so they
 * survive a warm boot.
 */
final class UsagePlugin extends AbstractPlugin implements EventSubscriberInterface
{
    private const ROUTE_BASE = '/api/v1/usage';

    private const ROUTE_ME = self::ROUTE_BASE . '/me';

    private const ROUTE_ME_SERIES = self::ROUTE_BASE . '/me/series';

    private const ROUTE_PRINCIPALS = self::ROUTE_BASE . '/principals';

    private const ROUTE_PRINCIPAL = self::ROUTE_BASE . '/principals/{id}';

    private const ROUTE_PRINCIPAL_LIMITS = self::ROUTE_BASE . '/principals/{id}/limits';

    private const ROUTE_PRINCIPAL_CREDITS = self::ROUTE_BASE . '/principals/{id}/credits';

    private const ROUTE_PRICES = self::ROUTE_BASE . '/prices';

    private const ROUTE_UNPRICED = self::ROUTE_BASE . '/unpriced';

    private const AUTH = [AuthMiddleware::class, CsrfMiddleware::class];

    private const ADMIN = [AuthMiddleware::class, CsrfMiddleware::class, AdminMiddleware::class];

    public function getName(): string
    {
        return 'Usage';
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ContainerBuildingEvent::class => 'onContainerBuilding',
            RoutesRegisteringEvent::class => 'onRoutesRegistering',
        ];
    }

    /**
     * Install the plugin's meter over core's null object, and wire the
     * controllers the routes dispatch into.
     *
     * The logger is bound **explicitly** on the two classes that take one.
     * PHP-DI does not resolve an optional nullable constructor parameter:
     * `?LoggerInterface $logger = null` stays `null` whether or not the
     * container has a binding for the type. Left to autowiring, the meter
     * would swallow its own failures in silence — and a meter that fails
     * silently is the one failure mode the fail-open rule exists to make
     * visible.
     */
    public function onContainerBuilding(ContainerBuildingEvent $event): void
    {
        $event->builder()->addDefinitions([
            UsageMeterInterface::class => \DI\autowire(UsageMeter::class)
                ->constructorParameter('logger', \DI\get(LoggerInterface::class)),
            UsageLimitService::class => \DI\autowire(UsageLimitService::class)
                ->constructorParameter('logger', \DI\get(LoggerInterface::class)),
            UsageController::class => \DI\autowire(),
            UsageAdminController::class => \DI\autowire(),
        ]);
    }

    /**
     * Register the plugin's own routes.
     *
     * The admin routes carry `AdminMiddleware` on top of auth and CSRF: the
     * admin tabs of the Usage app hide themselves for a non-admin, but the
     * API is the gate that decides.
     */
    public function onRoutesRegistering(RoutesRegisteringEvent $event): void
    {
        $routes = $event->routes();

        $routes->addRoute('GET', self::ROUTE_ME, [UsageController::class, 'me'], self::AUTH);
        $routes->addRoute('GET', self::ROUTE_ME_SERIES, [UsageController::class, 'series'], self::AUTH);

        $routes->addRoute('GET', self::ROUTE_PRINCIPALS, [UsageAdminController::class, 'index'], self::ADMIN);
        $routes->addRoute('GET', self::ROUTE_PRINCIPAL, [UsageAdminController::class, 'show'], self::ADMIN);
        $routes->addRoute('PUT', self::ROUTE_PRINCIPAL_LIMITS, [UsageAdminController::class, 'replaceLimits'], self::ADMIN);
        $routes->addRoute('PUT', self::ROUTE_PRINCIPAL_CREDITS, [UsageAdminController::class, 'grantCredits'], self::ADMIN);
        $routes->addRoute('GET', self::ROUTE_PRICES, [UsageAdminController::class, 'prices'], self::ADMIN);
        $routes->addRoute('PUT', self::ROUTE_PRICES, [UsageAdminController::class, 'replacePrices'], self::ADMIN);
        $routes->addRoute('GET', self::ROUTE_UNPRICED, [UsageAdminController::class, 'unpriced'], self::ADMIN);
    }

    public function migrationsPath(): string
    {
        return __DIR__ . '/../database/migrations';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    /**
     * @return list<class-string<\Spora\Settings\PluginSettingsProviderInterface>>
     */
    public function pluginSettingProviders(): array
    {
        return [
            UsageSettingsProvider::class,
        ];
    }

    /**
     * @return array<class-string<AppInterface>>
     */
    public function apps(): array
    {
        return [
            UsageApp::class,
        ];
    }
}
