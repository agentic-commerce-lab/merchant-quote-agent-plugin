<?php

declare(strict_types=1);

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteCommentWriterInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteProductAdderInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\SwagCommercialCommentWriter;
use MerchantQuoteAgentPlugin\Bridge\Commercial\SwagCommercialProductAdder;
use MerchantQuoteAgentPlugin\Bridge\Commercial\VariantRejectingProductAdder;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayFactory;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteLifecycleWriters;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineItemWriter;
use MerchantQuoteAgentPlugin\Bridge\QuoteRecalculator;
use MerchantQuoteAgentPlugin\Bridge\QuoteSnapshotReader;
use MerchantQuoteAgentPlugin\Bridge\QuoteStateTransitioner;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use MerchantQuoteAgentPlugin\Bridge\QuoteWriter;
use MerchantQuoteAgentPlugin\Bridge\QuoteWriters;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Ucp\Profile\QuoteCapabilityProfileContributor;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapability;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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

    // Stage one of ADR 0001's two-stage gate: class existence decides whether
    // the bridge is REGISTERED at all. Shopware only registers an active
    // plugin's autoloader, so this is false both when SwagCommercial is absent
    // and when it is installed but inactive — in either case nothing below is
    // in the container and the quote capability is simply not advertised
    // (issue #1 owns that). Stage two, the license toggle, is runtime and
    // lives in QuoteGatewayFactory.
    if (!CommercialAvailability::isAvailableByClass()) {
        return;
    }

    // Repositories are resolved by string id and typed with a covariant
    // template in the consumer, so autowiring cannot supply them.
    $services->set(QuoteVersionResolver::class);
    $services->set(QuoteSnapshotReader::class)->args([
        service('quote.repository'),
        service(QuoteVersionResolver::class),
    ]);
    $services->set(QuoteLineItemWriter::class)->args([service('quote_line_item.repository')]);
    $services->set(QuoteWriter::class)->args([service('quote.repository')]);
    $services->set(QuoteStateTransitioner::class);

    // The four commercial services, referenced by the string ids on
    // CommercialAvailability because their classes are not ours to name with
    // `::class`. ignoreOnInvalid() rather than a plain reference because an
    // unresolvable reference is a COMPILE-time failure in Symfony — it would
    // take the whole shop's container down, not just quotes. Inside this guard
    // the classes provably exist, so a null here would mean SwagCommercial
    // moved a service id; that surfaces as a TypeError on the adapter's
    // non-nullable `object` parameter, a legible failure confined to the
    // bridge. GatewayWiringTest resolves all four against the live shop.
    $services->set(SwagCommercialProductAdder::class)->args([service(CommercialAvailability::QUOTE_MANIPULATION)->ignoreOnInvalid()]);
    $services->set(SwagCommercialCommentWriter::class)->args([service(CommercialAvailability::QUOTE_COMMENTER)->ignoreOnInvalid()]);
    $services->set(QuoteRecalculator::class)->args([
        service(CommercialAvailability::CONTEXT_RESTORER)->ignoreOnInvalid(),
        service(CommercialAvailability::QUOTE_CALCULATOR)->ignoreOnInvalid(),
    ]);

    // The guard sits in front of the commercial adder: QuoteManipulation
    // segfaults on variant products (see VariantRejectingProductAdder). The
    // aliases are what autowiring needs to fill QuoteWriters/QuoteLifecycleWriters
    // — Symfony does not auto-alias an interface for services registered with
    // set(), and the adder interface now has two implementations anyway.
    $services->set(VariantRejectingProductAdder::class)->args([
        service(SwagCommercialProductAdder::class),
        service('product.repository'),
    ]);
    $services->alias(QuoteProductAdderInterface::class, VariantRejectingProductAdder::class);
    $services->alias(QuoteCommentWriterInterface::class, SwagCommercialCommentWriter::class);

    $services->set(QuoteWriters::class);
    $services->set(QuoteLifecycleWriters::class);
    $services->set(QuoteGatewayFactory::class);

    // Null when the license toggle is off, so consumers take
    // `?QuoteGatewayInterface`. Per the spec's non-goals there is deliberately
    // no null-object implementation: capability absence belongs one layer up.
    $services->set(QuoteGatewayInterface::class)->factory([service(QuoteGatewayFactory::class), 'create']);

    // Servicing (issue #4): trigger, queue and lock. Inside the guard because
    // a shop without SwagCommercial has no quotes to service.
    //
    // LOCK_DSN is read as an injected parameter rather than through getenv():
    // shopware/core defines `env(LOCK_DSN): 'flock'` in its own framework.yaml,
    // so this resolves on every shop whether or not the merchant set it.
    $services->set(QuoteServicingLock::class)->args([
        service('lock.factory'),
        '%env(LOCK_DSN)%',
        service('logger'),
    ]);

    // autoconfigure() picks up EventSubscriberInterface, so no explicit tag.
    $services->set(QuoteServicingTrigger::class)->args([service('messenger.default_bus')]);

    // The gateway argument is the null-returning factory registered above and
    // the pipeline is #18's, registered nowhere yet — both ignoreOnInvalid()
    // so an absent or unlicensed backend degrades to a log line rather than a
    // container error. autoconfigure() picks up #[AsMessageHandler].
    $services->set(ServiceQuoteHandler::class)->args([
        service(QuoteServicingLock::class),
        service('logger'),
        service(QuoteGatewayInterface::class)->ignoreOnInvalid(),
        service(QuoteServicingPipelineInterface::class)->ignoreOnInvalid(),
    ]);
};
