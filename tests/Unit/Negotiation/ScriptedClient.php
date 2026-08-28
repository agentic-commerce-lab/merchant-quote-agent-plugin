<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient;
use Psr\Log\NullLogger;

/**
 * A ChatCompletionClient whose HTTP layer is scripted, so a test can assert
 * both what came back AND how many calls were paid for. The call count is the
 * point: an assertion on the outcome alone still passes when the pipeline made
 * a model call it should have gated away.
 */
final class ScriptedClient
{
    /** @var list<string> */
    public array $systemPrompts = [];

    /** @var list<string> */
    public array $userPrompts = [];

    public int $calls = 0;

    /** @param list<string> $replies each becomes one assistant message, in order */
    public static function returning(array $replies): ChatCompletionClient
    {
        return self::spy($replies)[0];
    }

    /**
     * @param list<string> $replies
     *
     * @return array{0: ChatCompletionClient, 1: self}
     */
    public static function spy(array $replies): array
    {
        $spy = new self();
        $queue = array_map(static fn(string $r): Response => new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $r]]],
        ], JSON_THROW_ON_ERROR)), $replies);

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(static function (callable $handler) use ($spy) {
            return static function ($request, array $options) use ($handler, $spy) {
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                ++$spy->calls;
                $spy->systemPrompts[] = $body['messages'][0]['content'];
                $spy->userPrompts[] = $body['messages'][1]['content'];

                return $handler($request, $options);
            };
        });

        return [new ChatCompletionClient(new Client(['handler' => $stack]), new NullLogger()), $spy];
    }
}
