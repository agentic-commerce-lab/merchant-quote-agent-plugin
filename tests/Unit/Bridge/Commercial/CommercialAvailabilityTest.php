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
     * The gate reads the bundle list, like UcpAvailability's, and keys on the
     * quote bundle rather than the SwagCommercial umbrella: the umbrella alone
     * says nothing about whether `quote.repository` exists.
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
