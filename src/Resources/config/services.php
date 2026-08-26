<?php

declare(strict_types=1);

use MerchantQuoteAgentPlugin\Ucp\Profile\QuoteCapabilityProfileContributor;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapability;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()->autowire()->autoconfigure();

    // Autoconfiguration adds `ucp_sdk.capability` (the SDK registers it for
    // every CapabilityInterface), which is what gets the descriptor into the
    // SDK's CapabilityRegistry.
    $services->set(QuoteCapability::class);

    // Must run AFTER the Agentic Commerce plugin's capability filter, which
    // strips descriptors it does not own. Its contributor sits at the default
    // priority, so anything negative lands behind it. autoconfigure(false)
    // avoids a second tag at the default priority.
    $services->set(QuoteCapabilityProfileContributor::class)->autoconfigure(false)->tag('ucp_sdk.profile_contributor', [
        'priority' => -256,
    ]);
};
