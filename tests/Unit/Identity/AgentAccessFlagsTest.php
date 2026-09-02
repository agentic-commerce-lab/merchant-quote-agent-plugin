<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(AgentAccessFlags::class)]
final class AgentAccessFlagsTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    public function testItIsOffWhenNothingIsConfigured(): void
    {
        self::assertFalse($this->flags(null)->allowAnyAgent(self::SALES_CHANNEL_ID));
    }

    public function testItReadsTheFlagForTheGivenSalesChannel(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config
            ->expects(self::once())
            ->method('get')
            ->with(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, self::SALES_CHANNEL_ID)
            ->willReturn(true);

        self::assertTrue((new AgentAccessFlags($config))->allowAnyAgent(self::SALES_CHANNEL_ID));
    }

    public function testItTreatsAnUnresolvedSalesChannelAsOff(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->expects(self::never())->method('get');

        self::assertFalse((new AgentAccessFlags($config))->allowAnyAgent(null));
    }

    public function testItAcceptsOnlyABooleanTrue(): void
    {
        // system_config round-trips JSON, so a stale string must not read as on.
        self::assertFalse($this->flags('true')->allowAnyAgent(self::SALES_CHANNEL_ID));
        self::assertFalse($this->flags(1)->allowAnyAgent(self::SALES_CHANNEL_ID));
        // `system:config:set key false` without -j stores the string, and
        // (bool) 'false' is true -- so a hand-set value must fail closed.
        self::assertFalse($this->flags('false')->allowAnyAgent(self::SALES_CHANNEL_ID));
        self::assertTrue($this->flags(true)->allowAnyAgent(self::SALES_CHANNEL_ID));
    }

    private function flags(mixed $stored): AgentAccessFlags
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($stored);

        return new AgentAccessFlags($config);
    }
}
