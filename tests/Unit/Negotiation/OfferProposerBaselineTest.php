<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaselineLines;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use PHPUnit\Framework\TestCase;

/**
 * #49: OfferProposer::propose() bounds a per-line offer against the stored
 * baseline, not the round's own (possibly already-reduced) lines. Split from
 * OfferProposerTest, which is already at the ten-method gate.
 */
final class OfferProposerBaselineTest extends TestCase
{
    private static function proposer(\MerchantQuoteAgentPlugin\Negotiation\ChatCompletionClient $client): OfferProposer
    {
        return new OfferProposer(
            $client,
            new PromptComposer('EXTRACT', 'NEGOTIATE BASE', 'REPLY {{tone}}'),
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

    /**
     * #49. The distinguishing fixture: the baseline price is HIGHER than the
     * current line price, so a test that confused the two would land on a
     * different number rather than passing by coincidence. The buyer's line
     * is at 90 after an earlier round; the baseline says it started at 100.
     * A 10% cap therefore floors this line at 90, not at 81.
     */
    public function testAPerLineOfferIsBoundedAgainstTheBaselineNotTheCurrentLines(): void
    {
        [$client] = ScriptedClient::spy([
            '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":85}],"message":"85 each."}',
        ]);
        $snapshot = NegotiationFixture::snapshot(totalNet: 900.0);
        $baseline = new QuoteBaselineLines(1000.0, [
            new QuoteLineSnapshot(identity: new QuoteLineIdentity('line-1'), quantity: 10, unitPriceNet: 100.0),
        ]);

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
                $baseline,
            );

        self::assertNull(
            $answer->offer,
            'An 85 line against a 100 baseline is 15% off and must be rejected by a 10% cap; '
            . 'accepting it means the current 90 line was used as the reference.',
        );
        self::assertSame(QuoteEscalationReason::ProposalRejected, $answer->escalation);
    }

    /** The same offer is fine when the baseline says the line started at 90. */
    public function testTheBaselineIsWhatDecides(): void
    {
        [$client] = ScriptedClient::spy([
            '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":85}],"message":"85 each."}',
        ]);
        $snapshot = NegotiationFixture::snapshot(totalNet: 900.0);
        $baseline = new QuoteBaselineLines(900.0, [
            new QuoteLineSnapshot(identity: new QuoteLineIdentity('line-1'), quantity: 10, unitPriceNet: 90.0),
        ]);

        $answer = self::proposer($client)
            ->propose(
                NegotiationFixture::settings(),
                SnapshotAdapter::toPolicy($snapshot),
                self::grantDecision(),
                SnapshotAdapter::conversation($snapshot),
                $baseline,
            );

        self::assertNotNull($answer->offer, 'An 85 line against a 90 baseline is 5.6% off, inside a 10% cap.');
    }
}
