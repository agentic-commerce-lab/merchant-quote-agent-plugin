<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every logical model call leaves one `model_call` event, whatever happened to
 * it. Before this, only a successful call reached the recorder, and even then
 * as a running total: the prompt, the answer and every failure were gone.
 */
final class ModelPlatformTraceTest extends TestCase
{
    /** NegotiationFixture's `sk-test` is under the redaction's 8-byte guard. */
    private const LONG_KEY = 'sk-test-0123456789';

    public function testASuccessfulCallRecordsThePromptTheAnswerAndItsOwnFigures(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::responding([new MockResponse(json_encode([
            'model' => 'gpt-4o-mini-2024-07-18',
            'choices' => [['message' => ['content' => 'Hello'], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 8,
                'prompt_tokens_details' => ['cached_tokens' => 64],
                'completion_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ], JSON_THROW_ON_ERROR))], $recorder);

        $platform->text(NegotiationFixture::modelAccess(), 'SYSTEM', 'USER');
        $event = self::modelCalls($writer, $recorder)[0];

        self::assertSame(TraceKind::ModelCall->metaKeys(), array_keys($event->meta));
        self::assertSame('reply', $event->meta['purpose']);
        self::assertSame('ok', $event->meta['status']);
        self::assertSame(200, $event->meta['httpStatus']);
        self::assertSame('gpt-4o-mini', $event->meta['requestedModel']);
        self::assertSame('gpt-4o-mini-2024-07-18', $event->meta['servedModel']);
        self::assertSame('api.example.com', $event->meta['host']);
        self::assertSame(120, $event->meta['promptTokens']);
        self::assertSame(64, $event->meta['cachedTokens']);
        self::assertSame(0, $event->meta['reasoningTokens']);
        self::assertSame('stop', $event->meta['finishReason']);
        self::assertSame([], $event->meta['retries']);
        self::assertSame('SYSTEM', $event->content['request']['messages'][0]['content'] ?? null);
        self::assertSame('USER', $event->content['request']['messages'][1]['content'] ?? null);
        self::assertSame('Hello', $event->content['response']['choices'][0]['message']['content'] ?? null);
    }

    public function testTheApiKeyIsNowhereInTheTrace(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::spy(['Hello'], $recorder);

        $platform->text(NegotiationFixture::modelAccess(), 'SYSTEM', 'USER');

        self::assertStringNotContainsString('sk-test', json_encode(
            self::modelCalls($writer, $recorder),
            JSON_THROW_ON_ERROR,
        ));
    }

    public function testARetriedCallRecordsTheAttemptThatFailed(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503]),
            NegotiationFixture::modelReply('recovered'),
        ], $recorder);

        $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr');
        $event = self::modelCalls($writer, $recorder)[0];

        self::assertSame('ok', $event->meta['status']);
        self::assertSame([['httpStatus' => 503, 'transportError' => false]], $event->meta['retries']);
    }

    public function testAFailedCallRecordsTheStatusAndTheProvidersErrorBody(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::responding([
            new MockResponse('{"error":"overloaded"}', ['http_code' => 503]),
            new MockResponse('{"error":"still overloaded"}', ['http_code' => 503]),
        ], $recorder);

        try {
            $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr');
            self::fail('Two 503s must escalate.');
        } catch (ModelUnavailable) {
        }

        $event = self::modelCalls($writer, $recorder)[0];
        self::assertSame('failed', $event->meta['status']);
        self::assertSame(503, $event->meta['httpStatus']);
        self::assertCount(1, $event->meta['retries']);
        self::assertNotNull($event->meta['errorClass']);
        self::assertSame('{"error":"still overloaded"}', $event->content['error']['body'] ?? null);
        self::assertSame('sys', $event->content['request']['messages'][0]['content'] ?? null);
    }

    public function testAnAnswerThatDoesNotMapIsKeptAndMarkedUnusable(): void
    {
        // The call worked and the answer is what went wrong -- exactly the
        // answer worth reading afterwards, and until now thrown away.
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::spy(['{"action":"sing"}'], $recorder);

        try {
            $platform->object(NegotiationFixture::modelAccess(), 'sys', 'usr', NegotiateResponse::class);
            self::fail('An unmappable answer must escalate.');
        } catch (ModelUnavailable) {
        }

        $event = self::modelCalls($writer, $recorder)[0];
        self::assertSame('unusable_answer', $event->meta['status']);
        self::assertSame('negotiate', $event->meta['purpose']);
        self::assertSame('{"action":"sing"}', $event->content['response']['choices'][0]['message']['content'] ?? null);
        self::assertArrayHasKey('error', $event->content);
    }

    public function testThePurposeIsReadOffTheAnswerType(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::spy(['{"price":{"additionalDiscountPercent":5}}'], $recorder);

        $platform->object(NegotiationFixture::modelAccess(), 'sys', 'usr', CommentInterpretation::class);

        self::assertSame('extract', self::modelCalls($writer, $recorder)[0]->meta['purpose']);
    }

    public function testAKeyTheProviderEchoesInAnErrorIsRedacted(): void
    {
        // Some debug gateways reflect the Authorization header in a 401. The
        // problem+json body also makes Symfony copy `detail` into the
        // exception message, so `error.cause` carries the key as well.
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::responding([new MockResponse('{"title":"Unauthorized","detail":"Bearer '
        . self::LONG_KEY
        . ' is not valid"}', [
            'http_code' => 401,
            'response_headers' => ['content-type: application/problem+json'],
        ])], $recorder);

        try {
            $platform->text(new ModelAccess(self::LONG_KEY, 'https://api.example.com/v1', 'gpt-4o-mini'), 'sys', 'usr');
            self::fail('A 401 must escalate.');
        } catch (ModelUnavailable) {
        }

        $content = json_encode(self::modelCalls($writer, $recorder)[0]->content, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::LONG_KEY, $content);
        self::assertStringContainsString('[redacted]', $content);
    }

    public function testAKeyEchoedInAnUnusableAnswerIsRedacted(): void
    {
        [$writer, $recorder] = self::openPass();
        [$platform] = ScriptedClient::spy(['{"action":"sing","key":"' . self::LONG_KEY . '"}'], $recorder);

        try {
            $platform->object(
                new ModelAccess(self::LONG_KEY, 'https://api.example.com/v1', 'gpt-4o-mini'),
                'sys',
                'usr',
                NegotiateResponse::class,
            );
            self::fail('An unmappable answer must escalate.');
        } catch (ModelUnavailable) {
        }

        $content = json_encode(self::modelCalls($writer, $recorder)[0]->content, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::LONG_KEY, $content);
        self::assertStringContainsString('[redacted]', $content);
    }

    /** @return array{0: FakeDecisionWriter, 1: DecisionRecorder} */
    private static function openPass(): array
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

        return [$writer, $recorder];
    }

    /** @return list<TraceDraft> */
    private static function modelCalls(FakeDecisionWriter $writer, DecisionRecorder $recorder): array
    {
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        return array_values(array_filter(
            $writer->drafts[0]->trace,
            static fn(TraceDraft $t): bool => $t->kind === TraceKind::ModelCall,
        ));
    }
}
