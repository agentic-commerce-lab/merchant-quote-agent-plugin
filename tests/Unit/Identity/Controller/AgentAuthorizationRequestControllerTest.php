<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentAuthorizationRequestController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\ValidationException;

#[CoversClass(AgentAuthorizationRequestController::class)]
final class AgentAuthorizationRequestControllerTest extends TestCase
{
    public function testItRegistersAVerifiedRequest(): void
    {
        $store = AgentAuthorizationRequestControllerFixture::store();
        $domains = AgentAuthorizationRequestControllerFixture::domains();
        $controller = AgentAuthorizationRequestControllerFixture::build($store, $domains);
        $request = Request::create(
            '/ucp/quote-agent/authorization-requests',
            'POST',
            content: json_encode(AgentAuthorizationRequestControllerFixture::payload(), \JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('ucp_request_context', AgentAuthorizationRequestControllerFixture::context());

        $response = $controller->register($request);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        /** @var array{request_uri: string, expires_in: int, authorization_url: string} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(AgentAuthorizationRegistrar::TTL_SECONDS, $body['expires_in']);
        self::assertSame(
            AgentAuthorizationRequestControllerFixture::DOMAIN_BASE
            . '/quote-agent/authorize?request_uri='
            . $body['request_uri'],
            $body['authorization_url'],
        );
        self::assertCount(1, $store->stored);
        self::assertSame(AgentAuthorizationRequestControllerFixture::CLIENT_ID, $store->stored[0]->clientId);
        // The sales-channel binding: the consent URL must be built on the
        // domain of the channel the agent registered against, so asking for
        // the right domain id is the assertion, not just using the answer.
        self::assertSame([AgentAuthorizationRequestControllerFixture::DOMAIN_ID], $domains->askedFor);
    }

    public function testItRejectsABodyThatIsNotAJsonObject(): void
    {
        $store = AgentAuthorizationRequestControllerFixture::store();
        $controller = AgentAuthorizationRequestControllerFixture::build($store);
        $request = Request::create('/ucp/quote-agent/authorization-requests', 'POST', content: '[]');
        $request->attributes->set('ucp_request_context', AgentAuthorizationRequestControllerFixture::context());

        try {
            $controller->register($request);
            self::fail('A body that is not a JSON object must not be able to register.');
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when the body is not a JSON object');
        }
    }

    public function testItRejectsMalformedJson(): void
    {
        $store = AgentAuthorizationRequestControllerFixture::store();
        $controller = AgentAuthorizationRequestControllerFixture::build($store);
        $request = Request::create('/ucp/quote-agent/authorization-requests', 'POST', content: '{oops');
        $request->attributes->set('ucp_request_context', AgentAuthorizationRequestControllerFixture::context());

        try {
            $controller->register($request);
            self::fail('Malformed JSON must not be able to register.');
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when the body is malformed JSON');
        }
    }

    public function testItFailsLoudlyWithoutAUcpRequestContext(): void
    {
        $store = AgentAuthorizationRequestControllerFixture::store();
        $controller = AgentAuthorizationRequestControllerFixture::build($store);
        $request = Request::create('/ucp/quote-agent/authorization-requests', 'POST', content: '{}');

        try {
            $controller->register($request);
            self::fail('A request with no UCP request context must not be able to register.');
        } catch (ConfigurationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted when there is no UCP request context');
        }
    }
}
