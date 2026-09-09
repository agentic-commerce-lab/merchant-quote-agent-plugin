<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
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
use MerchantQuoteAgentPlugin\Bridge\SwagCommercialQuoteGateway;
use MerchantQuoteAgentPlugin\Servicing\ServicingPreflight;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for every bridge integration test. Each test runs in a transaction that
 * is rolled back, so tests may write freely to the live shop's database.
 */
abstract class IntegrationTestCase extends TestCase
{
    use KernelTestBehaviour;
    use DatabaseTransactionBehaviour;

    /**
     * Fetch a service by raw id. Commercial services are not typed here on
     * purpose — the adapters are what give them a type.
     */
    protected static function commercialService(string $id): object
    {
        try {
            $service = static::getContainer()->get($id);
        } catch (\Throwable $e) {
            self::fail(sprintf(
                'Commercial service "%s" could not be resolved: %s. This normally means the '
                . 'kernel booted without SwagCommercial active, not a wrong id — the ids are '
                . 'FQCNs registered in SwagCommercial\'s own services.php and are resolvable '
                . 'through test.service_container even though they are private.',
                $id,
                $e->getMessage(),
            ));
        }

        self::assertIsObject($service, sprintf('Service "%s" resolved to a non-object.', $id));

        return $service;
    }

    /**
     * A repository by its string service id, typed for callers that build a
     * collaborator graph by hand (e.g. history reads, whose EntityRepository
     * generic parameter forbids autowiring — see QuoteHistoryReads).
     *
     * @return EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection>
     */
    protected static function repository(ContainerInterface $container, string $id): EntityRepository
    {
        $repository = $container->get($id);
        self::assertInstanceOf(EntityRepository::class, $repository, sprintf('Service "%s" is not a repository.', $id));

        return $repository;
    }

    /** The shop's own DBAL connection, for reads a plain repository search cannot express. */
    protected static function connection(ContainerInterface $container): Connection
    {
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * The merchant-side bridge gateway under test. The collaborator graph is
     * hand-built from container services. `scripts/sync-to-shop.sh` installs
     * the plugin into this shop via composer's path repository, so
     * `src/Resources/config/services.php` does load here — `preflight()` a few
     * lines below resolves `ServicingPreflight` from the container, and the
     * buyer-side suites resolve `BuyerQuoteGatewayInterface` and
     * `SalesChannelContextResolver` straight from it (BuyerQuoteFlowTest,
     * SalesChannelContextResolverTest), while UcpQuoteEndpointTest routes real
     * HTTP through the controller `services.php` registers.
     *
     * The last step still goes through `QuoteGatewayFactory::create()` rather
     * than `new SwagCommercialQuoteGateway(...)`, so the license gate every
     * caller depends on is exercised by all of these tests instead of only by
     * GatewayWiringTest, which covers `services.php` itself — the compiled
     * container, not the hand-built graph these helpers use.
     *
     * Kept on the base class so the integration test classes share one
     * override point instead of duplicating a helper.
     */
    protected static function gateway(): QuoteGatewayInterface
    {
        $gateway = static::gatewayFactory()->create();
        self::assertInstanceOf(
            SwagCommercialQuoteGateway::class,
            $gateway,
            'QuoteGatewayFactory returned no gateway, so SwagCommercial is absent or the '
            . CommercialAvailability::LICENSE_TOGGLE
            . ' license toggle is off in this shop.',
        );

        return $gateway;
    }

    /**
     * The buyer-side counterpart of `gateway()`: resolved straight from the
     * container, since `services.php` compiles here (see `gateway()`'s own
     * docblock) and this one is a factory-produced service under its own
     * interface, exactly like `QuoteGatewayInterface`.
     *
     * Kept on the base class for the same reason `gateway()` is: one override
     * point shared by every integration test that needs it, rather than each
     * test class duplicating the accessor.
     */
    protected static function buyerGateway(): BuyerQuoteGatewayInterface
    {
        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        return $gateway;
    }

    /**
     * The preflight as `services.php` wires it, reading the live shop's
     * configuration. Resolved from the container rather than hand-built so the
     * servicing tests exercise the real reader — a handler built by hand still
     * gets the wired gate.
     */
    protected static function preflight(): ServicingPreflight
    {
        $preflight = static::getContainer()->get(ServicingPreflight::class);
        self::assertInstanceOf(ServicingPreflight::class, $preflight);

        return $preflight;
    }

    /** The factory with its collaborators wired the way `services.php` wires them. */
    protected static function gatewayFactory(): QuoteGatewayFactory
    {
        /** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
        $quoteRepository = static::getContainer()->get('quote.repository');
        /** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $lineItemRepository */
        $lineItemRepository = static::getContainer()->get('quote_line_item.repository');

        // Same constants `services.php` references, so this graph cannot drift
        // from the wired one on the one thing static analysis cannot check.
        $recalculator = new QuoteRecalculator(
            static::commercialService(CommercialAvailability::CONTEXT_RESTORER),
            static::commercialService(CommercialAvailability::QUOTE_CALCULATOR),
        );
        /** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $productRepository */
        $productRepository = static::getContainer()->get('product.repository');
        $productAdder = new VariantRejectingProductAdder(
            new SwagCommercialProductAdder(static::commercialService(CommercialAvailability::QUOTE_MANIPULATION)),
            $productRepository,
        );
        $commentWriter =
            new SwagCommercialCommentWriter(static::commercialService(CommercialAvailability::QUOTE_COMMENTER));

        $stateMachineRegistry = static::getContainer()->get(StateMachineRegistry::class);
        self::assertInstanceOf(StateMachineRegistry::class, $stateMachineRegistry);

        $capabilities = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $capabilities);

        return new QuoteGatewayFactory(
            new QuoteSnapshotReader($quoteRepository, new QuoteVersionResolver(), $capabilities),
            new QuoteWriters(
                new QuoteLineItemWriter($lineItemRepository, $capabilities),
                new QuoteWriter($quoteRepository),
                $recalculator,
                $productAdder,
            ),
            new QuoteLifecycleWriters($commentWriter, new QuoteStateTransitioner($stateMachineRegistry)),
        );
    }
}
