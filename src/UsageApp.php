<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage;

use Spora\Apps\VueAppInterface;

/**
 * Admin-panel metadata for the Usage feature.
 *
 * The entry must match the frontend bundle's `build.lib.fileName()` value —
 * the host loads the bundle from the plugin slug and that filename.
 */
final class UsageApp implements VueAppInterface
{
    public function name(): string
    {
        return 'usage';
    }

    public function displayName(): string
    {
        return 'Usage';
    }

    public function description(): string
    {
        return 'Token spend, credits and limits per principal.';
    }

    public function icon(): string
    {
        // A name outside the host's bundled palette renders as the generic
        // `puzzle`, so the name has to be one of the bundled glyphs. The
        // bolt is the closest of them to what this app does: a meter
        // measures consumption.
        return 'zap';
    }

    public function accent(): string
    {
        return 'amber';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
