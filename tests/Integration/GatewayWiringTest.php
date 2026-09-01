<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\SwagCommercialQuoteGateway;

/**
 * Covers `src/Resources/config/services.php` from outside the container.
 *
 * `services.php` does compile in this shop's container — BuyerQuoteFlowTest
 * and SalesChannelContextResolverTest resolve `BuyerQuoteGatewayInterface`
 * and `SalesChannelContextResolver` straight from it, and UcpQuoteEndpointTest
 * routes real HTTP through the controller it registers, which proves
 * autowire/autoconfigure defaults resolve and the buyer-side services survive
 * compilation. What those tests cannot see is what this one is for:
 *
 * - **The analyzer** covers the file's structure. `services.php` lives under
 *   `src/`, so `mago analyze` parses it, resolves every `::class` and every
 *   `use`, and would reject a misspelled service class or a missing
 *   `service()` import. No test needs to restate that.
 * - **This test** covers the one thing no analyzer can see: that the
 *   SwagCommercial ids the file injects (merchant-side and buyer-side) are
 *   ids a shop with SwagCommercial actually has. They are string literals by
 *   necessity (ADR 0001), so a typo or an upstream rename is invisible until
 *   runtime. It reads them from the same constants `services.php` references,
 *   so there is one copy of each id in the codebase and this follows it.
 */
final class GatewayWiringTest extends IntegrationTestCase
{
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
     * The nine ids the buyer-side gateway and its line-pricing collaborator
     * inject. Separate from the merchant-side test above because these come
     * from a different `services.php` block, registered on
     * `SwagCommercialBuyerQuoteGateway`/`CommercialQuoteLinePricing`.
     */
    public function testTheBuyerGatewayServiceIdsResolveInThisShop(): void
    {
        foreach (self::injectedBuyerCommercialIds() as $id) {
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

    /** @return list<non-empty-string> */
    private static function injectedBuyerCommercialIds(): array
    {
        return [
            CommercialAvailability::QUOTE_REQUEST_ROUTE,
            CommercialAvailability::QUOTE_SEND_REQUEST_ROUTE,
            CommercialAvailability::QUOTE_LINE_ITEM_ROUTE,
            CommercialAvailability::QUOTE_LOAD_ROUTE,
            CommercialAvailability::QUOTE_LISTING_ROUTE,
            CommercialAvailability::QUOTE_REQUEST_CHANGE_ROUTE,
            CommercialAvailability::QUOTE_DECLINE_ROUTE,
            CommercialAvailability::QUOTE_ORDER_ROUTE,
            CommercialAvailability::CUSTOMER_SPECIFIC_FEATURE_SERVICE,
        ];
    }
}
