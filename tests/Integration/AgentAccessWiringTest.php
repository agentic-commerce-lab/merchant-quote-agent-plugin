<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Identity\AgentAdmittingRuntimeConfigurationResolver;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/**
 * The runtime hooks only work while they are actually the services the container
 * hands out. Both are attached to definitions owned by other packages, so a
 * rename or a competing definition would otherwise degrade silently.
 */
final class AgentAccessWiringTest extends IntegrationTestCase
{
    public function testOurDecoratorIsWhatTheContainerResolvesForTheRuntimeConfiguration(): void
    {
        $resolver = static::getContainer()->get(RuntimeConfigurationResolverInterface::class);

        self::assertInstanceOf(
            AgentAdmittingRuntimeConfigurationResolver::class,
            $resolver,
            'the Agentic Commerce plugin no longer aliases this interface, or another decoration replaced ours',
        );
    }
}
