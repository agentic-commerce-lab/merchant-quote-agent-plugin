<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp;

use MerchantQuoteAgentPlugin\Ucp\UcpAvailability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class UcpAvailabilityTest extends TestCase
{
    /**
     * The gate reads the bundle list, not the classpath — see UcpAvailability
     * on the deactivation a class-existence gate breaks. These are the four
     * shapes it can be handed.
     */
    public function testRegistrationFollowsTheBundleList(): void
    {
        self::assertFalse(UcpAvailability::isRegistered(null));

        $withoutParameter = new ContainerBuilder();
        self::assertFalse(UcpAvailability::isRegistered($withoutParameter));

        $withoutBundle = new ContainerBuilder();
        $withoutBundle->setParameter('kernel.bundles', ['Framework' => 'Shopware\\Core\\Framework\\Framework']);
        self::assertFalse(UcpAvailability::isRegistered($withoutBundle));

        $withBundle = new ContainerBuilder();
        $withBundle->setParameter('kernel.bundles', ['UcpSdkBundle' => 'Ucp\\Sdk\\Symfony\\UcpSdkBundle']);
        self::assertTrue(UcpAvailability::isRegistered($withBundle));
    }
}
