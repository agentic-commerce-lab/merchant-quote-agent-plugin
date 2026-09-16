<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A ModelPlatform whose HTTP layer is scripted, so a test can assert both what
 * came back AND how many calls were paid for. The call count is the point: an
 * assertion on the outcome alone still passes when the pipeline made a model
 * call it should have gated away.
 */
final class ScriptedClient
{
    /** @var list<string> */
    public array $systemPrompts = [];

    /** @var list<string> */
    public array $userPrompts = [];

    public int $calls = 0;

    /** @var list<array{url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @param list<string|\Closure(string): string> $replies each becomes one assistant message, in order */
    public static function returning(array $replies): ModelPlatform
    {
        return self::spy($replies)[0];
    }

    /**
     * The same double handed raw responses instead of assistant messages, for
     * the tests that script a 503 or a dead connection rather than an answer.
     *
     * @param list<MockResponse> $responses
     *
     * @return array{0: ModelPlatform, 1: self}
     */
    public static function responding(array $responses, ?DecisionRecorder $recorder = null): array
    {
        return self::build($responses, $recorder);
    }

    /** The decoded request body of the nth call. */
    public function body(int $call = 0): array
    {
        return json_decode((string) $this->requests[$call]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A reply may be a closure rather than a string, resolved when its call
     * actually arrives and handed the user prompt that arrived with it.
     *
     * The integration suites run against a real shop quote, so the figures a
     * reply has to state -- the reduction, the new total, the expiry -- are
     * not knowable when the queue is built: they are what the pass is about
     * to write. But the reply call's user prompt IS `ReplyTemplate::compose()`
     * over the snapshot re-read after that write, so a test that could not
     * script such a reply up front can derive one here (#146). Without this,
     * six integration sites scripted a reply `RewordingGuard` rejected on
     * every run, and asserted nothing that could notice.
     *
     * @param list<string|\Closure(string): string> $replies
     *
     * @return array{0: ModelPlatform, 1: self}
     */
    public static function spy(array $replies, ?DecisionRecorder $recorder = null): array
    {
        return self::build(array_map(static fn(string|\Closure $reply): MockResponse|\Closure => $reply
            instanceof \Closure
                ? $reply
                : NegotiationFixture::modelReply($reply), $replies), $recorder);
    }

    /**
     * @param list<MockResponse|\Closure(string): string> $queue
     *
     * @return array{0: ModelPlatform, 1: self}
     */
    private static function build(array $queue, ?DecisionRecorder $recorder): array
    {
        // Tests that don't care about the audit trail get a recorder wired to
        // a throwaway writer, so every ModelPlatform construction site doesn't
        // need to know about DecisionRecorder.
        $recorder ??= new DecisionRecorder(new FakeDecisionWriter());

        $spy = new self();

        // MockHttpClient's callable form is what gives the spy the request: the
        // queue alone would say what came back but not what was sent, and the
        // prompt assertions are half of what these tests check.
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (
            $spy,
            &$queue,
        ): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
            ++$spy->calls;
            $spy->requests[] = ['url' => $url, 'options' => $options];
            $spy->systemPrompts[] = $body['messages'][0]['content'];
            $spy->userPrompts[] = $body['messages'][1]['content'];

            $reply = array_shift($queue) ?? throw new \LogicException('The scripted client ran out of replies.');

            return $reply instanceof \Closure
                ? NegotiationFixture::modelReply($reply((string) $body['messages'][1]['content']))
                : $reply;
        });

        return [new ModelPlatform($client, new NullLogger(), $recorder), $spy];
    }
}
