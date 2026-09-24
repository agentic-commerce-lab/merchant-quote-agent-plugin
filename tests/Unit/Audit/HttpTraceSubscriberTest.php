<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\HttpTraceCapture;
use MerchantQuoteAgentPlugin\Audit\HttpTraceQuote;
use MerchantQuoteAgentPlugin\Audit\HttpTraceSubscriber;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Identity\AuthenticatedCustomerAttribute;
use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpTraceSubscriberTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    private const CUSTOMER_ID = '22222222222222222222222222222222';

    public function testAuthenticatedQuoteRefusalCarriesCustomerForErasure(): void
    {
        $writer = new FakeTraceWriter();
        $request = Request::create('/ucp/quotes', 'POST', content: '{"comment":"private"}');
        $request->attributes->set('_route', 'frontend.merchant_quote_agent.quote.request');
        $request->attributes->set(AuthenticatedCustomerAttribute::KEY, self::CUSTOMER_ID);

        self::send($writer, $request, new Response('{"messages":[{"type":"error"}]}', 422));

        self::assertCount(1, $writer->events);
        self::assertSame(self::CUSTOMER_ID, $writer->events[0]->customerId);
        self::assertNull($writer->events[0]->quoteId);
        self::assertSame('{"comment":"private"}', $writer->events[0]->content['requestBody']);
    }

    public function testUnboundRefusalDoesNotRetainItsRequestBody(): void
    {
        $writer = new FakeTraceWriter();
        $request = Request::create('/ucp/quotes', 'POST', content: '{"comment":"private"}');
        $request->attributes->set('_route', 'frontend.merchant_quote_agent.quote.request');

        self::send($writer, $request, new Response('{"messages":[{"type":"error"}]}', 401));

        self::assertCount(1, $writer->events);
        self::assertNull($writer->events[0]->content);
    }

    public function testUnauthenticatedCounterRefusalDoesNotRetainItsRequestBody(): void
    {
        $writer = new FakeTraceWriter();
        $request = Request::create(
            '/ucp/quotes/' . self::QUOTE_ID . '/counter',
            'POST',
            content: '{"comment":"private"}',
        );
        $request->attributes->set('_route', 'frontend.merchant_quote_agent.quote.counter');
        $request->attributes->set('id', self::QUOTE_ID);

        self::send($writer, $request, new Response('{"messages":[{"type":"error"}]}', 401));

        self::assertCount(1, $writer->events);
        self::assertNull($writer->events[0]->content);
    }

    public function testPostCapturesBodiesAndResolvesCreatedQuote(): void
    {
        $writer = new FakeTraceWriter();
        $request = Request::create(
            '/ucp/quotes',
            'POST',
            server: ['REQUEST_TIME_FLOAT' => microtime(true) - 0.01],
            content: '{"comment":"hello"}',
        );
        $request->attributes->set('_route', 'frontend.merchant_quote_agent.quote.request');
        $request->attributes->set(AuthenticatedCustomerAttribute::KEY, self::CUSTOMER_ID);

        self::send($writer, $request, new Response('{"id":"' . self::QUOTE_ID . '"}', 201));

        self::assertCount(1, $writer->events);
        $trace = $writer->events[0];
        self::assertSame(TraceKind::Http, $trace->kind);
        self::assertSame(self::QUOTE_ID, $trace->quoteId);
        self::assertSame('POST', $trace->meta['method']);
        self::assertSame(201, $trace->meta['httpStatus']);
        self::assertGreaterThanOrEqual(0, $trace->meta['durationMs']);
        self::assertSame('{"comment":"hello"}', $trace->content['requestBody'] ?? null);
    }

    public function testA2cnRefusalKeepsTheCodeAndSessionQuote(): void
    {
        $writer = new FakeTraceWriter();
        $request = Request::create('/a2cn/sessions/session-1/messages', 'POST', content: '{"secret":"payload"}');
        $request->attributes->set('_route', 'frontend.merchant_quote_agent.a2cn.messages.post');
        $request->attributes->set('sessionId', 'session-1');
        $locator = new class extends SessionQuoteLocator {
            public function __construct() {}

            #[\Override]
            public function quoteIdFor(string $sessionId): ?string
            {
                return '11111111111111111111111111111111';
            }
        };

        self::send($writer, $request, new Response('{"status":"session_busy"}', 409), $locator);

        self::assertSame(self::QUOTE_ID, $writer->events[0]->quoteId);
        self::assertSame('session-1', $writer->events[0]->meta['sessionId']);
        self::assertSame('session_busy', $writer->events[0]->meta['errorCode']);
        self::assertSame('{"status":"session_busy"}', $writer->events[0]->content['responseBody'] ?? null);
    }

    public function testGetSuccessAndIdentityRoutesDoNotStoreBodies(): void
    {
        $writer = new FakeTraceWriter();
        $get = Request::create('/ucp/quotes/' . self::QUOTE_ID, 'GET');
        $get->attributes->set('_route', 'frontend.merchant_quote_agent.quote.get');
        $get->attributes->set('id', self::QUOTE_ID);
        self::send($writer, $get, new Response('{"comments":["private"]}'));

        $identity = Request::create('/quote-agent/authorize', 'POST', content: 'client_secret=hidden');
        $identity->attributes->set('_route', 'frontend.merchant_quote_agent.authorize.grant');
        self::send($writer, $identity, new Response('oauth-code=hidden', 400));

        self::assertCount(2, $writer->events);
        self::assertNull($writer->events[0]->content);
        self::assertNull($writer->events[1]->content);
    }

    public function testStaticAndCatchAllRoutesAreExcluded(): void
    {
        $writer = new FakeTraceWriter();
        foreach (['quote.schema', 'quote.spec', 'a2cn.discovery', 'a2cn.not_found'] as $suffix) {
            $request = Request::create('/document');
            $request->attributes->set('_route', 'frontend.merchant_quote_agent.' . $suffix);
            self::send($writer, $request, new Response('body'));
        }

        self::assertSame([], $writer->events);
    }

    public function testMalformedRouteQuoteIdStillRecordsTheRefusal(): void
    {
        $writer = new FakeTraceWriter();
        $request = Request::create('/ucp/quotes/not-a-uuid', 'GET');
        $request->attributes->set('_route', 'frontend.merchant_quote_agent.quote.get');
        $request->attributes->set('id', 'not-a-uuid');

        self::send($writer, $request, new Response('{"status":"not_found"}', 404));

        self::assertCount(1, $writer->events);
        self::assertNull($writer->events[0]->quoteId);
        self::assertSame('not_found', $writer->events[0]->meta['errorCode']);
    }

    public function testAuditFailureDoesNotChangeTheHttpResponse(): void
    {
        $writer = new FakeTraceWriter();
        $writer->failure = new \RuntimeException('audit unavailable');
        $request = Request::create('/ucp/quotes/' . self::QUOTE_ID, 'GET');
        $request->attributes->set('_route', 'frontend.merchant_quote_agent.quote.get');
        $request->attributes->set('id', self::QUOTE_ID);
        $response = new Response('{"id":"' . self::QUOTE_ID . '"}');

        self::send($writer, $request, $response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $writer->events);
    }

    private static function send(
        FakeTraceWriter $writer,
        Request $request,
        Response $response,
        ?SessionQuoteLocator $locator = null,
    ): void {
        $subscriber = new HttpTraceSubscriber(
            new HttpTraceCapture($writer, new HttpTraceQuote($locator)),
            new NullLogger(),
        );
        $kernel = self::createStub(HttpKernelInterface::class);
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
    }
}
