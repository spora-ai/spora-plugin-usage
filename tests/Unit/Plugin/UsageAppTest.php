<?php

declare(strict_types=1);

use Spora\Apps\AppInterface;
use Spora\Apps\VueAppInterface;
use Spora\Plugins\Usage\UsageApp;

it('names itself after the plugin slug', function (): void {
    $app = new UsageApp();

    expect($app)->toBeInstanceOf(VueAppInterface::class);
    expect($app->name())->toBe('usage');
    expect($app->displayName())->toBe('Usage');
});

it('points the host at the frontend bundle the package ships', function (): void {
    // The entry has to match the frontend package's `build.lib.fileName()`:
    // the host lazy-loads the bundle from `/plugins/usage/<entry>`.
    $app = new UsageApp();

    expect($app->entry())->toBe('main.js');
});

it('uses an accent the host can render', function (): void {
    // An unknown token falls back to `primary` silently, so a typo would
    // cost the tile its colour without saying so.
    $app = new UsageApp();

    expect($app->accent())->toBeIn(AppInterface::ACCENT_TOKENS);
});

it('uses the icon the manifest declares', function (): void {
    // The PHP method wins over the manifest, so the two have to agree —
    // otherwise the tile's icon depends on which one the host reads.
    $manifest = json_decode(
        (string) file_get_contents(__DIR__ . '/../../../plugin.json'),
        true,
    );

    expect((new UsageApp())->icon())->toBe($manifest['icon']);
});
