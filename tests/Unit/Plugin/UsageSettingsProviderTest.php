<?php

declare(strict_types=1);

use Spora\Plugins\Usage\Settings\UsageSettingsProvider;
use Spora\Settings\PluginSettingsProviderInterface;
use Spora\Settings\SettingDefinition;

it('declares its settings under the plugin slug', function (): void {
    $provider = new UsageSettingsProvider();

    expect($provider)->toBeInstanceOf(PluginSettingsProviderInterface::class);
    expect($provider->slug())->toBe('usage');
});

it('declares the retention window bare, so the host owns the key namespace', function (): void {
    // The key is rewritten to `SPORA_USAGE_RETENTION_DAYS` by the host. A
    // plugin that spelled the prefix itself would end up with a key the
    // host refuses, because the host owns the key space.
    $provider = new UsageSettingsProvider();

    $settings = $provider->settings();

    expect($settings)->toHaveCount(1);
    expect($settings[0]->key)->toBe('retention_days');
    expect($settings[0]->type)->toBe(SettingDefinition::TYPE_NUMBER);
    expect($settings[0]->default)->toBe(90);
});
