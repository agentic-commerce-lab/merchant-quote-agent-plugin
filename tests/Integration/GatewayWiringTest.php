<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\SwagCommercialQuoteGateway;

/**
 * Covers `src/Resources/config/services.php` as far as is reachable without
 * installing the plugin.
 *
 * That caveat shapes the whole class. The plugin cannot be installed into this
 * shop — `composer.json` declares `php ^8.3` against a default PHP of 8.2,
 * requires `ucp-php-sdk/core >=0.0.5` where 0.0.2 is what exists, and
 * `cuyz/valinor` is absent from the shop's vendor tree — so Shopware never
 * loads our `services.php` and `getContainer()->get(QuoteGatewayInterface::class)`
 * cannot resolve. What is left is split by who can check it:
 *
 * - **The analyzer** covers the file's structure. `services.php` lives under
 *   `src/`, so `mago analyze` parses it, resolves every `::class` and every
 *   `use`, and would reject a misspelled service class or a missing
 *   `service()` import. No test needs to restate that.
 * - **This test** covers the one thing no analyzer can see: that the four
 *   SwagCommercial ids the file injects are ids a shop with SwagCommercial
 *   actually has. They are string literals by necessity (ADR 0001), so a typo
 *   or an upstream rename is invisible until runtime. It reads them from the
 *   same constants `services.php` references, so there is one copy of each id
 *   in the codebase and this follows it.
 * - **Nothing yet** covers Shopware compiling the file in a real container:
 *   the autowire/autoconfigure defaults resolving, and
 *   `QuoteGatewayInterface` surviving compilation as a factory-produced
 *   service. That needs an install, and is reported as such.
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
