<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriterInterface;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteCommentWriterInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteProductAdderInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\SwagCommercialCommentWriter;
use MerchantQuoteAgentPlugin\Bridge\Commercial\SwagCommercialProductAdder;
use MerchantQuoteAgentPlugin\Bridge\Commercial\VariantRejectingProductAdder;
use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteLinePricing;
use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteSnapshotMapper;
use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
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
use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use MerchantQuoteAgentPlugin\Bridge\SwagCommercialBuyerQuoteGateway;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Identity\AccessTokenSubjectReaderInterface;
use MerchantQuoteAgentPlugin\Identity\AcOAuthAccessTokenReader;
use MerchantQuoteAgentPlugin\Identity\AgentCustomerAuthenticator;
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingPreflight;
use MerchantQuoteAgentPlugin\Ucp\Profile\QuoteCapabilityProfileContributor;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapability;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteContractController;
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

    // The two documents the descriptor advertises. Served by this plugin because
    // this plugin publishes the descriptor: whoever advertises the URLs owes the
    // shop the files behind them.
    $services
        ->set(QuoteContractController::class)
        ->arg('$schemaPath', __DIR__ . '/../schema/quote.openapi.json')
        ->arg('$specPath', __DIR__ . '/../schema/quote.spec.html')
        ->tag('controller.service_arguments');

    // Buyer-side sales-channel resolution. Autowired: Connection and the
    // context service are both core services.
    //
    // ->public() only because nothing CONSUMES the resolver yet. Registering a
    // service is not consuming it: the test service locator holds weak
    // references, so RemoveUnusedDefinitionsPass drops an unreferenced private
    // definition and everything it alone referenced — the authenticator and this
    // resolver disappear from the locator together, and the integration test
    // cannot fetch what the container removed. Task 6's controller is the first
    // real consumer (tagged controller.service_arguments); the flag comes out
    // with it.
    $services->set(SalesChannelContextResolver::class)->public();
    $services->alias(CustomerContextResolverInterface::class, SalesChannelContextResolver::class);

    // Identity: bearer token → customer context. The reader is the only class
    // that knows Agentic Commerce's OAuth schema (issue #13 retires it).
    $services->set(AcOAuthAccessTokenReader::class);
    $services->alias(AccessTokenSubjectReaderInterface::class, AcOAuthAccessTokenReader::class);
    $services->set(AgentCustomerAuthenticator::class);

    // The audit trail (issue #19). Registered unconditionally — a decision
    // record is written by the plugin's own servicing pass, not by the
    // commercial bridge, so it exists whether or not SwagCommercial is
    // installed. Autoconfiguration reads the class's own #[Entity] attribute
    // and adds the `shopware.entity` tag; this set() call only has to make
    // the class a service for that to fire.
    $services->set(QuoteDecisionRecord::class);

    // The repository is created by the DAL from the #[Entity] attribute; it
    // is not autowirable by type, so name it.
    $services->set(DecisionRecordWriter::class)->args([service('merchant_quote_agent_decision.repository')]);
    $services->alias(DecisionRecordWriterInterface::class, DecisionRecordWriter::class);
    $services->set(DecisionRecorder::class);

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

    // Buyer-side counterpart of the merchant gateway. Every commercial route is
    // an ignore-on-invalid reference, so the container compiles on a shop
    // without SwagCommercial and the capability reports itself unsupported.
    if (CommercialAvailability::isAvailableByClass()) {
        $services->set(CommercialQuoteSnapshotMapper::class);

        // Extracted out of the gateway (the follow-up issue on the buyer
        // gateway's shape covers the rest of it): this is where nearly all of
        // the branching over an existing quote's line items lived, and it is a
        // different job from deciding which Store API route to call.
        $services->set(CommercialQuoteLinePricing::class)->arg(
            '$quoteLineItemRoute',
            service(
                'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\LineItem\\QuoteLineItemRoute',
            )->nullOnInvalid(),
        );

        // ->public() only because nothing CONSUMES the gateway yet. Registering
        // a service is not consuming it: the test service locator holds weak
        // references, so RemoveUnusedDefinitionsPass drops an unreferenced
        // private definition and everything it alone referenced, and the
        // integration test cannot fetch what the container removed. Task 5's
        // QuoteCapability is the first real consumer — the SDK's registry
        // references it through the ucp_sdk.capability tag — and the flag comes
        // out with it.
        $services
            ->set(SwagCommercialBuyerQuoteGateway::class)
            ->public()
            ->arg(
                '$quoteRequestRoute',
                service(
                    'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\CartToQuote\\QuoteRequestRoute',
                )->nullOnInvalid(),
            )
            ->arg(
                '$quoteSendRequestRoute',
                service(
                    'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteSendRequestRoute',
                )->nullOnInvalid(),
            )
            ->arg(
                '$quoteLoadRoute',
                service(
                    'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteAccounting\\QuoteLoadRoute',
                )->nullOnInvalid(),
            )
            ->arg(
                '$quoteListingRoute',
                service(
                    'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteAccounting\\QuoteListingRoute',
                )->nullOnInvalid(),
            )
            ->arg(
                '$quoteRequestChangeRoute',
                service(
                    'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteRequestChangeRoute',
                )->nullOnInvalid(),
            )
            ->arg(
                '$quoteDeclineRoute',
                service(
                    'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteDeclineRoute',
                )->nullOnInvalid(),
            )
            ->arg(
                '$quoteOrderRoute',
                service(
                    'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteToOrder\\QuoteOrderRoute',
                )->nullOnInvalid(),
            )
            ->arg(
                '$customerSpecificFeatureService',
                service(
                    'Shopware\\Commercial\\B2B\\CustomerSpecificFeatures\\Domain\\CustomerSpecificFeature\\CustomerSpecificFeatureService',
                )->nullOnInvalid(),
            )
            ->arg('$quoteRepository', service('quote.repository')->nullOnInvalid());

        $services->alias(BuyerQuoteGatewayInterface::class, SwagCommercialBuyerQuoteGateway::class);
    }

    // Configuration (issue #5). Autowired: the factory takes ValidatorInterface,
    // which Shopware aliases to HappyPathValidator — harmless, because a
    // validate() call with no explicit constraints delegates straight to the
    // real Symfony validator.
    //
    // The reader stays private: the alias below is what references it, so
    // RemoveUnusedDefinitionsPass no longer prunes it as dead.
    $services->set(QuoteAgentSettingsFactory::class);
    $services->set(QuoteAgentSettingsReader::class);
    $services->alias(QuoteAgentSettingsSource::class, QuoteAgentSettingsReader::class);

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

    // Whether a quote may be serviced at all, and with which settings (#5).
    $services->set(QuoteEscalator::class);
    $services->set(ServicingPreflight::class)->args([
        service(QuoteAgentSettingsSource::class),
        service(QuoteEscalator::class),
        service('logger'),
    ]);

    // Negotiation (issue #18). The prompts are read HERE, at container compile,
    // and handed in as strings — src/Negotiation/ never touches the filesystem,
    // which is what keeps it Shopware-free and path-free. services.php lives at
    // src/Resources/config/, so three levels up is the plugin root; a missing
    // prompt makes file_get_contents() return false, and str_replace() under
    // strict_types then fails the container at compile, which is the right
    // time to find out.
    $promptDir = \dirname(__DIR__, levels: 3) . '/config/agents/';

    // The `%%` is not decoration. Symfony resolves `%...%` inside a string
    // argument as a container parameter, and the negotiate prompt says
    // "allowed up to 10% is perfectly fine" — which the container read as a
    // parameter name and refused to boot over. `%%` is the literal escape.
    $prompt = static function (string $file) use ($promptDir): string {
        $text = file_get_contents($promptDir . $file);
        \assert(\is_string($text), description: $promptDir . $file . ' could not be read.');

        return str_replace(search: '%', replace: '%%', subject: $text);
    };

    $services->set(PromptComposer::class)->args([
        $prompt('quote-extract-agent.prompt.md'),
        $prompt('quote-negotiate-agent.prompt.md'),
        $prompt('quote-reply-agent.prompt.md'),
    ]);

    // Guzzle's own client, registered explicitly: nothing in the shop provides
    // GuzzleHttp\ClientInterface, and ChatCompletionClient types against it
    // rather than PSR-18 because that is the contract Guzzle's exceptions ride.
    $services->set(GuzzleClient::class);
    $services->set(ChatCompletionClient::class)->args([service(GuzzleClient::class), service('logger')]);

    // The policy deciders take only defaulted collaborators, so autowiring
    // leaves them at their defaults — no argument list to keep in sync.
    $services->set(NegotiationDecider::class);
    $services->set(OfferAuthorizer::class);
    $services->set(OfferVerifier::class);

    $services->set(AskInterpreter::class);
    $services->set(OfferProposer::class);
    $services->set(OfferApplier::class);
    $services->set(ReplyComposer::class);
    $services->set(OfferRound::class);
    $services->set(NegotiationPipeline::class);

    // The one line that turns the agent on.
    $services->alias(QuoteServicingPipelineInterface::class, NegotiationPipeline::class);

    // The gateway argument is the null-returning factory registered above; the
    // pipeline is the alias just above it. Both ignoreOnInvalid() so an absent
    // or unlicensed backend degrades to a log line rather than a container
    // error. autoconfigure() picks up #[AsMessageHandler].
    $services->set(ServiceQuoteHandler::class)->args([
        service(QuoteServicingLock::class),
        service('logger'),
        service(ServicingPreflight::class),
        service(QuoteGatewayInterface::class)->ignoreOnInvalid(),
        service(QuoteServicingPipelineInterface::class)->ignoreOnInvalid(),
    ]);
};
