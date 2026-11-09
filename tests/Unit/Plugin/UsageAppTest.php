<?php

declare(strict_types=1);

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
