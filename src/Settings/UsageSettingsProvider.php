<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Settings;

use Spora\Settings\PluginSettingsProviderInterface;
use Spora\Settings\SettingDefinition;

/**
 * The operator settings the plugin needs.
 *
 * The key is given bare: the host rewrites `retention_days` into
 * `SPORA_USAGE_RETENTION_DAYS` and owns the namespace, so the plugin states
 * one key and the host decides what it is called.
 */
final class UsageSettingsProvider implements PluginSettingsProviderInterface
{
    public function slug(): string
    {
        return 'usage';
    }

    /**
     * @return list<SettingDefinition>
     */
    public function settings(): array
    {
        return [
            new SettingDefinition(
                key: 'retention_days',
                label: 'Retention (days)',
                type: SettingDefinition::TYPE_NUMBER,
                default: 90,
                group: 'plugin:usage',
                section: 'plugins',
                description: 'How long the ledger rows and the bucket rows are kept. A shorter retention keeps the '
                    . 'tables small; a longer one keeps the history the reporting windows are drawn from.',
                min: 1,
            ),
        ];
    }
}
