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
use Ucp\Sdk\Enum\SignaturePolicy;
use Ucp\Sdk\Enum\Transport;
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

        self::assertSame(['profiles.example', 'agent.example'], $resolved->allowedProfileHosts);
        self::assertSame(['agents.example', 'agent.example'], $resolved->allowedAgentDomains);
    }

    public function testItChangesNothingWhileTheFlagIsOff(): void
    {
        $resolved = $this->resolve(flagOn: false, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame(['profiles.example'], $resolved->allowedProfileHosts);
        self::assertSame(['agents.example'], $resolved->allowedAgentDomains);
    }

    public function testItChangesNothingWithoutAUsableHeader(): void
    {
        self::assertSame(['profiles.example'], $this->resolve(flagOn: true, headers: [])->allowedProfileHosts);
        self::assertSame(
            ['profiles.example'],
            $this->resolve(flagOn: true, headers: ['UCP-Agent' => 'manual/1.0'])->allowedProfileHosts,
        );
    }

    public function testItDoesNotAdmitAHostTwice(): void
    {
        $resolved = $this->resolve(flagOn: true, headers: [
            'UCP-Agent' => 'manual/1.0; profile="https://profiles.example/.well-known/ucp"',
        ]);

        self::assertSame(['profiles.example'], $resolved->allowedProfileHosts);
    }

    public function testItPreservesEveryOtherRuntimeSetting(): void
    {
        $resolved = $this->resolve(flagOn: true, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame('2026-04-08', $resolved->version);
        self::assertSame('https://shop.example', $resolved->baseUri);
        self::assertSame(SignaturePolicy::Strict, $resolved->signaturePolicy);
        self::assertTrue($resolved->idempotencyRequired);
        self::assertSame(['2026-04-08', '2026-01-01'], $resolved->supportedVersions);
        self::assertSame([Transport::Rest, Transport::Mcp], $resolved->transports);
        self::assertSame(['catalog'], $resolved->enabledCapabilities);
        self::assertSame('tenant-1', $resolved->tenantIdentifier);
        self::assertSame(['mcp' => 'https://shop.example/ucp/mcp'], $resolved->transportEndpoints);
        self::assertTrue($resolved->profileFetchingDevelopmentMode);
    }

    public function testItChangesNothingWhenTheHostMatchesNoSalesChannel(): void
    {
        $inner = $this->inner();
        $flags = new AgentAccessFlags($this->systemConfig(true));
        $resolver = $this->createMock(CustomerContextResolverInterface::class);
        $resolver->method('resolveByHost')->willReturn(null);

        $decorator = new AgentAdmittingRuntimeConfigurationResolver($inner, $flags, $resolver);

        self::assertSame(
            ['profiles.example'],
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
        // Every field is deliberately set away from its constructor default,
        // and the two widened lists are deliberately different, so that
        // dropping a field or transposing the two lists fails a test instead
        // of matching a default that happened to agree.
        $configuration = new RuntimeConfiguration(
            '2026-04-08',
            'https://shop.example',
            signaturePolicy: SignaturePolicy::Strict,
            idempotencyRequired: true,
            allowedProfileHosts: ['profiles.example'],
            allowedAgentDomains: ['agents.example'],
            supportedVersions: ['2026-04-08', '2026-01-01'],
            transports: [Transport::Rest, Transport::Mcp],
            enabledCapabilities: ['catalog'],
            tenantIdentifier: 'tenant-1',
            transportEndpoints: ['mcp' => 'https://shop.example/ucp/mcp'],
            profileFetchingDevelopmentMode: true,
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
