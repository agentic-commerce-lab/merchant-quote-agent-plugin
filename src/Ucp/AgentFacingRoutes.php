<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
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
 * Static: it holds nothing, and the caller is a Bundle hook that has no
 * container to resolve a collaborator from.
 */
final class AgentFacingRoutes
{
    /** @param string $pluginPath the plugin root, i.e. Bundle::getPath() */
    public static function import(RoutingConfigurator $routes, string $pluginPath): void
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

        if (!CommercialAvailability::isAvailableByClass()) {
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
