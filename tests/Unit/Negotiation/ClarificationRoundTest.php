<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationRound;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use PHPUnit\Framework\TestCase;

/**
 * The two halves of "ask once, then escalate", at the boundary that decides
 * which. Getting it backwards either escalates without ever asking the buyer,
 * or asks forever and never reaches a human.
 */
final class ClarificationRoundTest extends TestCase
{
    public function testItPostsEveryQuestionVerbatimAndMarksTheQuote(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot();

        $pass = ClarificationRound::handle(
            $harness->gateway,
            $snapshot,
            $this->ask(['Which line did you mean?', 'By when do you need it?']),
            $harness->round,
            $harness->logger,
        );

        self::assertSame(NegotiationOutcome::Clarified, $pass->outcome);
        self::assertSame('extract-hash', $pass->extractHash);
        self::assertNull($pass->negotiateHash, 'A clarification never reaches the negotiate call.');
        self::assertNull($pass->replyHash, 'The questions are posted as-is, so no reply prompt ran.');

        self::assertSame(
            ["Which line did you mean?\nBy when do you need it?"],
            $harness->gateway->comments,
            'Both questions, in order, with nothing added.',
        );
        self::assertSame([ClarificationMarker::MARKER_KEY => true], $harness->gateway->customFieldWrites[0] ?? null);
    }

    public function testItEscalatesWhenTheQuoteWasAlreadyAsked(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::withCustomFields(NegotiationFixture::snapshot(), [
            ClarificationMarker::MARKER_KEY => true,
        ]);

        $pass = ClarificationRound::handle(
            $harness->gateway,
            $snapshot,
            $this->ask(['Which line did you mean?']),
            $harness->round,
            $harness->logger,
        );

        self::assertSame(NegotiationOutcome::Escalated, $pass->outcome);
        self::assertSame(QuoteEscalationReason::NeedsHumanReview, $pass->escalationReason);

        // The buyer gets the escalation constant, never the question again.
        self::assertCount(1, $harness->gateway->comments);
        self::assertStringNotContainsString('Which line', $harness->gateway->comments[0]);
    }

    /** @param list<string> $questions */
    private function ask(array $questions): InterpretedAsk
    {
        return new InterpretedAsk(new CommentInterpretation(clarificationQuestions: $questions), 'extract-hash');
    }
}
