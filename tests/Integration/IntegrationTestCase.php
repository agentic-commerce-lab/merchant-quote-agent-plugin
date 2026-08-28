<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
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
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\System\StateMachine\StateMachineRegistry;

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
     * The bridge gateway under test. The collaborator graph is hand-built from
     * container services because the plugin is deliberately not installed into
     * this shop (its composer constraints are unsatisfiable here, see Task 1),
     * so `src/Resources/config/services.php` never loads and
     * `QuoteGatewayInterface` cannot be resolved from the container.
     *
     * The last step still goes through `QuoteGatewayFactory::create()` rather
     * than `new SwagCommercialQuoteGateway(...)`, so the license gate every
     * caller depends on is exercised by all of these tests instead of only by
     * GatewayWiringTest. What remains unverified without an install is
     * `services.php` itself; GatewayWiringTest covers as much of it as is
     * reachable from outside the container.
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

        return new QuoteGatewayFactory(
            new QuoteSnapshotReader($quoteRepository, new QuoteVersionResolver()),
            new QuoteWriters(
                new QuoteLineItemWriter($lineItemRepository),
                new QuoteWriter($quoteRepository),
                $recalculator,
                $productAdder,
            ),
            new QuoteLifecycleWriters($commentWriter, new QuoteStateTransitioner($stateMachineRegistry)),
        );
    }
}
