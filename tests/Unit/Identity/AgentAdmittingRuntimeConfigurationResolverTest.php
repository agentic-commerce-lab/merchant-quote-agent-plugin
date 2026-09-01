<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelResolution;
use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use MerchantQuoteAgentPlugin\Identity\AgentAdmittingRuntimeConfigurationResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

#[CoversClass(AgentAdmittingRuntimeConfigurationResolver::class)]
final class AgentAdmittingRuntimeConfigurationResolverTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const AGENT_HEADER = 'manual/1.0; profile="https://agent.example/.well-known/ucp"';

    public function testItAdmitsThePresentedHostWhileTheFlagIsOn(): void
    {
        $resolved = $this->resolve(flagOn: true, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame(['chatgpt.com', 'agent.example'], $resolved->allowedProfileHosts);
        self::assertSame(['chatgpt.com', 'agent.example'], $resolved->allowedAgentDomains);
    }

    public function testItChangesNothingWhileTheFlagIsOff(): void
    {
        $resolved = $this->resolve(flagOn: false, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame(['chatgpt.com'], $resolved->allowedProfileHosts);
        self::assertSame(['chatgpt.com'], $resolved->allowedAgentDomains);
    }

    public function testItChangesNothingWithoutAUsableHeader(): void
    {
        self::assertSame(['chatgpt.com'], $this->resolve(flagOn: true, headers: [])->allowedProfileHosts);
        self::assertSame(
            ['chatgpt.com'],
            $this->resolve(flagOn: true, headers: ['UCP-Agent' => 'manual/1.0'])->allowedProfileHosts,
        );
    }

    public function testItDoesNotAdmitAHostTwice(): void
    {
        $resolved = $this->resolve(flagOn: true, headers: [
            'UCP-Agent' => 'manual/1.0; profile="https://chatgpt.com/.well-known/ucp"',
        ]);

        self::assertSame(['chatgpt.com'], $resolved->allowedProfileHosts);
    }

    public function testItPreservesEveryOtherRuntimeSetting(): void
    {
        $resolved = $this->resolve(flagOn: true, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame('2026-04-08', $resolved->version);
        self::assertSame('https://shop.example', $resolved->baseUri);
        self::assertTrue($resolved->idempotencyRequired);
        self::assertSame(['catalog'], $resolved->enabledCapabilities);
        self::assertSame('tenant-1', $resolved->tenantIdentifier);
    }

    public function testItChangesNothingWhenTheHostMatchesNoSalesChannel(): void
    {
        $inner = $this->inner();
        $flags = new AgentAccessFlags($this->systemConfig(true));
        $resolver = $this->createMock(CustomerContextResolverInterface::class);
        $resolver->method('resolveByHost')->willReturn(null);

        $decorator = new AgentAdmittingRuntimeConfigurationResolver($inner, $flags, $resolver);

        self::assertSame(
            ['chatgpt.com'],
            $decorator->resolve($this->request(['UCP-Agent' => self::AGENT_HEADER]))->allowedProfileHosts,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function resolve(bool $flagOn, array $headers): RuntimeConfiguration
    {
        $resolver = $this->createMock(CustomerContextResolverInterface::class);
        $resolver
            ->method('resolveByHost')
            ->willReturn(new SalesChannelResolution(self::SALES_CHANNEL_ID, 'l', 'c', 'd'));

        $decorator = new AgentAdmittingRuntimeConfigurationResolver(
            $this->inner(),
            new AgentAccessFlags($this->systemConfig($flagOn)),
            $resolver,
        );

        return $decorator->resolve($this->request($headers));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): HttpRequest
    {
        return new HttpRequest('GET', 'https://shop.example/ucp/quotes', $headers);
    }

    private function inner(): RuntimeConfigurationResolverInterface
    {
        $configuration = new RuntimeConfiguration(
            '2026-04-08',
            'https://shop.example',
            allowedProfileHosts: ['chatgpt.com'],
            allowedAgentDomains: ['chatgpt.com'],
            enabledCapabilities: ['catalog'],
            tenantIdentifier: 'tenant-1',
            idempotencyRequired: true,
        );

        $inner = $this->createMock(RuntimeConfigurationResolverInterface::class);
        $inner->method('resolve')->willReturn($configuration);

        return $inner;
    }

    private function systemConfig(bool $flagOn): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($flagOn);

        return $config;
    }
}
