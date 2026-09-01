<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
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

    private static function proposer(\MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient $client): OfferProposer
    {
        return new OfferProposer(
            $client,
            self::prompts(),
            new OfferAuthorizer(),
            new DecisionRecorder(new FakeDecisionWriter()),
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
        [$client, $spy] = ScriptedClient::spy(['{"action":"offer","discount_percent":5,"message":"5% for you."}']);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
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
        [$client] = ScriptedClient::spy(['{"action":"offer","discount_percent":5,"message":"5% for you."}']);
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 95.0);

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
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
     * The other half of #47's restriction, and the one the integration suite
     * had to teach us: OfferRound escalates any per-line offer once the agent
     * has replied before (#2(a)'s open half — the reference lines are
     * re-captured each round, so round two would compound past the cap).
     * Converting here would turn an answerable quote into a human's, which is
     * worse than the wrong-level answer. Without this guard,
     * NegotiationPipelineTest and DecisionRecordTest both flip to escalated.
     */
    public function testAQuoteWideAnswerIsLeftAloneOnceTheAgentHasAlreadyReplied(): void
    {
        [$client] = ScriptedClient::spy(['{"action":"offer","discount_percent":5,"message":"5% for you."}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::agentComment('Our first offer.', '2026-08-28 09:00:00'),
            NegotiationFixture::buyerComment('Still too high.', '2026-08-28 10:00:00'),
        ], requestedUnitPrice: 95.0);

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertNotNull($answer->offer);
        self::assertSame(
            5.0,
            $answer->offer->price->discountPercent,
            'A later round was converted to per-line prices, which OfferRound escalates.',
        );
        self::assertNull($answer->offer->price->linePricesNet);
    }

    public function testTheModelIsToldItsAuthorityAndTheMerchantStrategy(): void
    {
        [$client, $spy] = ScriptedClient::spy(['{"action":"offer","discount_percent":5,"message":"ok"}']);
        $snapshot = NegotiationFixture::snapshot();

        self::proposer($client)
            ->propose(
                NegotiationFixture::settings(strategy: 'concede in 1% steps'),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertStringContainsString('concede in 1% steps', $spy->systemPrompts[0]);
        self::assertStringContainsString('10', $spy->userPrompts[0], 'The cap must be stated to the model.');
    }

    public function testAProposalOutsideAuthorityIsRejected(): void
    {
        // The model asked for 40% against a 10% cap. The rules, not the model,
        // decide what is permitted — this is the guardrail working.
        [$client] = ScriptedClient::spy(['{"action":"offer","discount_percent":40,"message":"40% off!"}']);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::ProposalRejected, $answer->escalation);
    }

    public function testTheModelMayDeclineAndItsReasonIsKept(): void
    {
        [$client] = ScriptedClient::spy([
            '{"action":"escalate","escalation_reason":"buyer wants a term I cannot offer","message":""}',
        ]);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertNull($answer->offer);
        self::assertSame(QuoteEscalationReason::NeedsHumanReview, $answer->escalation);
        self::assertStringContainsString('term I cannot offer', $answer->escalationDetail);
    }

    public function testRulesOnlyModePricesFromTheBandWithNoModelCall(): void
    {
        [$client, $spy] = ScriptedClient::spy([]);
        $snapshot = NegotiationFixture::snapshot();

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(rulesOnly: true),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
            );

        self::assertNotNull($answer->offer);
        self::assertSame(0, $spy->calls, 'Rules-only must not let a model choose the number.');
        self::assertNull($answer->promptHash);
    }
}
