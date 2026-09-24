<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
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
}
