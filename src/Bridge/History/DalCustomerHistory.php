<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderHistory;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryInterface;

/**
 * The port, over the DAL. Holds no customer id of its own — the scope does, and
 * every read takes it from there.
 *
 * Read-only throughout, and constructed fresh per pass, so there is no cache to
 * go stale and no instance that can outlive the quote it was built for.
 */
final readonly class DalCustomerHistory implements CustomerHistoryInterface
{
    public function __construct(
        private CustomerScope $scope,
        private QuoteHistoryReads $quotes,
        private OrderHistoryReads $orders,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[\Override]
    public function summary(): CustomerSummary
    {
        return new CustomerSummary(
            quotes: $this->quotes->stats($this->scope),
            orders: $this->orders->stats($this->scope),
        );
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[\Override]
    public function quotes(): array
    {
        return $this->quotes->entries($this->scope);
    }

    #[\Override]
    public function orders(): OrderHistory
    {
        return $this->orders->history($this->scope);
    }

    #[\Override]
    public function productPurchases(string $productId): array
    {
        return $this->orders->purchasesOf($this->scope, $productId);
    }
}
