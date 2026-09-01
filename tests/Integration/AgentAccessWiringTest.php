<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Identity\AgentAdmittingRuntimeConfigurationResolver;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
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

    public function testOurFactoryBuildsTheValidatorTheContainerResolves(): void
    {
        $validator = static::getContainer()->get(UrlSafetyValidator::class);

        self::assertInstanceOf(
            UrlSafetyValidator::class,
            $validator,
            'the SDK bundle no longer defines this service under this id',
        );

        // With no allow-any-agent channel in scope, our factory must reproduce
        // the bundle's own behaviour: a host nobody allowlisted stays rejected.
        $this->expectException(ValidationException::class);
        $validator->assertAllowed('https://agent.example/.well-known/ucp');
    }
}
