<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderStats;
use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteStats;
use MerchantQuoteAgentPlugin\Negotiation\Response\HistoryRequest;
use MerchantQuoteAgentPlugin\Negotiation\Response\HistoryRequestKind;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use PHPUnit\Framework\TestCase;

final class HistoryRecordTest extends TestCase
{
    public function testTheQuoteCompanyIsAttributedToThePass(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->finish(null);

        self::assertSame('cust-1', $writer->drafts[0]->customerId);
    }

    public function testAMissingCompanyIsStoredAsNull(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshotWithCustomer(''), NegotiationFixture::context());
        $recorder->finish(null);

        self::assertNull($writer->drafts[0]->customerId);
    }

    public function testTheEntireSummaryIsFlattenedForReplay(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->recordHistorySummary(new CustomerSummary(
            quotes: new QuoteStats(
                seen: 8,
                converted: 3,
                lost: 2,
                offersMade: 6,
                offersAccepted: 3,
                lastGrantedDiscountPercent: 7.5,
            ),
            orders: new OrderStats(
                count: 4,
                lifetimeNet: 1234.5,
                lastOrderAt: new \DateTimeImmutable('2026-09-08T12:34:56+02:00'),
                currencyIso: 'EUR',
            ),
        ));
        $recorder->finish(null);

        self::assertSame(
            [
                'available' => true,
                'reason' => null,
                'quotesSeen' => 8,
                'quotesConverted' => 3,
                'quotesLost' => 2,
                'offersMade' => 6,
                'offersAccepted' => 3,
                'lastGrantedDiscountPercent' => 7.5,
                'orderCount' => 4,
                'lifetimeNet' => 1234.5,
                'currencyIso' => 'EUR',
                'lifetimeNetUnavailableReason' => null,
                'lastOrderAt' => '2026-09-08T12:34:56+02:00',
                'rounds' => [],
            ],
            $writer->drafts[0]->historyReads,
        );
    }

    public function testKnownEmptyHistoryPreservesZeroCountsAndNullableValues(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->recordHistorySummary(new CustomerSummary());
        $recorder->finish(null);

        self::assertSame(
            [
                'available' => true,
                'reason' => null,
                'quotesSeen' => 0,
                'quotesConverted' => 0,
                'quotesLost' => 0,
                'offersMade' => 0,
                'offersAccepted' => 0,
                'lastGrantedDiscountPercent' => null,
                'orderCount' => 0,
                'lifetimeNet' => 0.0,
                'currencyIso' => null,
                'lifetimeNetUnavailableReason' => null,
                'lastOrderAt' => null,
                'rounds' => [],
            ],
            $writer->drafts[0]->historyReads,
        );
    }

    public function testUnavailableHistoryRetainsItsReason(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->recordHistorySummary(CustomerSummary::unavailable('bridge_unavailable'));
        $recorder->finish(null);

        self::assertFalse($writer->drafts[0]->historyReads['available']);
        self::assertSame('bridge_unavailable', $writer->drafts[0]->historyReads['reason']);
    }

    public function testRoundsPreserveTheSummaryAndExactResultsInRequestOrder(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->recordHistorySummary(new CustomerSummary());
        $recorder->recordHistoryRound(new HistoryRequest(HistoryRequestKind::Orders), "Orders:\n  €42.50\n");
        $recorder->recordHistoryRound(new HistoryRequest(HistoryRequestKind::ProductPurchases, 'prod-1'), 'Paid 12.00');
        $recorder->recordHistoryRound(new HistoryRequest(HistoryRequestKind::QuoteHistory), 'No earlier quotes');
        $recorder->finish(null);

        self::assertTrue($writer->drafts[0]->historyReads['available']);
        self::assertSame(
            [
                ['kind' => 'orders', 'productId' => null, 'result' => "Orders:\n  €42.50\n"],
                ['kind' => 'product_purchases', 'productId' => 'prod-1', 'result' => 'Paid 12.00'],
                ['kind' => 'quote_history', 'productId' => null, 'result' => 'No earlier quotes'],
            ],
            $writer->drafts[0]->historyReads['rounds'],
        );
    }

    public function testASummaryDoesNotDiscardEarlierRounds(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->recordHistoryRound(new HistoryRequest(HistoryRequestKind::Orders), 'Earlier orders');
        $recorder->recordHistorySummary(new CustomerSummary());
        $recorder->finish(null);

        self::assertSame(
            [
                ['kind' => 'orders', 'productId' => null, 'result' => 'Earlier orders'],
            ],
            $writer->drafts[0]->historyReads['rounds'],
        );
        self::assertTrue($writer->drafts[0]->historyReads['available']);
    }

    public function testARoundCanBeRecordedWithoutASummaryOrRequestKind(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->recordHistoryRound(new HistoryRequest(), 'Invalid request');
        $recorder->finish(null);

        self::assertSame(
            [
                'rounds' => [['kind' => null, 'productId' => null, 'result' => 'Invalid request']],
            ],
            $writer->drafts[0]->historyReads,
        );
    }

    public function testCallsOutsideAPassAreNoOpsAndHistoryResetsBetweenPasses(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->recordHistorySummary(new CustomerSummary());
        $recorder->recordHistoryRound(new HistoryRequest(), 'Before begin');
        $recorder->finish(null);
        self::assertSame([], $writer->drafts);

        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->recordHistorySummary(new CustomerSummary());
        $recorder->recordHistoryRound(new HistoryRequest(), 'First pass');
        $recorder->finish(null);
        $recorder->recordHistoryRound(new HistoryRequest(), 'After finish');
        $recorder->begin(NegotiationFixture::snapshotWithCustomer('cust-2'), NegotiationFixture::context());
        $recorder->finish(null);

        self::assertCount(2, $writer->drafts);
        self::assertNull($writer->drafts[1]->historyReads);
        self::assertSame('cust-2', $writer->drafts[1]->customerId);
    }

    public function testMixedCurrenciesRemainUnavailableAlongsideTheOrderFacts(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());
        $recorder->recordHistorySummary(
            new CustomerSummary(orders: new OrderStats(
                count: 2,
                lifetimeNet: null,
                lastOrderAt: new \DateTimeImmutable('2026-09-08'),
                unavailableReason: 'order currencies are mixed or unknown',
            )),
        );
        $recorder->finish(null);
        $record = $writer->drafts[0]->historyReads;
        self::assertTrue($record['available']);
        self::assertSame(2, $record['orderCount']);
        self::assertNull($record['lifetimeNet']);
        self::assertNull($record['currencyIso']);
        self::assertSame('order currencies are mixed or unknown', $record['lifetimeNetUnavailableReason']);
        self::assertNotNull($record['lastOrderAt']);
    }
}
