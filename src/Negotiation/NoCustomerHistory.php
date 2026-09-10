<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderHistory;

/**
 * What a pass gets when the quote's customer cannot be read.
 *
 * A null object rather than a nullable dependency: every call site would
 * otherwise need its own null branch, and one that forgot would be the branch
 * that reads without a filter. Degrading to empty history is the pre-#100
 * behaviour, so negotiation character does not change — and the reason travels
 * to the audit record, which is what keeps it from being silent.
 */
final readonly class NoCustomerHistory implements CustomerHistoryInterface
{
    public function __construct(
        private string $reason,
    ) {}

    #[\Override]
    public function summary(): CustomerSummary
    {
        return CustomerSummary::unavailable($this->reason);
    }

    #[\Override]
    public function quotes(): array
    {
        return [];
    }

    #[\Override]
    public function orders(): OrderHistory
    {
        return new OrderHistory();
    }

    #[\Override]
    public function productPurchases(string $productId): array
    {
        return [];
    }
}
