<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatCompletionClientTest extends TestCase
{
    private static function access(): ModelAccess
    {
        return new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini');
    }

    private static function ok(string $content): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content]]],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<mixed> $queue */
    private static function client(array $queue, ?array &$sent = null): ChatCompletionClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(static function (callable $handler) use (&$sent) {
            return static function ($request, array $options) use ($handler, &$sent) {
                $sent[] = $request;

                return $handler($request, $options);
            };
        });

        return new ChatCompletionClient(new Client(['handler' => $stack]), new NullLogger());
    }

    public function testItReturnsTheAssistantMessageContent(): void
    {
        $client = self::client([self::ok('{"ok":true}')]);

        self::assertSame('{"ok":true}', $client->complete(self::access(), 'sys', 'usr', json: true));
    }

    public function testItPostsToTheMerchantsBaseUrlWithTheirKeyAndModel(): void
    {
        $sent = [];
        self::client([self::ok('x')], $sent)->complete(self::access(), 'sys', 'usr', json: true);

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
        self::client([self::ok('a sentence')], $sent)->complete(self::access(), 'sys', 'usr', json: false);

        $body = json_decode((string) $sent[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('response_format', $body);
    }

    public function testATransientFailureIsRetriedOnce(): void
    {
        $sent = [];
        $client = self::client([new Response(503), self::ok('recovered')], $sent);

        self::assertSame('recovered', $client->complete(self::access(), 'sys', 'usr', json: true));
        self::assertCount(2, $sent, 'The 503 was not retried.');
    }

    public function testASecondFailureThrowsModelUnavailable(): void
    {
        $sent = [];
        $client = self::client([new Response(503), new Response(503)], $sent);

        $this->expectException(ModelUnavailable::class);

        try {
            $client->complete(self::access(), 'sys', 'usr', json: true);
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

        self::assertSame('recovered', $client->complete(self::access(), 'sys', 'usr', json: true));
    }

    public function testAMalformedEnvelopeThrowsModelUnavailable(): void
    {
        $client = self::client([new Response(200, [], '{"choices":[]}')]);

        $this->expectException(ModelUnavailable::class);

        $client->complete(self::access(), 'sys', 'usr', json: true);
    }
}
