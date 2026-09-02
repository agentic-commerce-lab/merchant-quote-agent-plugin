<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Controller\AgentAuthorizationRequestController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\ValidationException;

#[CoversClass(AgentAuthorizationRequestController::class)]
final class AgentAuthorizationRequestControllerTest extends TestCase
{
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
