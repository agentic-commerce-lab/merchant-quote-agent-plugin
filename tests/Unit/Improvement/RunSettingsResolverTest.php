<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettingsReader;
use MerchantQuoteAgentPlugin\Improvement\RunSettingsResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * I1: a channel whose configured strategy is dangling or archived must be
 * skipped for the night, not abort ImprovementGenerator's whole tick.
 * QuoteAgentSettingsSource::forSalesChannel() reports that by THROWING
 * InvalidQuoteAgentConfiguration (see its own docblock), and this class's own
 * docblock already promised "null when the agent underneath it is off or
 * misconfigured" -- these tests are what makes that promise true.
 */
final class RunSettingsResolverTest extends TestCase
{
    public function testAMisconfiguredAgentIsSkippedNotThrown(): void
    {
        $agent = $this->createMock(QuoteAgentSettingsSource::class);
        $agent
            ->method('forSalesChannel')
            ->willThrowException(new InvalidQuoteAgentConfiguration(['dangling negotiationStrategyId']));

        $resolver = $this->resolver(improvementEnabled: true, agent: $agent);

        self::assertNull($resolver->resolve('sc-1'));
    }

    public function testAWorkingChannelStillResolves(): void
    {
        $agent = $this->createMock(QuoteAgentSettingsSource::class);
        $agent
            ->method('forSalesChannel')
            ->willReturn(\MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture::settings());

        $resolver = $this->resolver(improvementEnabled: true, agent: $agent);

        self::assertNotNull($resolver->resolve('sc-1'));
    }

    private function resolver(bool $improvementEnabled, QuoteAgentSettingsSource $agent): RunSettingsResolver
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn(string $key): mixed => str_replace(
            ImprovementSettingsReader::DOMAIN,
            '',
            $key,
        ) === 'improvementEnabled'
                ? $improvementEnabled
                : null);

        return new RunSettingsResolver(new ImprovementSettingsReader($config, $agent), $agent);
    }
}
