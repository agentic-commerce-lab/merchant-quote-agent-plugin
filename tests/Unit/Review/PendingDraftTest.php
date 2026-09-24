<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class PendingDraftTest extends TestCase
{
    public function testReviewEditsIncludePreviewedChangesAndReplyChanges(): void
    {
        $record = new QuoteDecisionRecord();
        $record->replyToBuyer = 'Original reply';
        $pending = new PendingDraft($record, QuoteSnapshotFixture::snapshot(), null, false);

        self::assertFalse($pending->wasEditedByMerchant(new DraftEdits(), 'Original reply'));
        self::assertTrue($pending->wasEditedByMerchant(new DraftEdits(discountPercent: 8.0), 'Original reply'));
        self::assertTrue($pending->wasEditedByMerchant(new DraftEdits(), 'Merchant reply'));

        $record->sentChanges = ['editedByMerchant' => true];
        self::assertTrue($pending->wasEditedByMerchant(new DraftEdits(), 'Original reply'));
    }
}
