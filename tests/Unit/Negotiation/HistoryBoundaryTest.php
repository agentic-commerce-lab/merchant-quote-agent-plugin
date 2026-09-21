<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationContext;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use PHPUnit\Framework\TestCase;

final class HistoryBoundaryTest extends TestCase
{
    public function testNoPricedBandDoesNotBindHistoryOrCallModel(): void
    {
        $h = new HistoryProposerHarness([]);
        $snapshot = NegotiationFixture::snapshot();
        $policy = SnapshotAdapter::toPolicy($snapshot);
        $settings = NegotiationFixture::settings();
        $decision = (new QuoteBandDecider())->decide($policy->withBuyerTargetNet(500), $settings->policy->price);
        $answer = $h->proposer->propose(
            $settings,
            $policy,
            $decision,
            new NegotiationContext('cust-1', $snapshot->identity->quoteId, SnapshotAdapter::conversation($snapshot)),
        );
        self::assertSame(QuoteEscalationReason::NeedsHumanReview, $answer->escalation);
        self::assertSame([], $h->factory->boundTo);
        self::assertSame(0, $h->history->summaryCalls);
        self::assertSame(0, $h->spy->calls);
    }

    public function testOnQuoteProductRequestReadsTheProductIdRatherThanLineId(): void
    {
        $h = new HistoryProposerHarness([
            HistoryProposerHarness::request('product_purchases', 'prod-1'),
            HistoryProposerHarness::offer(),
        ]);
        self::assertNotNull($h->propose()->offer);
        self::assertSame(['prod-1'], $h->history->products);
        self::assertSame('prod-1', $h->writer->drafts[0]->historyReads['rounds'][0]['productId']);
    }

    public function testRefusalsUseTheSameBudgetAsReturnedHistory(): void
    {
        $h = new HistoryProposerHarness([
            HistoryProposerHarness::request('product_purchases', 'outside'),
            HistoryProposerHarness::request('product_purchases', 'outside'),
            HistoryProposerHarness::request(),
        ]);
        self::assertSame(QuoteEscalationReason::ModelUnavailable, $h->propose()->escalation);
        self::assertSame(0, $h->history->quoteCalls);
        self::assertSame([], $h->history->products);
        self::assertCount(2, $h->writer->drafts[0]->historyReads['rounds']);
    }

    public function testMissingCustomerContinuesWithoutHistoryAndLogsWarning(): void
    {
        $factory = new FakeCustomerHistoryFactory();
        $h = PipelineHarness::with(
            [HistoryProposerHarness::offer(), PipelineHarness::rewordedReply()],
            historyFactory: $factory,
        );
        $base = NegotiationFixture::snapshot(requestedUnitPrice: 95);
        $snapshot = new QuoteSnapshot(
            NegotiationFixture::snapshotWithCustomer('')->identity,
            $base->revision,
            $base->totals,
            $base->lifecycle,
            $base->content,
        );
        $outcome = $h->pipeline->service(
            $snapshot,
            $h->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );
        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame([''], $factory->boundTo);
        self::assertNotNull($h->logger->contextOf('no customer id'));
        self::assertSame('warning', $h->logger->records[0]['level']);
        self::assertStringNotContainsString('INTERNAL', $h->spy->userPrompts[0]);
        self::assertFalse($h->writer->drafts[0]->historyReads['available']);
        // A pass that continued without history still answers the buyer in the
        // model's words, not the fallback's.
        self::assertSame([PipelineHarness::rewordedReply()], $h->gateway->comments);
    }
}
