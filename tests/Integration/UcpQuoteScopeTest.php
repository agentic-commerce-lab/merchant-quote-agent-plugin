<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteOAuthScopeProvider;

/**
 * Scope enforcement on the quote routes, which only exists with Agentic
 * Commerce 1.4.0+: before that release no token can carry the quote scope, so
 * none is checked and these tests skip. The older side is pinned in
 * UcpSurfaceConfigurationTest.
 */
final class UcpQuoteScopeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::requireUcpSurface();

        if (!class_exists(QuoteOAuthScopeProvider::REGISTRY_CLASS)) {
            self::markTestSkipped(
                'Agentic Commerce before 1.4.0 cannot register extension scopes, so none are enforced.',
            );
        }

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }

        UcpAgentRequestFixture::stubAgentProfile(static::getContainer());
    }

    /**
     * Also the canary for the duck-typed provider: if Agentic Commerce starts
     * type-checking scope providers, ours drops out of the list here.
     */
    public function testAgenticCommerceAdvertisesTheRegisteredQuoteScope(): void
    {
        $response = UcpAgentRequestFixture::send(
            static::getContainer(),
            'GET',
            '/.well-known/oauth-authorization-server',
        );

        self::assertSame(200, $response->getStatusCode());
        $metadata = json_decode((string) $response->getContent(), associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertContains(QuoteOAuthScopeProvider::MANAGE_QUOTES, $metadata['scopes_supported'] ?? []);
    }

    public function testATokenWithoutTheQuoteScopeIsRefusedWithA403(): void
    {
        $container = static::getContainer();
        $response = UcpAgentRequestFixture::send($container, 'GET', '/ucp/quotes', headers: [
            'HTTP_UCP_AGENT' => UcpAgentRequestFixture::agentHeader($container),
            'HTTP_AUTHORIZATION' =>
                'Bearer ' . UcpAgentRequestFixture::issueToken($container, scope: 'dev.ucp.shopping.cart:manage'),
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(QuoteOAuthScopeProvider::MANAGE_QUOTES, (string) $response->getContent());
    }

    public function testAcceptingWithoutTheOrderScopeIsRefusedWithA403(): void
    {
        $container = static::getContainer();

        // The scope check runs before the quote is looked up, so any id will do.
        $response = UcpAgentRequestFixture::send(
            $container,
            'POST',
            '/ucp/quotes/' . bin2hex(random_bytes(16)) . '/accept',
            headers: [
                'HTTP_UCP_AGENT' => UcpAgentRequestFixture::agentHeader($container),
                'HTTP_AUTHORIZATION' =>
                    'Bearer '
                        . UcpAgentRequestFixture::issueToken($container, scope: QuoteOAuthScopeProvider::MANAGE_QUOTES),
                'HTTP_IDEMPOTENCY_KEY' => 'idem-' . bin2hex(random_bytes(8)),
            ],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(QuoteOAuthScopeProvider::MANAGE_ORDERS, (string) $response->getContent());
    }

    public function testATokenWithEveryScopeReachesTheQuotes(): void
    {
        $container = static::getContainer();
        $response = UcpAgentRequestFixture::send($container, 'GET', '/ucp/quotes', headers: [
            'HTTP_UCP_AGENT' => UcpAgentRequestFixture::agentHeader($container),
            'HTTP_AUTHORIZATION' => 'Bearer ' . UcpAgentRequestFixture::issueToken($container),
        ]);

        self::assertSame(200, $response->getStatusCode());
    }
}
