<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;

final class QuoteSnapshotFixture
{
    /**
     * @param list<QuoteComment> $comments
     * @param array<string, mixed> $customFields
     */
    public static function snapshot(
        string $state = 'open',
        array $comments = [],
        array $customFields = [],
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-27 10:00:00.000')),
            totals: new QuoteTotals(totalNet: 100.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: $state, customFields: $customFields),
            content: new QuoteContent(comments: $comments),
        );
    }

    public static function buyerComment(string $createdAt): QuoteComment
    {
        return new QuoteComment('buyer ask', customerId: 'customer-1', createdAt: new \DateTimeImmutable($createdAt));
    }
}
