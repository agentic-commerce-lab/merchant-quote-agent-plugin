<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatCompletionClientTest extends TestCase
{
    private static function ok(string $content): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content]]],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<mixed> $queue
     *
     * A throwaway recorder is used when the caller doesn't need one, so the
     * seven tests below that only care about complete()'s return value or its
     * HTTP calls don't have to know DecisionRecorder exists.
     */
    private static function client(
        array $queue,
        ?array &$sent = null,
        ?DecisionRecorder $recorder = null,
    ): ChatCompletionClient {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(static function (callable $handler) use (&$sent) {
            return static function ($request, array $options) use ($handler, &$sent) {
                $sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new ChatCompletionClient(
            new Client(['handler' => $stack]),
            new NullLogger(),
            $recorder ?? new DecisionRecorder(new FakeDecisionWriter()),
        );
    }

    public function testItReturnsTheAssistantMessageContent(): void
    {
        $client = self::client([self::ok('{"ok":true}')]);

        self::assertSame('{"ok":true}', $client->complete(NegotiationFixture::modelAccess(), 'sys', 'usr', json: true));
    }

    public function testItPostsToTheMerchantsBaseUrlWithTheirKeyAndModel(): void
    {
        $sent = [];
        self::client([self::ok('x')], $sent)->complete(NegotiationFixture::modelAccess(), 'sys', 'usr', json: true);

        self::assertCount(1, $sent);
        self::assertSame('POST', $sent[0]->getMethod());
        self::assertSame('https://api.example.com/v1/chat/completions', (string) $sent[0]->getUri());
        self::assertSame('Bearer sk-test', $sent[0]->getHeaderLine('Authorization'));

        $body = json_decode((string) $sent[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('gpt-4o-mini', $body['model']);
        self::assertSame('sys', $body['messages'][0]['content']);
        self::assertSame('usr', $body['messages'][1]['content']);
        self::assertSame(['type' => 'json_object'], $body['response_format']);
    }

    public function testJsonFalseOmitsTheResponseFormat(): void
    {
        // The reply prompt returns prose, not JSON — asking for json_object
        // there would make the model wrap the sentence in a JSON envelope.
        $sent = [];
        self::client([self::ok('a sentence')], $sent)
            ->complete(NegotiationFixture::modelAccess(), 'sys', 'usr', json: false);

        $body = json_decode((string) $sent[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('response_format', $body);
    }

    public function testATransientFailureIsRetriedOnce(): void
    {
        $sent = [];
        $client = self::client([new Response(503), self::ok('recovered')], $sent);

        self::assertSame('recovered', $client->complete(NegotiationFixture::modelAccess(), 'sys', 'usr', json: true));
        self::assertCount(2, $sent, 'The 503 was not retried.');
    }

    public function testASecondFailureThrowsModelUnavailable(): void
    {
        $sent = [];
        $client = self::client([new Response(503), new Response(503)], $sent);

        $this->expectException(ModelUnavailable::class);

        try {
            $client->complete(NegotiationFixture::modelAccess(), 'sys', 'usr', json: true);
        } finally {
            self::assertCount(2, $sent, 'Retried more than once; three calls must fit inside the 300s lock TTL.');
        }
    }

    public function testAConnectionFailureIsAlsoTransient(): void
    {
        $client = self::client([
            new ConnectException('timed out', new Request('POST', 'https://api.example.com/v1/chat/completions')),
            self::ok('recovered'),
        ]);

        self::assertSame('recovered', $client->complete(NegotiationFixture::modelAccess(), 'sys', 'usr', json: true));
    }

    public function testAMalformedEnvelopeThrowsModelUnavailable(): void
    {
        $client = self::client([new Response(200, [], '{"choices":[]}')]);

        $this->expectException(ModelUnavailable::class);

        $client->complete(NegotiationFixture::modelAccess(), 'sys', 'usr', json: true);
    }

    /**
     * Covers both the happy path (a provider that sends the OpenAI `usage`
     * block) and the common gap (one that doesn't) in one test, so the class
     * stays under the too-many-methods cap: recording still happens either
     * way, just with null token counts when the block is absent.
     */
    public function testUsageAndLatencyAreRecordedFromTheModelResponse(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

        $withUsage = new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'the answer']]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
        ], JSON_THROW_ON_ERROR));

        $answer = self::client([$withUsage], recorder: $recorder)
            ->complete(NegotiationFixture::modelAccess(), 'system', 'user', json: false);

        self::assertSame('the answer', $answer, 'The return type must not change.');

        $recorder->finish(null);
        $draft = $writer->drafts[0];

        self::assertSame(120, $draft->promptTokens);
        self::assertSame(30, $draft->completionTokens);
        self::assertSame('gpt-4o-mini', $draft->model);
        self::assertSame('api.example.com', $draft->modelHost);
        self::assertNotNull($draft->modelLatencyMs);

        // A second, independent pass against a provider that sends no usage
        // block at all — still worth recording, just with unknown cost.
        $secondWriter = new FakeDecisionWriter();
        $secondRecorder = new DecisionRecorder($secondWriter);
        $secondRecorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

        self::client([self::ok('the answer')], recorder: $secondRecorder)
            ->complete(NegotiationFixture::modelAccess(), 'system', 'user', json: false);

        $secondRecorder->finish(null);

        self::assertSame('gpt-4o-mini', $secondWriter->drafts[0]->model);
        self::assertSame(0, $secondWriter->drafts[0]->promptTokens);
    }
}
