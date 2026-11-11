<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Services;

use Spora\Metering\MeteringContext;

/**
 * Decides which owners a metered event is charged to.
 */
final class UsageAttribution
{
    /**
     * The principal always; the individual only for an interactive run.
     *
     * A platform-started run still carries a trigger user — whoever happened
     * to fire the housekeeping — and charging that person would bill a
     * scheduled job to whoever was logged in when the cron fired.
     *
     * @return list<OwnerScope>
     */
    public function scopesFor(MeteringContext $context): array
    {
        $scopes = [OwnerScope::principal($context->principalId)];

        if ($context->isSystemRun || $context->triggerUserId === null) {
            return $scopes;
        }

        $scopes[] = OwnerScope::user($context->triggerUserId);

        return $scopes;
    }
}
