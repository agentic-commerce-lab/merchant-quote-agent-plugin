<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bench;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Tests\Bench\BuyerMoveKind;
use MerchantQuoteAgentPlugin\Tests\Bench\LlmBuyer;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Snapshots come from `NegotiationFixture::snapshot()` (tests/Unit/Negotiation),
 * the same fixture `ScriptedBuyerTest` reuses -- there is nothing bench-local
 * to add for a fixed `totalNet`.
 */
final class LlmBuyerTest extends TestCase
{
    private const ACCESS_ARGS = ['sk-test', 'https://api.example.com/v1', 'test-model'];

    public function testItMapsTheModelsAnswerOntoABuyerMove(): void
    {
        $platform = ScriptedClient::returning([
            '{"kind":"counter","comment":"That is still above what I can approve. Can you reach 12%?"}',
        ]);

        $move = (new LlmBuyer($platform, new ModelAccess(...self::ACCESS_ARGS), 'A hard bargainer.'))->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 950.0),
            'We can do 5%.',
            round: 1,
        );

        self::assertSame(BuyerMoveKind::Counter, $move->kind);
        self::assertSame('That is still above what I can approve. Can you reach 12%?', $move->comment);
    }

    public function testItAcceptsWhenTheModelAnswersAccept(): void
    {
        $platform = ScriptedClient::returning(['{"kind":"accept","comment":""}']);

        $move = (new LlmBuyer($platform, new ModelAccess(...self::ACCESS_ARGS), 'An easygoing buyer.'))->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 900.0),
            'We can do 10%.',
            round: 2,
        );

        self::assertSame(BuyerMoveKind::Accept, $move->kind);
        self::assertNull($move->comment);
    }

    public function testItWalksWhenTheModelAnswersWalk(): void
    {
        $platform = ScriptedClient::returning(['{"kind":"walk","comment":"Not interested any more."}']);

        $move = (new LlmBuyer($platform, new ModelAccess(...self::ACCESS_ARGS), 'An impatient buyer.'))->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 1000.0),
            'We cannot move further.',
            round: 4,
        );

        // A walk carries no comment regardless of what the model wrote in the
        // field -- BuyerMove::walk() is the only constructor a Walk kind maps
        // to, and it takes none.
        self::assertSame(BuyerMoveKind::Walk, $move->kind);
        self::assertNull($move->comment);
    }

    public function testAnAnswerWithoutACommentCannotCounter(): void
    {
        // A counter with nothing to say would write an empty buyer comment,
        // which the interpreter reads as no ask at all -- the pass would
        // record nothing_to_do and the round would be silently lost.
        $platform = ScriptedClient::returning(['{"kind":"counter","comment":""}']);

        $this->expectException(\RuntimeException::class);

        (new LlmBuyer($platform, new ModelAccess(...self::ACCESS_ARGS), 'A hard bargainer.'))->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 950.0),
            'We can do 5%.',
            round: 1,
        );
    }

    public function testThePersonaAndAntiDisclosureInstructionReachTheSystemPrompt(): void
    {
        [$platform, $spy] = ScriptedClient::spy(['{"kind":"accept","comment":""}']);

        (new LlmBuyer($platform, new ModelAccess(...self::ACCESS_ARGS), 'A hard bargainer.'))->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 900.0),
            'We can do 10%.',
            round: 1,
        );

        // Both halves are asserted: the persona text itself, and the standing
        // instruction not to reveal it is synthetic -- a buyer that announces
        // itself changes what the agent replies next.
        self::assertStringContainsString('A hard bargainer.', $spy->systemPrompts[0]);
        self::assertStringContainsString('Never say, imply, or admit that you are an AI', $spy->systemPrompts[0]);
    }

    public function testAModelUnavailableFromTheProviderPropagatesUncaught(): void
    {
        // Two failures because ModelPlatform retries exactly once
        // (ModelPlatformRetryTest::testASecondFailureThrowsModelUnavailable
        // pins that behaviour); a buyer that swallowed this would record a
        // walk the model never chose.
        [$platform] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 503]),
        ]);

        $this->expectException(ModelUnavailable::class);

        (new LlmBuyer($platform, new ModelAccess(...self::ACCESS_ARGS), 'A hard bargainer.'))->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 950.0),
            'We can do 5%.',
            round: 1,
        );
    }
}
