<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

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
     * The bridge gateway under test. Task 4 fills this in by hand-constructing
     * from container services; Task 9 replaces the body with a DI lookup once
     * QuoteGatewayInterface is registered. Kept here so the six integration
     * test classes share one override point instead of duplicating a helper.
     *
     * @throws \LogicException until plan Task 4
     */
    protected static function gateway(): never
    {
        throw new \LogicException('Not available until plan Task 4.');
    }
}
