<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Review\DraftEditor;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DraftEditorTest extends TestCase
{
    public function testEditsAreWrittenIntoTheDraftAndRecalculated(): void
    {
        $snapshot = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null)]);
        $draft = new FakeQuoteGateway([$snapshot]);

        DraftEditor::apply(self::pending($snapshot, $draft), new DraftEdits(8.0, ['line-1' => 9.5]));

        self::assertSame(
            ['fetchSnapshot', 'updateLineItems', 'updateQuote', 'recalculate', 'fetchSnapshot'],
            $draft->calls,
        );
        self::assertSame(9.5, $draft->lineItemChanges[0]->unitPriceNet);
        self::assertSame(8.0, $draft->quoteUpdates[0]->discount?->value);
    }

    public function testNoEditOnlyReadsTheDraft(): void
    {
        $snapshot = QuoteSnapshotFixture::snapshot();
        $draft = new FakeQuoteGateway([$snapshot]);

        DraftEditor::apply(self::pending($snapshot, $draft), new DraftEdits());

        self::assertSame(['fetchSnapshot'], $draft->calls);
    }

    public function testALineThatIsNotOnTheQuoteIsRefused(): void
    {
        $snapshot = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null)]);

        $this->expectException(InvalidReviewRequest::class);

        DraftEditor::apply(self::pending($snapshot, new FakeQuoteGateway([$snapshot])), new DraftEdits(linePrices: [
            'someone-elses-line' => 1.0,
        ]));
    }

    public function testAClarificationHasNoPricesToEdit(): void
    {
        $this->expectException(InvalidReviewRequest::class);

        DraftEditor::apply(self::pending(QuoteSnapshotFixture::snapshot(), null), new DraftEdits(5.0));
    }

    private static function pending(
        \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $live,
        ?FakeQuoteGateway $draft,
    ): PendingDraft {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';

        return new PendingDraft($record, $live, $draft, false);
    }
}
