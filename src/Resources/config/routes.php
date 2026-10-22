<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Imported by Bundle::configureRoutes(). Only the routes that need no UCP
// surface live here. Everything gated is imported by
// the plugin class's configureRoutes() override instead, because the gate has
// to read the container's bundle list and a route file is handed nothing but
// $routes.
return static function (RoutingConfigurator $routes): void {
    // The quote contract documents: static files that depend on nothing and
    // describe the capability rather than serving it, so they stay reachable on
    // a shop that advertises nothing.
    $routes->import(__DIR__ . '/../../Ucp/Quote/QuoteContractController.php', 'attribute');

    // The dashboard's Export button. An admin-API route, so it depends on the
    // admin session and not on the shop advertising anything.
    $routes->import(__DIR__ . '/../../Audit/Export/DecisionExportController.php', 'attribute');

    // Accepting or rejecting a nightly proposal. An admin-API route like the
    // export: it depends on the admin session, not on the shop advertising
    // anything.
    $routes->import(__DIR__ . '/../../Improvement/ProposalController.php', 'attribute');
};
