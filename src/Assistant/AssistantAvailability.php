<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Whether this shop runs the shopping-assistant-starter-kit.
 *
 * That plugin registers the bundle `SwagAssistantStarterKit`, and with it the
 * `swag_assistant.tool_factory` tag and the `ToolFactoryInterface` our factory
 * implements. Without it, nothing this plugin contributes to the assistant is
 * registered — and, because the interface is not in this repo's vendor tree at
 * all, the factory class must never be autoloaded either. Keeping it out of
 * the container is what keeps it off the classpath.
 *
 * `kernel.bundles`, not `class_exists`, for the reasons Ucp\UcpAvailability
 * sets out at length: a vendored plugin's namespace stays in Composer's
 * autoloader after deactivation, and a container rebuild in the same process
 * keeps the boot-time autoloaders. The bundle list has no such lag.
 *
 * Everything else survives its absence: quotes a buyer creates by hand are
 * serviced, escalated and audited exactly as before. Only the two chat tools
 * switch off.
 */
final class AssistantAvailability
{
    /** `Bundle::getName()` is the short class name; the plugin does not override it. */
    private const ASSISTANT_BUNDLE = 'SwagAssistantStarterKit';

    public static function isRegistered(?ContainerInterface $container): bool
    {
        if ($container === null || !$container->hasParameter('kernel.bundles')) {
            return false;
        }

        $bundles = $container->getParameter('kernel.bundles');

        return \is_array($bundles) && \array_key_exists(self::ASSISTANT_BUNDLE, $bundles);
    }
}
