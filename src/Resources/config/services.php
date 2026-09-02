<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriterInterface;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeSubscriber;
use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriter;
use MerchantQuoteAgentPlugin\Audit\TerminalOutcomeWriterInterface;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteCommentWriterInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteProductAdderInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\SwagCommercialCommentWriter;
use MerchantQuoteAgentPlugin\Bridge\Commercial\SwagCommercialProductAdder;
use MerchantQuoteAgentPlugin\Bridge\Commercial\VariantRejectingProductAdder;
use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteAccess;
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
use MerchantQuoteAgentPlugin\Identity\Authorization\DbalPendingAuthorizationStore;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
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
use MerchantQuoteAgentPlugin\Ucp\Quote\Controller\UcpQuoteController;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapability;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteContractController;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteFieldAssertions;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteLineItemValidator;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteRequestValidator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->defaults()->autowire()->autoconfigure();

    // Autoconfiguration adds `ucp_sdk.capability` (the SDK registers it for
    // every CapabilityInterface), which is what gets the descriptor into the
    // SDK's CapabilityRegistry. The gateway is ignoreOnInvalid() so a shop
    // without SwagCommercial still compiles: the capability's own guard turns
    // the resulting null into a 501, not a container error.
    $services->set(QuoteCapability::class)->arg(
        '$gateway',
        service(BuyerQuoteGatewayInterface::class)->ignoreOnInvalid(),
    );
    $services->set(QuoteRequestValidator::class);
    $services->set(QuoteLineItemValidator::class);
    $services->set(QuoteFieldAssertions::class);

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

    if (CommercialAvailability::isAvailableByClass()) {
        $services->set(UcpQuoteController::class)->tag('controller.service_arguments');
    }

    // Buyer-side sales-channel resolution. Autowired: Connection and the
    // context service are both core services.
    $services->set(SalesChannelContextResolver::class);
    $services->alias(CustomerContextResolverInterface::class, SalesChannelContextResolver::class);

    // Identity: bearer token → customer context. The reader is the only class
    // that knows Agentic Commerce's OAuth schema (issue #13 retires it).
    $services->set(AcOAuthAccessTokenReader::class);
    $services->alias(AccessTokenSubjectReaderInterface::class, AcOAuthAccessTokenReader::class);
    $services->set(AgentCustomerAuthenticator::class);

    $services->set(DbalPendingAuthorizationStore::class);
    $services->alias(PendingAuthorizationStoreInterface::class, DbalPendingAuthorizationStore::class);

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

    // The outcome half of the audit trail (#33): a subscriber on the core
    // quote state machine stamps terminal_state / terminal_at onto the newest
    // record. autoconfigure() gives the subscriber its kernel.event_subscriber
    // tag, so only the repository argument needs naming.
    $services->set(TerminalOutcomeWriter::class)->args([service('merchant_quote_agent_decision.repository')]);
    $services->alias(TerminalOutcomeWriterInterface::class, TerminalOutcomeWriter::class);
    $services->set(TerminalOutcomeSubscriber::class);

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
    // (No `isAvailableByClass()` guard here: the early return above already
    // means SwagCommercial's classes provably exist past this point.)
    $services->set(CommercialQuoteSnapshotMapper::class);

    // "May this buyer request be served at all, and on whose behalf" —
    // the preconditions every operation shares, separate from the
    // gateway because they are a different question from which route
    // implements the operation. #44 tracks the rest of the gateway's shape.
    $services->set(CommercialQuoteAccess::class)->arg(
        '$customerSpecificFeatureService',
        service(CommercialAvailability::CUSTOMER_SPECIFIC_FEATURE_SERVICE)->nullOnInvalid(),
    );

    // Extracted out of the gateway for the same reason: this is where
    // nearly all of the branching over an existing quote's line items
    // lived, and it is a different job from deciding which Store API
    // route to call.
    $services->set(CommercialQuoteLinePricing::class)->arg(
        '$quoteLineItemRoute',
        service(CommercialAvailability::QUOTE_LINE_ITEM_ROUTE)->nullOnInvalid(),
    );

    $services
        ->set(SwagCommercialBuyerQuoteGateway::class)
        ->arg('$quoteRequestRoute', service(CommercialAvailability::QUOTE_REQUEST_ROUTE)->nullOnInvalid())
        ->arg('$quoteSendRequestRoute', service(CommercialAvailability::QUOTE_SEND_REQUEST_ROUTE)->nullOnInvalid())
        ->arg('$quoteLoadRoute', service(CommercialAvailability::QUOTE_LOAD_ROUTE)->nullOnInvalid())
        ->arg('$quoteListingRoute', service(CommercialAvailability::QUOTE_LISTING_ROUTE)->nullOnInvalid())
        ->arg('$quoteRequestChangeRoute', service(CommercialAvailability::QUOTE_REQUEST_CHANGE_ROUTE)->nullOnInvalid())
        ->arg('$quoteDeclineRoute', service(CommercialAvailability::QUOTE_DECLINE_ROUTE)->nullOnInvalid())
        ->arg('$quoteOrderRoute', service(CommercialAvailability::QUOTE_ORDER_ROUTE)->nullOnInvalid());

    $services->alias(BuyerQuoteGatewayInterface::class, SwagCommercialBuyerQuoteGateway::class);

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
