<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\AssistantAvailability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AssistantAvailabilityTest extends TestCase
{
    /**
     * Same four shapes UcpAvailabilityTest checks, and for the same reason —
     * see AssistantAvailability on why this reads the bundle list rather than
     * the classpath.
     */
    public function testRegistrationFollowsTheBundleList(): void
    {
        self::assertFalse(AssistantAvailability::isRegistered(null));

        $withoutParameter = new ContainerBuilder();
        self::assertFalse(AssistantAvailability::isRegistered($withoutParameter));

        $withoutBundle = new ContainerBuilder();
        $withoutBundle->setParameter('kernel.bundles', ['Framework' => 'Shopware\\Core\\Framework\\Framework']);
        self::assertFalse(AssistantAvailability::isRegistered($withoutBundle));

        $withBundle = new ContainerBuilder();
        $withBundle->setParameter('kernel.bundles', [
            'SwagAssistantStarterKit' => 'Swag\\AssistantStarterKit\\SwagAssistantStarterKit',
        ]);
        self::assertTrue(AssistantAvailability::isRegistered($withBundle));
    }
}
