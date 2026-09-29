<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationContext;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\TestCase;

final class OfferProposerTest extends TestCase
{
    private static function prompts(): PromptComposer
    {
        return new PromptComposer('EXTRACT', 'NEGOTIATE BASE', 'REPLY {{tone}}');
    }

    private static function proposer(\MerchantQuoteAgentPlugin\Negotiation\ModelPlatform $client): OfferProposer
    {
        return new OfferProposer(
            $client,
            self::prompts(),
            new OfferAuthorizer(),
            new DecisionRecorder(new FakeDecisionWriter()),
            new FakeCustomerHistoryFactory(),
        );
    }

    /** A grant-band decision: the buyer asked 5% against a 10% cap. */
    private static function grantDecision(): \MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision
    {
        $snapshot = SnapshotAdapter::toPolicy(NegotiationFixture::snapshot());

        return (new QuoteBandDecider())->decide(
            $snapshot->withBuyerTargetNet(950.0),
            NegotiationFixture::settings()->policy->price,
        );
    }

    public function testAnInBandAskGetsAModelProposal(): void
    {
        [$client, $spy] = ScriptedClient::spy([
            '{"action":"offer","message":"5% for you.","terms":{"discountPercent":5}}',
        ]);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                new NegotiationContext(
                    $snapshot->identity->customerId,
                    $snapshot->identity->quoteId,
                    SnapshotAdapter::conversation($snapshot),
                ),
            );

        self::assertNotNull($answer->offer);
        self::assertSame(5.0, $answer->offer->price->discountPercent);
        self::assertSame('5% for you.', $answer->modelMessage);
        self::assertSame(1, $spy->calls);
    }

    /**
     * Issue #47, and the half OfferLevelMirrorTest cannot prove: that the
     * mirror is actually reached. The buyer itemised the ask (a requested
     * price on the line), the model answered quote-wide anyway, and what
     * comes back must be a line price — otherwise OfferApplier writes a
     * quote-level discount to an ask that named a line.
     */
    public function testAQuoteWideAnswerToAPerLineAskComesBackAsALinePrice(): void
    {
        [$client] = ScriptedClient::spy(['{"action":"offer","message":"5% for you.","terms":{"discountPercent":5}}']);
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 95.0);

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                new NegotiationContext(
                    $snapshot->identity->customerId,
                    $snapshot->identity->quoteId,
                    SnapshotAdapter::conversation($snapshot),
                ),
            );

        self::assertNotNull($answer->offer);
        self::assertNull(
            $answer->offer->price->discountPercent,
            'The quote-wide discount reached the offer: OfferLevelMirror is not reached from propose().',
        );
        // The line is 100.00 before the round; 5% off it is 95.00.
        self::assertEquals(
            [new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice('line-1', 95.0)],
            $answer->offer->price->linePricesNet,
        );
    }

    /**
     * #49 removed the restriction this test used to name: OfferRound no
     * longer escalates a later-round per-line offer, so the buyer-level
     * correction now runs on every round, not just the first.
     */
    public function testAQuoteWideAnswerIsConvertedOnALaterRoundToo(): void
    {
        [$client] = ScriptedClient::spy(['{"action":"offer","message":"5% for you.","terms":{"discountPercent":5}}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::agentComment('Our first offer.', '2026-08-28 09:00:00'),
            NegotiationFixture::buyerComment('Still too high.', '2026-08-28 10:00:00'),
        ], requestedUnitPrice: 95.0);

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                new NegotiationContext(
                    $snapshot->identity->customerId,
                    $snapshot->identity->quoteId,
                    SnapshotAdapter::conversation($snapshot),
                ),
            );

        self::assertNotNull($answer->offer);
        self::assertNull($answer->offer->price->discountPercent);
        // The line is 100.00 before the round; 5% off it is 95.00.
        self::assertEquals(
            [new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice('line-1', 95.0)],
            $answer->offer->price->linePricesNet,
        );
    }

    public function testTheModelIsToldItsAuthorityAndTheMerchantStrategy(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"action":"offer","message":"ok","terms":{"discountPercent":5}}']);
        $snapshot = NegotiationFixture::snapshot();

        self::proposer($client)
            ->propose(
                NegotiationFixture::settings(strategy: 'concede in 1% steps'),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                new NegotiationContext(
                    $snapshot->identity->customerId,
                    $snapshot->identity->quoteId,
                    SnapshotAdapter::conversation($snapshot),
                ),
            );

        self::assertStringContainsString('concede in 1% steps', $spy->systemPrompts[0]);
        // The rendered cap line, not the bare digits: '10' is also satisfied by
        // the fixture's own 100.00 line price and 1000.00 quote total, so it
        // passed whether or not the cap reached the model at all.
        self::assertStringContainsString(
            'maximum discount you may grant: 10.00%',
            $spy->userPrompts[0],
            'The cap must be stated to the model.',
        );
        // The round-1 prompt has nothing to remember yet, so the transcript
        // section must not appear at all rather than as an empty, noisy header.
        self::assertStringNotContainsString('Earlier rounds of this negotiation', $spy->userPrompts[0]);
    }

    /**
     * #166, and its acceptance gate: given a thread, an offer may never
     * concede more than the buyer's own latest ask. Reproduces the measured
     * session — buyer asks 800, agent offers 820, buyer softens to 815 — and
     * pins that this round's prompt actually carries the earlier round's
     * figures, which is the memory that lets a merchant's strategy (and,
     * deterministically, OfferAuthorizer) hold the line at 815 rather than
     * re-deriving a fresh concession from the anchored baseline alone. The
     * band/authorizer check itself is untouched by this issue: this pins the
     * PROMPT'S input to it.
     */
    public function testTheNegotiatePromptCarriesEarlierRoundsAndNeverLosesTheBuyersLatestAsk(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"action":"offer","message":"ok","terms":{"discountPercent":8}}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('Could you do 800?', '2026-09-18 09:00:00'),
            NegotiationFixture::agentComment(
                'We can bring this quote down by 8% to 820.00 EUR. The offer is valid until 2026-10-02.',
                '2026-09-18 10:00:00',
            ),
            NegotiationFixture::buyerComment('815 would work too.', '2026-09-18 11:00:00'),
        ]);

        self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                new NegotiationContext(
                    $snapshot->identity->customerId,
                    $snapshot->identity->quoteId,
                    SnapshotAdapter::conversation($snapshot),
                ),
            );

        // The buyer's own latest ask this round (815) and last round's offer
        // (820.00) both reach the model, in figures — the fact a prompt with
        // no memory of itself cannot state at all.
        self::assertStringContainsString(
            "Earlier rounds of this negotiation (buyer's ask -> your offer, oldest first):\n"
            . 'buyer asked 800 -> you offered 820.00',
            $spy->userPrompts[0],
        );
        // The newest buyer comment is THIS round's ask, not a completed
        // round: it must not appear inside the transcript block, only after it.
        self::assertStringContainsString("Buyer's latest comment:\n815 would work too.", $spy->userPrompts[0]);
    }

    public function testAProposalOutsideAuthorityIsRejected(): void
    {
        // The model asked for 40% against a 10% cap. The rules, not the model,
        // decide what is permitted — this is the guardrail working.
        [$client] = ScriptedClient::spy(['{"action":"offer","message":"40% off!","terms":{"discountPercent":40}}']);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                new NegotiationContext(
                    $snapshot->identity->customerId,
                    $snapshot->identity->quoteId,
                    SnapshotAdapter::conversation($snapshot),
                ),
            );

        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::ProposalRejected, $answer->escalation);
    }

    public function testTheModelMayDeclineAndItsReasonIsKept(): void
    {
        [$client] = ScriptedClient::spy([
            '{"action":"escalate","escalationReason":"buyer wants a term I cannot offer","message":""}',
        ]);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                new NegotiationContext(
                    $snapshot->identity->customerId,
                    $snapshot->identity->quoteId,
                    SnapshotAdapter::conversation($snapshot),
                ),
            );

        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::ModelDeclined, $answer->escalation);
        self::assertStringContainsString('term I cannot offer', $answer->escalationDetail);
    }
}
