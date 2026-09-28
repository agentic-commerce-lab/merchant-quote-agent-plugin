<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Review\DraftView;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DraftViewTest extends TestCase
{
    public function testAPerLineDraftShowsLiveAndDraftedPrices(): void
    {
        $live = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null, unitPriceNet: 10.0)]);
        $draft = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null, unitPriceNet: 9.0)]);
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';
        $record->outcome = 'offered';
        $record->writes = ['claim', 'updateLineItems', 'updateQuote', 'recalculate'];
        $record->replyToBuyer = 'We can do 9.00 per unit.';
        $record->maxDiscountPercent = 10.0;

        $view = DraftView::of(new PendingDraft($record, $live, new FakeQuoteGateway([$draft]), false), $draft, null);

        self::assertSame('lines', $view['pricing']);
        self::assertSame(
            [['id' => 'line-1', 'label' => 'Widget', 'quantity' => 10, 'live' => 10.0, 'draft' => 9.0]],
            $view['lines'],
        );
        self::assertSame('We can do 9.00 per unit.', $view['reply']);
        self::assertFalse($view['replyRedrafted']);
        self::assertTrue(
            DraftView::of(
                new PendingDraft($record, $live, new FakeQuoteGateway([$draft]), false),
                $draft,
                'We can do 9.00 per unit today.',
            )['replyRedrafted'],
        );
        self::assertFalse($view['stale']);
        self::assertFalse($view['previewEdited']);

        $record->sentChanges = ['editedByMerchant' => true];
        self::assertTrue(
            DraftView::of(
                new PendingDraft($record, $live, new FakeQuoteGateway([$draft]), false),
                $draft,
                null,
            )['previewEdited'],
        );
    }

    /**
     * QuoteTotalRounding drafts an absolute amount. A percent-only read showed
     * it as no discount at all, so a typed percentage silently replaced it.
     */
    public function testTheDiscountKeepsItsTypeLiveAndDrafted(): void
    {
        $live = self::withTotals(new QuoteTotals(1000.0, new Discount(DiscountType::Percentage, 5.0), 1190.0));
        $draft = self::withTotals(new QuoteTotals(924.37, new Discount(DiscountType::Absolute, 90.0), 1100.0));
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';
        $record->writes = ['claim', 'updateQuote', 'recalculate'];

        $view = DraftView::of(new PendingDraft($record, $live, new FakeQuoteGateway([$draft]), false), $draft, null);

        self::assertSame('discount', $view['pricing']);
        self::assertSame(
            [
                'live' => ['type' => 'percentage', 'value' => 5.0],
                'draft' => ['type' => 'absolute', 'value' => 90.0],
            ],
            $view['discount'],
        );
        self::assertSame(['net' => 924.37, 'gross' => 1100.0], $view['totals']['draft']);
        self::assertArrayNotHasKey('discountPercent', $view);

        $none = QuoteSnapshotFixture::snapshot();
        self::assertSame(
            ['live' => null, 'draft' => null],
            DraftView::of(
                new PendingDraft($record, $none, new FakeQuoteGateway([$none]), false),
                $none,
                null,
            )['discount'],
        );
    }

    public function testAClarificationHasNoPricing(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';
        $record->outcome = 'clarified';

        self::assertNull(
            DraftView::of(new PendingDraft($record, $live, null, false), $live, 'Which colour?')['pricing'],
        );
    }

    private static function withTotals(QuoteTotals $totals): QuoteSnapshot
    {
        $base = QuoteSnapshotFixture::snapshot();

        return new QuoteSnapshot($base->identity, $base->revision, $totals, $base->lifecycle, $base->content);
    }
}
