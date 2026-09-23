<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Every route this plugin serves only where the UCP SDK bundle is.
 *
 * A class of its own rather than a block in the plugin's `configureRoutes()`
 * because that class is on a cyclomatic-complexity gate and these branches
 * pushed it over — but the split earns its keep either way: the imports here
 * mirror, one for one, the gates `Resources/config/services.php` puts the
 * matching controllers behind, and keeping them in one small file makes the two
 * lists readable side by side. Nothing else belongs in here.
 *
 * Static: it holds nothing. The caller hands over its booted container for
 * the commercial gate to read — the same `kernel.bundles` services.php read
 * when it built that container — not to resolve collaborators from.
 */
final class AgentFacingRoutes
{
    /**
     * @param string $pluginPath the plugin root, i.e. Bundle::getPath()
     * @param ?ContainerInterface $container the booted container, i.e. Bundle::$container
     */
    public static function import(RoutingConfigurator $routes, string $pluginPath, ?ContainerInterface $container): void
    {
        // Where an agent registers an authorization request before sending a
        // human to the shop, and the consent page that request points at. Both
        // are Agentic Commerce's identity-linking flow; this plugin only holds
        // the pending request and renders the page.
        $routes->import($pluginPath . '/Identity/Controller/AgentAuthorizationRequestController.php', 'attribute');
        $routes->import($pluginPath . '/Identity/Controller/AgentConsentController.php', 'attribute');

        // The three A2CN discovery documents (Task 20). Gated with the rest of
        // the evidence layer: they advertise `endpoint` and `records_url` to
        // buyer agents, and a shop with no UCP surface has none to answer.
        $routes->import($pluginPath . '/Protocol/Http/A2cnDiscoveryController.php', 'attribute');

        // Unconditional, like the discovery document above and for its sake:
        // discovery advertises `{base}/a2cn` as this agent's endpoint whether
        // or not the quote backend is licensed, so every path beneath it owes
        // a protocol answer rather than a storefront page. Its route sorts
        // last by priority, so importing it early costs the real routes
        // nothing.
        $routes->import($pluginPath . '/Protocol/Http/A2cnNotFoundController.php', 'attribute');

        if (!CommercialAvailability::isRegistered($container)) {
            return;
        }

        // The runtime quote endpoints, and the act chain and end-of-session
        // records (Task 19), need BOTH backends — matching the nested gates in
        // services.php.
        $routes->import($pluginPath . '/Ucp/Quote/Controller/UcpQuoteController.php', 'attribute');
        $routes->import($pluginPath . '/Protocol/Http/A2cnRecordsController.php', 'attribute');

        // A2CN's own inbound act route. Same gates as the records routes: it
        // resolves a session to a quote and reads that quote's state, so it
        // needs the commercial backend exactly as they do.
        $routes->import($pluginPath . '/Protocol/Http/A2cnMessagesController.php', 'attribute');
    }
}
