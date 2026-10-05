<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use PHPUnit\Framework\Assert;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Service\AgentProfileFetcherInterface;

/**
 * An agent's side of a UCP request over the real kernel: a stubbed agent
 * profile, a token Agentic Commerce itself issued, and the UCP-Agent header.
 * Shared by UcpQuoteEndpointTest and UcpQuoteScopeTest, and separate from both
 * so each stays under mago's too-many-methods ceiling.
 */
final class UcpAgentRequestFixture
{
    /** What an agent linking for quotes requests. The store writes it as given, on any release. */
    public const ALL_SCOPES = 'dev.ucp.shopping.cart:manage com.shopware.quote:manage dev.ucp.shopping.order:manage';

    private const AC_OAUTH_STORE = 'Swag\\AgenticCommerce\\Ucp\\Identity\\DoctrineDbalUcpOAuthStore';

    private function __construct() {}

    /**
     * Duck-typed rather than importing Swag\AgenticCommerce\Ucp\Test\StaticAgentProfileFetcher:
     * that class belongs to a sibling plugin, not a package this one depends on.
     */
    public static function stubAgentProfile(ContainerInterface $container): void
    {
        $fetcher = $container->get(AgentProfileFetcherInterface::class);
        Assert::assertTrue(method_exists($fetcher, 'setProfile'), 'expected the test-env agent-profile double');
        $fetcher->setProfile(new PlatformProfile(
            version: '2026-04-08',
            services: [],
            capabilities: [],
            paymentHandlers: [],
        ));
    }

    public static function issueToken(
        ContainerInterface $container,
        ?string $customerId = null,
        string $scope = self::ALL_SCOPES,
    ): string {
        $store = $container->get(self::AC_OAUTH_STORE);
        Assert::assertIsObject($store);
        Assert::assertTrue(method_exists($store, 'issueTokenSet'), 'Agentic Commerce OAuth store lost issueTokenSet()');

        $set = $store->issueTokenSet(
            BuyerQuoteFixture::storefrontSalesChannelId($container),
            'integration-test-client',
            $customerId ?? BuyerQuoteFixture::anyQuoteCapableCustomerId($container),
            $scope,
        );

        Assert::assertIsObject($set);
        Assert::assertIsString($set->accessToken);

        return $set->accessToken;
    }

    /**
     * A well-formed UCP-Agent header: DefaultHttpRequestContextFactory::extractProfileUri()
     * only accepts `profile="<uri>"`, and the host it names must pass
     * assertSafeProfileUri()'s allowlist check before the (stubbed) fetch
     * ever runs. The storefront's own domain is the one host this suite
     * knows is allowed.
     */
    public static function agentHeader(ContainerInterface $container): string
    {
        return 'profile="' . BuyerQuoteFixture::storefrontBaseUri($container) . '/.well-known/ucp"';
    }

    /**
     * @param array<string, string> $headers server-style header names (HTTP_*)
     */
    public static function send(
        ContainerInterface $container,
        string $method,
        string $path,
        array $headers = [],
    ): Response {
        $request = Request::create(BuyerQuoteFixture::storefrontBaseUri($container) . $path, $method, server: $headers);

        return KernelLifecycleManager::getKernel()->handle($request);
    }
}
