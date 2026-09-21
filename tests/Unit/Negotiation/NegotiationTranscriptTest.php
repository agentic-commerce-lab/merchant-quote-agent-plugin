<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\BuyerConversation;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationTranscript;
use PHPUnit\Framework\TestCase;

/**
 * #166: the negotiate prompt's memory of its own thread. A round is one buyer
 * comment followed by the agent's reply to it; the transcript pairs them and
 * keeps only the figures.
 */
final class NegotiationTranscriptTest extends TestCase
{
    public function testAFirstRoundWithNoPriorHistoryIsEmpty(): void
    {
        $conversation = new BuyerConversation(buyer: [NegotiationFixture::buyerComment(
            'Could you do 800?',
            '2026-09-18 09:00:00',
        )], agent: []);

        self::assertSame('', NegotiationTranscript::of($conversation));
    }

    public function testOneCompletedRoundPairsTheAskWithTheOffer(): void
    {
        $conversation = new BuyerConversation(
            buyer: [
                NegotiationFixture::buyerComment('Could you do 800?', '2026-09-18 09:00:00'),
                // The newest buyer comment has no reply yet: it is this round's
                // ask, not a completed round, and must not appear here.
                NegotiationFixture::buyerComment('815 would work too.', '2026-09-18 11:00:00'),
            ],
            agent: [
                NegotiationFixture::agentComment(
                    'We can bring this quote down by 8.2% to 820.00 EUR. The offer is valid until 2026-10-02.',
                    '2026-09-18 10:00:00',
                ),
            ],
        );

        self::assertSame('buyer asked 800 -> you offered 820.00', NegotiationTranscript::of($conversation));
    }

    public function testEveryCompletedRoundIsPairedInOrder(): void
    {
        $conversation = new BuyerConversation(buyer: [
            NegotiationFixture::buyerComment('Could you do 800?', '2026-09-18 09:00:00'),
            NegotiationFixture::buyerComment('815 would work too.', '2026-09-18 11:00:00'),
        ], agent: [
            NegotiationFixture::agentComment(
                'We can bring this quote down by 8.2% to 820.00 EUR. The offer is valid until 2026-10-02.',
                '2026-09-18 10:00:00',
            ),
            NegotiationFixture::agentComment(
                'We can bring this quote down by 10% to 800.00 EUR. The offer is valid until 2026-10-03.',
                '2026-09-18 12:00:00',
            ),
        ]);

        self::assertSame(
            "buyer asked 800 -> you offered 820.00\nbuyer asked 815 -> you offered 800.00",
            NegotiationTranscript::of($conversation),
        );
    }

    public function testABuyerCommentWithNoFigureStillClosesTheRound(): void
    {
        $conversation = new BuyerConversation(buyer: [NegotiationFixture::buyerComment(
            'Still too high, please reconsider.',
            '2026-09-18 09:00:00',
        )], agent: [NegotiationFixture::agentComment(
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-10-02.',
            '2026-09-18 10:00:00',
        )]);

        self::assertSame('buyer asked (unstated) -> you offered 950.00', NegotiationTranscript::of($conversation));
    }

    /**
     * A grouped total ("9,500.00") reaches the buyer over a rewording exactly
     * as often as the plain one -- RewordingGuard accepts either -- so the
     * transcript has to ungroup it the same way or misreport a five-figure
     * quote as a five-hundred one.
     */
    public function testAGroupedTotalIsReadCorrectly(): void
    {
        $conversation = new BuyerConversation(buyer: [NegotiationFixture::buyerComment(
            'Can we land at 9500?',
            '2026-09-18 09:00:00',
        )], agent: [NegotiationFixture::agentComment(
            'We can bring this quote down by 5% to 9,500.00 EUR. Valid until 2026-10-02.',
            '2026-09-18 10:00:00',
        )]);

        self::assertSame('buyer asked 9500 -> you offered 9500.00', NegotiationTranscript::of($conversation));
    }

    public function testOnlyTheLastFiveRoundsAreKeptSoAPromptCannotGrowWithoutLimit(): void
    {
        $buyer = [];
        $agent = [];

        for ($i = 1; $i <= 7; ++$i) {
            $buyer[] = NegotiationFixture::buyerComment(
                sprintf('Can you do %d?', 900 - $i),
                sprintf('2026-09-%02d 09:00:00', $i),
            );
            $agent[] = NegotiationFixture::agentComment(
                sprintf('We can bring this quote down by 1%% to %d.00 EUR. Valid until 2026-10-02.', 1000 - $i),
                sprintf('2026-09-%02d 10:00:00', $i),
            );
        }

        $transcript = NegotiationTranscript::of(new BuyerConversation($buyer, $agent));

        self::assertSame(
            5,
            substr_count($transcript, 'you offered'),
            'A long thread must not grow the prompt without limit.',
        );
        // Only rounds 3..7 (the last five) survive; round 1 and 2 fall off.
        self::assertStringNotContainsString('offered 999.00', $transcript);
        self::assertStringContainsString('offered 993.00', $transcript);
    }
}
