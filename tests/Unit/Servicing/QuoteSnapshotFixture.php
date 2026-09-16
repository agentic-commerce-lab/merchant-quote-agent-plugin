<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
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
        array $lines = [],
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-27 10:00:00.000')),
            totals: new QuoteTotals(totalNet: 100.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: $state, customFields: $customFields),
            content: new QuoteContent(lines: $lines, comments: $comments),
        );
    }

    /** One line quoted at $unitPriceNet a unit, with the buyer's structured ask on it. */
    public static function line(
        ?float $requestedUnitPrice,
        string $lineItemId = 'line-1',
        float $unitPriceNet = 10.0,
    ): QuoteLineSnapshot {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity($lineItemId, 'Widget', 'prod-1'),
            quantity: 10,
            unitPriceNet: $unitPriceNet,
            totalNet: $unitPriceNet * 10,
            requestedUnitPrice: $requestedUnitPrice,
        );
    }

    public static function buyerComment(string $createdAt): QuoteComment
    {
        return new QuoteComment('buyer ask', customerId: 'customer-1', createdAt: new \DateTimeImmutable($createdAt));
    }

    /** A merchant's note through the administration: createdById, nothing else. */
    public static function merchantComment(string $createdAt): QuoteComment
    {
        return new QuoteComment('internal note', createdById: 'user-1', createdAt: new \DateTimeImmutable($createdAt));
    }
}
