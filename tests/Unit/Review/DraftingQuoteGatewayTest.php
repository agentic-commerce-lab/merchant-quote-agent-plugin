<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteRevisionMismatch;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Review\DraftingQuoteGateway;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DraftingQuoteGatewayTest extends TestCase
{
    public function testPriceWritesGoToTheDraftVersionAndNeverToTheLiveQuote(): void
    {
        [$gateway, $live, $versions] = self::gateway();

        $gateway->updateQuote(
            'q1',
            new QuoteUpdate(
                discount: new Discount(DiscountType::Percentage, 5.0),
                expiresAt: new \DateTimeImmutable('+14 days'),
            ),
        );
        $gateway->recalculate('q1');
        $gateway->updateLineItems('q1', [new QuoteLineItemChange('line-1', unitPriceNet: 9.0)]);

        self::assertCount(1, $versions->created, 'One pass, one version.');
        self::assertSame(['updateQuote', 'recalculate', 'updateLineItems'], $versions->draft->calls);
        self::assertNotContains('updateQuote', $live->calls);
        self::assertNotContains('recalculate', $live->calls);
        self::assertSame($versions->created[0], $gateway->versionId());
    }

    public function testBookkeepingAndMirroredAsksStayLive(): void
    {
        [$gateway, $live, $versions] = self::gateway();

        $gateway->updateQuote('q1', new QuoteUpdate(customFields: ['merchant_quote_agent_escalated' => 'x']));
        $gateway->updateLineItems('q1', [new QuoteLineItemChange('line-1', requestedUnitPriceNet: 9.0)]);

        self::assertSame(['updateQuote', 'updateLineItems'], $live->calls);
        self::assertSame([], $versions->created);
    }

    public function testCommentsAndTransitionsReachNobody(): void
    {
        [$gateway, $live, $versions, $recorder, $writer] = self::gateway();

        $gateway->addComment('q1', 'We can offer 5%.');
        $gateway->transition('q1', QuoteTransition::Sent);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Clarified));

        self::assertSame([], $live->comments);
        self::assertSame([], $live->transitions);
        self::assertSame([], $versions->draft->comments);
        self::assertSame('We can offer 5%.', $writer->drafts[0]->replyToBuyer);
        self::assertSame('pending', $writer->drafts[0]->reviewStatus);
        self::assertNotNull($writer->drafts[0]->reviewFingerprint);
    }

    public function testReadsFollowTheDraftOnceItExists(): void
    {
        [$gateway, $live, $versions] = self::gateway();

        $gateway->fetchSnapshot('q1');
        self::assertSame(['fetchSnapshot'], $live->calls);

        $gateway->updateQuote('q1', new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0)));
        $gateway->fetchSnapshot('q1');

        self::assertSame(['updateQuote', 'fetchSnapshot'], $versions->draft->calls);
    }

    /** The precondition protects the LIVE quote: a buyer edit between the read and the write must still lose. */
    public function testTheRevisionPreconditionIsCheckedAgainstTheLiveQuote(): void
    {
        [$gateway] = self::gateway();

        $this->expectException(QuoteRevisionMismatch::class);

        $gateway->updateQuote(
            'q1',
            new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0)),
            new QuoteRevision('v1', new \DateTimeImmutable('2020-01-01 00:00:00.000')),
        );
    }

    /** @return array{0: DraftingQuoteGateway, 1: FakeQuoteGateway, 2: FakeDraftVersions, 3: DecisionRecorder, 4: FakeDecisionWriter} */
    private static function gateway(): array
    {
        $snapshot = QuoteSnapshotFixture::snapshot(comments: [QuoteSnapshotFixture::buyerComment(
            '2026-09-23 10:00:00.000',
        )]);
        $live = new FakeQuoteGateway([$snapshot]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$snapshot]));
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin($snapshot, new PassContext(ServicingTriggerReason::cases()[0], 0));

        return [new DraftingQuoteGateway($live, $versions, $recorder, $snapshot), $live, $versions, $recorder, $writer];
    }
}
