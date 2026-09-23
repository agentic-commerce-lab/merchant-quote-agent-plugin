<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CommercialAvailabilityTest extends TestCase
{
    public function testIsLicensedNeverThrowsWhenSwagCommercialIsAbsent(): void
    {
        self::assertFalse(CommercialAvailability::isLicensed());
    }

    /**
     * This suite runs without SwagCommercial's classes, so every one of these
     * shapes must answer false regardless of the bundle logic: no container,
     * no `kernel.bundles` parameter, and the SwagCommercial umbrella listed
     * without the quote bundle. That the gate actually keys on the quote
     * bundle rather than the umbrella is pinned by GateMatrix's fifth shop
     * (`vendoredButInactive`), not here.
     */
    public function testRegistrationNeedsTheQuoteBundle(): void
    {
        self::assertFalse(CommercialAvailability::isRegistered(null));

        $withoutParameter = new ContainerBuilder();
        self::assertFalse(CommercialAvailability::isRegistered($withoutParameter));

        $umbrellaOnly = new ContainerBuilder();
        $umbrellaOnly->setParameter('kernel.bundles', ['SwagCommercial' => 'Shopware\\Commercial\\SwagCommercial']);
        self::assertFalse(CommercialAvailability::isRegistered($umbrellaOnly));
    }

    /**
     * This suite runs without SwagCommercial's classes, so a listed bundle
     * must still answer false: the probe is bundle AND classes. The true case
     * needs the classes aliased, which is one-way, so GateMatrix's present
     * shops cover it in a thrown-away process.
     */
    public function testAListedBundleIsNotEnoughWithoutTheClasses(): void
    {
        $withBundle = new ContainerBuilder();
        $withBundle->setParameter('kernel.bundles', [
            'QuoteManagement' => 'Shopware\\Commercial\\B2B\\QuoteManagement\\QuoteManagement',
        ]);

        self::assertFalse(CommercialAvailability::isRegistered($withBundle));
    }
}
