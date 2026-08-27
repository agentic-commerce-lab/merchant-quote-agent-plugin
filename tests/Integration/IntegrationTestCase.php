<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineItemWriter;
use MerchantQuoteAgentPlugin\Bridge\QuoteRecalculator;
use MerchantQuoteAgentPlugin\Bridge\QuoteSnapshotReader;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use MerchantQuoteAgentPlugin\Bridge\SwagCommercialQuoteGateway;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\DatabaseTransactionBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

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
     * The bridge gateway under test, hand-constructed from container
     * services: the plugin is deliberately not installed into this shop
     * (composer constraints are unsatisfiable, see Task 1), so `services.php`
     * never loads and `QuoteGatewayInterface` cannot be resolved from the
     * container. Task 9 replaces this body with a DI lookup once it can be.
     * Kept here so the six integration test classes share one override point
     * instead of duplicating a helper.
     */
    protected static function gateway(): QuoteGatewayInterface
    {
        /** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
        $quoteRepository = static::getContainer()->get('quote.repository');
        /** @var \Shopware\Core\Framework\DataAbstractionLayer\EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $lineItemRepository */
        $lineItemRepository = static::getContainer()->get('quote_line_item.repository');

        $reader = new QuoteSnapshotReader($quoteRepository, new QuoteVersionResolver());
        $lineItemWriter = new QuoteLineItemWriter($lineItemRepository);
        $recalculator = new QuoteRecalculator(
            static::commercialService(
                'Shopware\Commercial\B2B\QuoteManagement\Domain\SalesChannelContextRestorer\SalesChannelContextRestorer',
            ),
            static::commercialService('Shopware\Commercial\B2B\QuoteManagement\Domain\Recalculation\QuoteCalculator'),
        );

        return new SwagCommercialQuoteGateway($reader, $lineItemWriter, $recalculator);
    }
}
