<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\SwagCommercialQuoteGateway;
use MerchantQuoteAgentPlugin\Servicing\Attempt\ServicingAttemptDefinition;
use MerchantQuoteAgentPlugin\Servicing\Attempt\ServicingAttemptStoreInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingHandler;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingSubscriber;
use Shopware\Core\Framework\Context;

/**
 * Exercises the installed plugin's compiled service graph against a licensed
 * shop, including the raw Commercial ids that static analysis cannot verify.
 */
final class GatewayWiringTest extends IntegrationTestCase
{
    public function testLiveSnapshotCarriesSalesChannelIdentity(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $snapshot = static::gateway()->fetchSnapshot($quoteId);

        self::assertNotSame('', $snapshot->identity->salesChannelId);
    }

    public function testInstalledContainerResolvesTheServicingGraph(): void
    {
        $services = [
            ServicingAttemptDefinition::class,
            ServicingAttemptStoreInterface::class,
            QuoteServicingHandler::class,
            QuoteServicingSubscriber::class,
        ];

        foreach ($services as $id) {
            self::assertInstanceOf($id, static::getContainer()->get($id));
        }
    }

    /**
     * The ids `services.php` injects, resolved against this shop. A failure
     * here means SwagCommercial moved a service, and the fix is the constant
     * on `CommercialAvailability` — the ids live nowhere else.
     */
    public function testTheCommercialServiceIdsServicesFileInjectsResolveInThisShop(): void
    {
        foreach (self::injectedCommercialIds() as $id) {
            // Fails with commercialService()'s own diagnostic if the id is wrong.
            static::commercialService($id);
        }
    }

    /**
     * Class existence is stage one of ADR 0001's gate and decides whether
     * `services.php` registers the bridge block at all, so a shop where the
     * license toggle is on but the classes are missing must not exist. Cheap,
     * and it pins the direction of the implication.
     */
    public function testALicensedShopIsAlsoAClassAvailableShop(): void
    {
        self::assertTrue(CommercialAvailability::isAvailableByClass());
        self::assertTrue(
            CommercialAvailability::isLicensed(),
            'This shop does not hold '
            . CommercialAvailability::LICENSE_TOGGLE
            . ', so no integration test in this suite can exercise the bridge.',
        );
    }

    /**
     * The gate on the licensed path — the only path this shop can exercise.
     * `CommercialAvailabilityTest` covers the degraded path, where absence is
     * the only outcome reachable.
     */
    public function testTheFactoryProducesAGatewayOnALicensedShop(): void
    {
        self::assertInstanceOf(SwagCommercialQuoteGateway::class, static::gatewayFactory()->create());
    }

    /** @return list<non-empty-string> */
    private static function injectedCommercialIds(): array
    {
        return [
            CommercialAvailability::QUOTE_MANIPULATION,
            CommercialAvailability::QUOTE_COMMENTER,
            CommercialAvailability::CONTEXT_RESTORER,
            CommercialAvailability::QUOTE_CALCULATOR,
        ];
    }
}
