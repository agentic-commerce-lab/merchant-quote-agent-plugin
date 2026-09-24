<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Review\DraftPriceGuard;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;

final class DraftPriceGuardTest extends TestCase
{
    public function testAPriceAboveLiveIsRefusedEvenWhenItIsBelowAnOldBaseline(): void
    {
        $baseline = NegotiationFixture::snapshot(totalNet: 1000.0);
        $current = NegotiationFixture::snapshot(totalNet: 900.0);
        $live = new QuoteSnapshot(
            $current->identity,
            $current->revision,
            $current->totals,
            new QuoteLifecycle('open', $current->lifecycle->expiresAt, QuoteBaseline::stamp($baseline)),
            $current->content,
        );
        $raised = NegotiationFixture::snapshot(totalNet: 950.0);
        $record = new QuoteDecisionRecord();
        $record->quoteId = 'q1';

        $this->expectException(InvalidReviewRequest::class);

        DraftPriceGuard::reduction(new PendingDraft($record, $live, new FakeQuoteGateway([$raised]), false), $raised);
    }
}
