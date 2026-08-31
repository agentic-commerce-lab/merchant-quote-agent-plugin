<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Imported by Bundle::configureRoutes(). The only routes this plugin owns are
// the two capability documents; the quote transport itself belongs to the
// Agentic Commerce plugin (issue #9).
return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/../../Ucp/Quote/QuoteContractController.php', 'attribute');
};
