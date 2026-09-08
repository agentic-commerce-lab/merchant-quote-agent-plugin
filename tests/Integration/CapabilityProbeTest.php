<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilitiesFactory;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;

/**
 * The factory reads the live DAL registry, so this is the only place it can be
 * checked at all — and it is checked against both shops, because the whole
 * point of the object is that the two answer differently.
 *
 * The assertions are internally consistent rather than hardcoded per shop: the
 * suite runs against whichever container SHOP_CONTAINER names, and hardcoding
 * "this shop is legacy" would fail the moment someone upgrades it. What is
 * pinned instead is that the probe agrees with the container's own schema, and
 * that the four flags move together in the combinations that actually ship.
 */
final class CapabilityProbeTest extends IntegrationTestCase
{
    public function testTheProbeAgreesWithTheShopsOwnSchema(): void
    {
        $registry = static::getContainer()->get(DefinitionInstanceRegistry::class);
        self::assertInstanceOf(DefinitionInstanceRegistry::class, $registry);

        $capabilities = (new CommercialCapabilitiesFactory($registry))->create();

        self::assertSame(
            $registry->getByEntityName('quote_line_item')->getFields()->get('requestedPrice') !== null,
            $capabilities->lineItemAsks,
        );
        self::assertSame(
            $registry->getByEntityName('quote_line_item')->getFields()->get('deletedAt') !== null,
            $capabilities->softDeleteLines,
        );
        self::assertSame(
            $registry->getByEntityName('quote_comment')->getFields()->get('quoteLineItemId') !== null,
            $capabilities->lineScopedComments,
        );
    }

    public function testTheContainerServiceIsTheProbedOne(): void
    {
        $fromContainer = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $fromContainer);

        $registry = static::getContainer()->get(DefinitionInstanceRegistry::class);
        self::assertInstanceOf(DefinitionInstanceRegistry::class, $registry);

        self::assertEquals((new CommercialCapabilitiesFactory($registry))->create(), $fromContainer);
    }

    /**
     * The two combinations that ship. A shop with line-item asks but no
     * soft-delete would be a backport we have not accounted for, and the read
     * path's assumptions deserve to be re-checked before it is served.
     */
    public function testTheShopIsOneOfTheTwoKnownProfiles(): void
    {
        $capabilities = static::getContainer()->get(CommercialCapabilities::class);
        self::assertInstanceOf(CommercialCapabilities::class, $capabilities);

        // assertContainsEquals(), not assertContains(): PHPUnit's assertContains()
        // compares objects by identity, so two structurally equal
        // CommercialCapabilities instances (value objects, `==` true but never
        // `===`, since modern()/legacy() build a fresh one each call) would
        // never match — assertContainsEquals() compares by value instead, which
        // is what this needs.
        self::assertContainsEquals(
            $capabilities,
            [CommercialCapabilities::modern(), CommercialCapabilities::legacy()],
            'This shop mixes capabilities in a combination no release ships. '
            . 'Re-read the bridge guards before trusting them here.',
        );
    }
}
