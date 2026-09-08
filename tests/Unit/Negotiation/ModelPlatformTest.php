<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiationAction;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/** What one model call sends and returns. The retry behaviour is ModelPlatformRetryTest's. */
final class ModelPlatformTest extends TestCase
{
    public function testItPostsToTheMerchantsBaseUrlWithTheirKeyAndModelAndReturnsTheContent(): void
    {
        [$platform, $spy] = ScriptedClient::spy(['a sentence']);

        self::assertSame('a sentence', $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr'));
        self::assertSame('https://api.example.com/v1/chat/completions', $spy->requests[0]['url']);
        self::assertContains('Authorization: Bearer sk-test', $spy->requests[0]['options']['headers']);

        $body = $spy->body();
        self::assertSame('gpt-4o-mini', $body['model']);
        self::assertSame('sys', $body['messages'][0]['content']);
        self::assertSame('usr', $body['messages'][1]['content']);

        // The reply prompt returns prose, not JSON — asking for a schema there
        // would make the model wrap the sentence in a JSON envelope.
        self::assertArrayNotHasKey('response_format', $body);
    }

    /**
     * The schema is what replaced the hand-written JSON skeleton in the
     * prompt: it is derived from the DTO, so a field renamed in PHP can no
     * longer drift away from what the model was asked for.
     */
    public function testObjectSendsASchemaDerivedFromTheTargetClassAndMapsTheAnswerOntoIt(): void
    {
        $answer = json_encode([
            'action' => 'offer',
            'message' => 'We can do 5%.',
            'escalationReason' => null,
            'terms' => ['discountPercent' => 5.0, 'linePricesNet' => null],
        ], JSON_THROW_ON_ERROR);

        [$platform, $spy] = ScriptedClient::spy([$answer]);
        $response = $platform->object(NegotiationFixture::modelAccess(), 'sys', 'usr', NegotiateResponse::class);

        $schema = $spy->body()['response_format'];
        self::assertSame('json_schema', $schema['type']);
        self::assertTrue($schema['json_schema']['strict']);
        self::assertSame(
            ['offer', 'escalate'],
            $schema['json_schema']['schema']['properties']['action']['enum'],
            'The action gate is the enum now, not a hand-written check.',
        );

        self::assertSame(NegotiationAction::Offer, $response->action);
        self::assertSame('We can do 5%.', $response->message);
        self::assertSame(5.0, $response->toOffer(1000.0)->price->discountPercent);
    }

    public function testAnEmptyMessageIsUnusable(): void
    {
        [$platform] = ScriptedClient::responding([
            new MockResponse('{"choices":[]}', ['response_headers' => ['content-type' => 'application/json']]),
        ]);

        $this->expectException(ModelUnavailable::class);

        $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr');
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

        $withUsage = new MockResponse(
            json_encode([
                'choices' => [['message' => ['content' => 'the answer'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
            ], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );

        $answer = ScriptedClient::responding([$withUsage], $recorder)[0]->text(
            NegotiationFixture::modelAccess(),
            'system',
            'user',
        );

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

        ScriptedClient::spy(['the answer'], $secondRecorder)[0]->text(
            NegotiationFixture::modelAccess(),
            'system',
            'user',
        );

        $secondRecorder->finish(null);

        self::assertSame('gpt-4o-mini', $secondWriter->drafts[0]->model);
        self::assertNull(
            $secondWriter->drafts[0]->promptTokens,
            'Unknown cost must stay null, not fold into a silent zero.',
        );
    }

    /**
     * A scheme-less baseUrl (a plausible merchant typo) makes parse_url()
     * read the whole string as a path and return a null host. The fallback
     * for that case must never be the raw baseUrl itself: some gateways carry
     * a key in the query string, and modelHost lands in a merchant-readable
     * audit column.
     */
    public function testASchemeLessBaseUrlNeverLeaksItsQueryStringAsTheHost(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

        $access = new ModelAccess('sk-test', 'api.example.com/v1?key=SECRET', 'gpt-4o-mini');

        ScriptedClient::spy(['the answer'], $recorder)[0]->text($access, 'system', 'user');

        $recorder->finish(null);

        self::assertSame('api.example.com', $writer->drafts[0]->modelHost);
        self::assertStringNotContainsString('SECRET', (string) $writer->drafts[0]->modelHost);
    }
}
