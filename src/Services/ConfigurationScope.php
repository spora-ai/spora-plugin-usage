<?php

declare(strict_types=1);

namespace Spora\Plugins\Usage\Services;

use Psr\Log\LoggerInterface;
use Spora\Metering\LlmSubject;
use Spora\Metering\MeteringContext;
use Spora\Metering\MeteringSubject;
use Spora\Metering\ToolSubject;
use Spora\Models\Agent;
use Spora\Models\ToolUserSetting;
use Spora\Services\LLMConfigPreferences;
use Throwable;

/**
 * Whether the configuration that served a metered event was the
 * installation's or the principal's own.
 *
 * A principal may run an agent with their own provider key, and they may
 * configure a tool with their own credentials. The installation pays for
 * the first and not for the second, so the second must not be billed to
 * their credit pool: the money is theirs, and so is the provider's bill.
 *
 * **The rule is this plugin's.** The core's seam reports what was metered
 * and has no opinion about who pays. Another plugin may bill every event,
 * and it would be equally right — which is why nothing about the rule
 * lives in the core.
 *
 * The facts come from core's own models and services, so the seam needs no
 * change for this to work:
 *
 *  - a model is served by one `LLMDriverConfiguration` row, and the row
 *    says who owns it: a principal-owned row (`is_global = false`) is the
 *    principal's own key, and the global default is the installation's;
 *  - a tool is served by the installation's `tool_configurations` row
 *    unless the principal has a `tool_user_settings` row of their own for
 *    that tool class.
 *
 * The principal that is checked is the one being charged — the task's
 * principal. A group agent's member who has a tool configuration of their
 * own is not consulted: the group cascade that would apply it is off by
 * default, so their configuration is not in play either.
 */
final class ConfigurationScope
{
    private ?LLMConfigPreferences $preferences = null;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Whether the installation paid for the event.
     *
     * A scope that cannot be resolved is **not** billed. The event is
     * still recorded — the tokens and the call count are facts — but
     * charging a principal for a call whose payer is unknown is the worse
     * mistake: the price book is the operator's, and the operator can see
     * in the report what was not billed.
     */
    public function isInstallationScoped(MeteringContext $context, MeteringSubject $subject): bool
    {
        try {
            if ($subject instanceof LlmSubject) {
                return $this->llmIsInstallationScoped($context);
            }

            if ($subject instanceof ToolSubject) {
                return $this->toolIsInstallationScoped($context, $subject);
            }

            return true;
        } catch (Throwable $e) {
            $this->logger?->warning(
                'The configuration scope could not be resolved — the event is recorded but not billed',
                [
                    'kind'      => $subject->kind(),
                    'agent_id'  => $context->agentId,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ],
            );

            return false;
        }
    }

    /**
     * Whether the model that served the call was the installation's.
     *
     * The effective configuration is the one the agent would use anyway:
     * its own pin, else the principal's preference, else the global
     * default. Core already refuses to let an agent use a private
     * configuration that belongs to somebody else, so the row that comes
     * back is either the installation's or the principal's own.
     */
    private function llmIsInstallationScoped(MeteringContext $context): bool
    {
        $agent = Agent::query()->find($context->agentId);

        if ($agent === null) {
            $this->logger?->warning(
                'The agent of a metered call is gone — the event is recorded but not billed',
                ['agent_id' => $context->agentId],
            );

            return false;
        }

        $config = $this->preferences()->getEffectiveConfigForAgent($agent);

        if ($config === null) {
            $this->logger?->warning(
                'The agent of a metered call has no configuration — the event is recorded but not billed',
                ['agent_id' => $context->agentId],
            );

            return false;
        }

        return (bool) $config->is_global;
    }

    /**
     * Whether the tool that served the call was the installation's.
     *
     * The principal's own row for the tool class is their configuration of
     * it; without one, the tool ran on the installation's configuration.
     */
    private function toolIsInstallationScoped(MeteringContext $context, ToolSubject $subject): bool
    {
        $own = ToolUserSetting::query()
            ->where('principal_id', $context->principalId)
            ->where('tool_class', $subject->toolClass)
            ->exists();

        return !$own;
    }

    /**
     * The resolver, built once.
     *
     * It is constructed here rather than injected: it is core's service,
     * not the plugin's, and the container has no binding for it.
     */
    private function preferences(): LLMConfigPreferences
    {
        return $this->preferences ??= new LLMConfigPreferences();
    }
}
