<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
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
 * The second condition, `interface_exists(ToolFactoryInterface::class)`, does
 * NOT reintroduce the `class_exists` gate spec §2.3 forbids. That prohibition
 * is about a classpath check LYING about deactivation — a vendored plugin's
 * namespace survives in Composer's autoloader after the bundle is gone, so
 * `class_exists` alone would say "still here" about a plugin that was just
 * uninstalled. Here the bundle check still runs first and still dominates:
 * whenever the bundle is absent this still returns `false`, so deactivation
 * is decided correctly either way. The `interface_exists` check only guards
 * the one case the bundle check cannot: the bundle IS present but the stub
 * this repo hand-maintains has drifted from upstream's real FQCN. Without it,
 * `services.php` still registers `RequestQuoteToolFactory implements
 * ToolFactoryInterface` against a name that resolves to nothing, and the
 * container build fatals on every request of a shop that has both plugins
 * installed — a wrong tag or bundle name only makes the feature silently
 * absent, but a wrong interface name is an unbounded failure. This converts
 * that fatal into the same silent absence. Do not delete this as a
 * contradiction of §2.3 without rereading this paragraph.
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

        if (!\is_array($bundles) || !\array_key_exists(self::ASSISTANT_BUNDLE, $bundles)) {
            return false;
        }

        return interface_exists(ToolFactoryInterface::class);
    }
}
