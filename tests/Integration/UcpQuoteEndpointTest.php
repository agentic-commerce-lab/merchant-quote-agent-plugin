<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\TraceEvent;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Identity\AccessTokenSubjectReaderInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * The endpoints over the real kernel, so the SDK's listeners run: that is the
 * only way to prove the UCP-Agent requirement, the error envelopes and the
 * request context are inherited rather than re-implemented.
 *
 * Tokens are issued through Agentic Commerce's own OAuth store service, which
 * makes these tests the canary for the one schema coupling this plugin has: if
 * that plugin renames its table, its columns or its hash, the reader stops
 * finding tokens its writer just wrote.
 *
 * Agent-profile fetching stays test-doubled the same way Agentic Commerce's
 * own functional suite does it: `TestAgentProfileFetcherCompilerPass` swaps
 * the SDK's real `HttpAgentProfileFetcher` for `StaticAgentProfileFetcher` in
 * the `test` environment, because the real one's SSRF check rejects a local
 * host regardless of scheme - there is no live profile document this suite
 * could ever fetch. `UCP-Agent`'s URI still has to pass the allowlist check
 * that runs before the fetch, so it still has to name a real, allowed host.
 */
final class UcpQuoteEndpointTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::requireUcpSurface();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }

        UcpAgentRequestFixture::stubAgentProfile(static::getContainer());
    }

    public function testTheSdkRejectsARequestWithoutAUcpAgentHeader(): void
    {
        $response = UcpAgentRequestFixture::send(static::getContainer(), 'GET', '/ucp/quotes', headers: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . UcpAgentRequestFixture::issueToken(static::getContainer()),
        ]);

        // 422, not 400: the SDK's own UcpErrorDescriptor::fromThrowable() maps
        // every ValidationException to 422 (types/error_code.json's
        // "invalid_request" family) - this is the SDK's mapping, not ours.
        self::assertSame(422, $response->getStatusCode());
        // Lowercase: the SDK's own violation path is '$.headers.ucp-agent is required'.
        self::assertStringContainsString('ucp-agent', (string) $response->getContent());
    }

    public function testAListRequestWithAValidTokenReturnsTheCustomersQuotes(): void
    {
        $customerId = BuyerQuoteFixture::anyQuoteCapableCustomerId(static::getContainer());
        $foreignQuoteId = BuyerQuoteFixture::anyQuoteIdNotOwnedBy(static::getContainer(), $customerId);

        $response = UcpAgentRequestFixture::send(static::getContainer(), 'GET', '/ucp/quotes', headers: [
            'HTTP_UCP_AGENT' => UcpAgentRequestFixture::agentHeader(static::getContainer()),
            'HTTP_AUTHORIZATION' => 'Bearer ' . UcpAgentRequestFixture::issueToken(static::getContainer(), $customerId),
        ]);

        self::assertSame(200, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertArrayHasKey('quotes', $payload);
        // Non-empty, so the ownership check below is not vacuously true.
        self::assertNotEmpty($payload['quotes']);

        // Ownership, not just shape: a quote belonging to another customer on
        // the same sales channel must never appear in this customer's list.
        $quoteIds = array_column($payload['quotes'], 'id');
        self::assertNotContains($foreignQuoteId, $quoteIds);
    }

    public function testAnUnknownTokenIsRejectedWithA401(): void
    {
        $response = UcpAgentRequestFixture::send(static::getContainer(), 'GET', '/ucp/quotes', headers: [
            'HTTP_UCP_AGENT' => UcpAgentRequestFixture::agentHeader(static::getContainer()),
            'HTTP_AUTHORIZATION' => 'Bearer ucp_at_not_a_real_token',
        ]);

        self::assertSame(401, $response->getStatusCode());
        // Content, not just status: AgentCustomerAuthenticator's own message,
        // proving the rejection reached our authenticator rather than
        // stopping earlier at the SDK's profile/signature checks (which
        // would 401 too, but with an unrelated message).
        self::assertStringContainsString('invalid, expired, or revoked', (string) $response->getContent());
    }

    public function testOurReaderFindsATokenAgenticCommerceItselfIssued(): void
    {
        $customerId = BuyerQuoteFixture::anyQuoteCapableCustomerId(static::getContainer());
        $token = UcpAgentRequestFixture::issueToken(static::getContainer(), $customerId);

        $reader = static::getContainer()->get(AccessTokenSubjectReaderInterface::class);
        self::assertInstanceOf(AccessTokenSubjectReaderInterface::class, $reader);

        $info = $reader->find($token, BuyerQuoteFixture::storefrontSalesChannelId(static::getContainer()));

        self::assertNotNull($info, 'the access-token schema or hash changed in Agentic Commerce');
        self::assertSame($customerId, $info->subject);
    }

    public function testInvalidAuthenticatedQuoteRequestKeepsAnErasableTrace(): void
    {
        $customerId = BuyerQuoteFixture::anyQuoteCapableCustomerId(static::getContainer());
        $request = Request::create(
            BuyerQuoteFixture::storefrontBaseUri(static::getContainer()) . '/ucp/quotes',
            'POST',
            server: [
                'HTTP_UCP_AGENT' => UcpAgentRequestFixture::agentHeader(static::getContainer()),
                'HTTP_AUTHORIZATION' =>
                    'Bearer ' . UcpAgentRequestFixture::issueToken(static::getContainer(), $customerId),
                'HTTP_IDEMPOTENCY_KEY' => 'idem-' . bin2hex(random_bytes(8)),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: '{"line_items":[{"product_id":"","quantity":1}],"comment":"private"}',
        );
        $response = KernelLifecycleManager::getKernel()->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('kind', 'http'), new EqualsFilter('customerId', $customerId));
        $trace = static::getContainer()
            ->get('merchant_quote_agent_trace.repository')
            ->search($criteria, Context::createDefaultContext())
            ->first();
        self::assertInstanceOf(TraceEvent::class, $trace);
        self::assertSame($customerId, $trace->customerId);
        self::assertNull($trace->quoteId);
        self::assertSame(
            '{"line_items":[{"product_id":"","quantity":1}],"comment":"private"}',
            $trace->content['requestBody'] ?? null,
        );
    }
}
