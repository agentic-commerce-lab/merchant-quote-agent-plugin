<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelResolution;
use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use MerchantQuoteAgentPlugin\Identity\AgentProfileHostValidatorFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/**
 * UrlSafetyValidator exposes no getters (it is the SDK's own `@internal`
 * class), so the only way to observe what a factory built is reflection on
 * its private `allowedHosts` property -- reading it beats driving
 * assertAllowed() with real hostnames, which would perform live DNS lookups.
 */
#[CoversClass(AgentProfileHostValidatorFactory::class)]
final class AgentProfileHostValidatorFactoryTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const AGENT_HEADER = 'manual/1.0; profile="https://agent.example/.well-known/ucp"';

    public function testItAdmitsThePresentedHostWhileTheFlagIsOn(): void
    {
        $validator = $this->create(flagOn: true, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame(['chatgpt.com', 'agent.example'], self::allowedHosts($validator));
    }

    public function testItChangesNothingWhileTheFlagIsOff(): void
    {
        $validator = $this->create(flagOn: false, headers: ['UCP-Agent' => self::AGENT_HEADER]);

        self::assertSame(['chatgpt.com'], self::allowedHosts($validator));
    }

    public function testItDoesNotAdmitAHostTwice(): void
    {
        $validator = $this->create(flagOn: true, headers: [
            'UCP-Agent' => 'manual/1.0; profile="https://chatgpt.com/.well-known/ucp"',
        ]);

        self::assertSame(['chatgpt.com'], self::allowedHosts($validator));
    }

    /**
     * Every source of "nothing to widen from" in one place: a missing or
     * unparseable header, a host no sales channel serves, and no request in
     * scope at all -- each must leave the configured hosts untouched.
     */
    public function testItChangesNothingWithoutAWideningSignal(): void
    {
        self::assertSame(['chatgpt.com'], self::allowedHosts($this->create(flagOn: true, headers: [])));
        self::assertSame(
            ['chatgpt.com'],
            self::allowedHosts($this->create(flagOn: true, headers: ['UCP-Agent' => 'manual/1.0'])),
        );

        $contextResolver = $this->createMock(CustomerContextResolverInterface::class);
        $contextResolver->method('resolveByHost')->willReturn(null);
        $unattributable = new AgentProfileHostValidatorFactory(
            $this->sdkConfiguration(),
            new AgentAccessFlags($this->systemConfig(true)),
            $contextResolver,
            $this->requestStack(['UCP-Agent' => self::AGENT_HEADER]),
        );
        self::assertSame(['chatgpt.com'], self::allowedHosts($unattributable->create()));

        $noRequest = new AgentProfileHostValidatorFactory(
            $this->sdkConfiguration(),
            new AgentAccessFlags($this->systemConfig(true)),
            $this->createMock(CustomerContextResolverInterface::class),
            new RequestStack(),
        );
        self::assertSame(['chatgpt.com'], self::allowedHosts($noRequest->create()));
    }

    public function testItPassesThroughTheDevelopmentModeFlag(): void
    {
        $factory = new AgentProfileHostValidatorFactory(
            $this->sdkConfiguration(developmentMode: true),
            new AgentAccessFlags($this->systemConfig(false)),
            $this->createMock(CustomerContextResolverInterface::class),
            new RequestStack(),
        );

        $property = new \ReflectionProperty(UrlSafetyValidator::class, 'profileFetchingDevelopmentMode');
        self::assertTrue($property->getValue($factory->create()));
    }

    /**
     * @param array<string, string> $headers
     */
    private function create(bool $flagOn, array $headers): UrlSafetyValidator
    {
        $contextResolver = $this->createMock(CustomerContextResolverInterface::class);
        $contextResolver
            ->method('resolveByHost')
            ->willReturn(new SalesChannelResolution(self::SALES_CHANNEL_ID, 'l', 'c', 'd'));

        $factory = new AgentProfileHostValidatorFactory(
            $this->sdkConfiguration(),
            new AgentAccessFlags($this->systemConfig($flagOn)),
            $contextResolver,
            $this->requestStack($headers),
        );

        return $factory->create();
    }

    /**
     * @return list<string>
     */
    private static function allowedHosts(UrlSafetyValidator $validator): array
    {
        $property = new \ReflectionProperty(UrlSafetyValidator::class, 'allowedHosts');

        /** @var list<string> */
        return $property->getValue($validator);
    }

    /**
     * @param array<string, string> $headers
     */
    private function requestStack(array $headers): RequestStack
    {
        $request = Request::create('https://shop.example/ucp/quotes');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function sdkConfiguration(bool $developmentMode = false): UcpSdkConfiguration
    {
        return new UcpSdkConfiguration(
            version: '2026-04-08',
            baseUri: 'https://shop.example',
            allowedProfileHosts: ['chatgpt.com'],
            signaturePolicy: 'required',
            allowedAgentDomains: ['chatgpt.com'],
            idempotencyRequired: true,
            idempotencyTtl: 3600,
            maxRequestBodyBytes: 1_000_000,
            platformProfileCacheTtl: 3600,
            negotiationSessionTtl: 3600,
            signatureMaxLifetimeSeconds: 300,
            oauthAuthorizationCodeTtl: 60,
            supportedVersions: [],
            signingKeysAutoGenerate: true,
            signingKeysDefaultKid: 'default',
            signingKeysAlgorithm: 'ed25519',
            signingKeysRetireAfter: 'P30D',
            signingKeysRetiredKeyRetention: 'P30D',
            idempotencyMaxStoredResponseBytes: 1_000_000,
            webhookTimeout: 10,
            ap2Enabled: false,
            storageDsn: 'array://',
            profileFetchingDevelopmentMode: $developmentMode,
        );
    }

    private function systemConfig(bool $flagOn): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturn($flagOn);

        return $config;
    }
}
