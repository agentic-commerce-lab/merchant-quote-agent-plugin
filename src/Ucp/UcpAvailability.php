<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Whether this shop has a UCP surface at all.
 *
 * The Agentic Commerce plugin is what registers `Ucp\Sdk\Symfony\UcpSdkBundle`
 * (its `getAdditionalBundles()`), and that bundle is what brings the SDK's
 * `RuntimeConfigurationResolverInterface` / `UcpSdkConfiguration` /
 * `UcpResponseFactory` services, plus the OAuth tables the identity-linking
 * flow reads. Without it none of those exist, so everything this plugin builds
 * on top of them is simply not registered — the same stage-one gate ADR 0001
 * already applies to SwagCommercial, for the same reason.
 *
 * What survives its absence is the point: quotes a buyer creates by hand in the
 * storefront are serviced, escalated and audited exactly as before, and the
 * A2CN evidence layer keeps signing (services.php registers the SDK's two
 * dependency-free core services itself when the bundle is gone). Only the
 * agent-facing surface — UCP quote endpoints, identity linking, the Agent
 * Access settings page — switches off.
 *
 * One probe, and it reads the container rather than the classpath: see
 * isRegistered() on why class existence is the wrong question here.
 */
final class UcpAvailability
{
    /**
     * The bundle's name in `kernel.bundles`, which is `Bundle::getName()` —
     * the short class name, since UcpSdkBundle does not override it.
     */
    private const SDK_BUNDLE = 'UcpSdkBundle';

    /**
     * The only probe: is the UCP SDK bundle in THIS container?
     *
     * Deliberately NOT `class_exists` on the Agentic Commerce plugin, which is
     * the obvious thing and is wrong twice over. Both were observed on a real
     * shop, not deduced:
     *
     * 1. That plugin is normally `composer require`d into `vendor/`, so its
     *    namespace is in Composer's autoloader whether or not the plugin is
     *    active. Deactivating it leaves the class loadable forever. (ADR 0001's
     *    "Shopware only registers an ACTIVE plugin's autoloader" holds for
     *    `custom/plugins`, not for a vendored plugin.)
     * 2. Even without that, `plugin:deactivate` rebuilds the container in the
     *    same PHP process that booted with the plugin active, and the autoload
     *    namespaces registered at boot are not unregistered for the rebuild.
     *
     * Either way the class outlives the bundle, and a class-existence gate then
     * registers services against an SDK bundle that is not there — which failed
     * the deactivation outright, in DecoratorServicePass, on the
     * `RuntimeConfigurationResolverInterface` decoration in services.php.
     *
     * `kernel.bundles` has no such lag: it is built from the bundle collection
     * the container actually uses. It is readable during `Bundle::build()`
     * because Symfony's Kernel fills its parameter bag first (services.php
     * already reads `kernel.environment` there), and readable again from the
     * booted container that `Bundle::configureRoutes()` sees, which is what
     * keeps the routes and the service graph telling the same story.
     */
    public static function isRegistered(?ContainerInterface $container): bool
    {
        // Null, or a ContainerBuilder assembled by hand in a test, has no
        // kernel parameters at all. Absent means "no UCP surface", the safe
        // answer: the services behind this gate cannot be built without one.
        if ($container === null || !$container->hasParameter('kernel.bundles')) {
            return false;
        }

        $bundles = $container->getParameter('kernel.bundles');

        return \is_array($bundles) && \array_key_exists(self::SDK_BUNDLE, $bundles);
    }
}
