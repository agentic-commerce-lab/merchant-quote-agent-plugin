<?php

declare(strict_types=1);

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Imported by Bundle::configureRoutes(). Two kinds of route live here: the
// capability's contract documents, served unconditionally, and its runtime
// endpoints, which exist only where the commercial quote backend does.
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/../../Ucp/Quote/QuoteContractController.php', 'attribute');

    // Where an agent registers an authorization request before sending a
    // human to the shop. Imported unconditionally — it does not depend on the
    // commercial quote backend.
    $routes->import(__DIR__ . '/../../Identity/Controller/AgentAuthorizationRequestController.php', 'attribute');

    // The consent page the registered request's storefront URL points at.
    // Imported unconditionally for the same reason as the route above.
    $routes->import(__DIR__ . '/../../Identity/Controller/AgentConsentController.php', 'attribute');

    // The runtime endpoints only exist where the commercial backend does,
    // matching the service-graph gate in services.php — otherwise the routes
    // would resolve to a service the container never built.
    if (CommercialAvailability::isAvailableByClass()) {
        $routes->import(__DIR__ . '/../../Ucp/Quote/Controller/UcpQuoteController.php', 'attribute');

        // The act chain and end-of-session records (Task 19): imported here,
        // not below with the discovery document, because
        // QuoteTerminalStateReader depends on the quote gateway, which only
        // exists where the commercial backend does.
        $routes->import(__DIR__ . '/../../Protocol/Http/A2cnRecordsController.php', 'attribute');
    }
};
