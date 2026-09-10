<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderHistory;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderStats;
use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteStats;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryInterface;

final class InMemoryCustomerHistory implements CustomerHistoryInterface
{
    public int $summaryCalls = 0;
    public int $quoteCalls = 0;
    public int $orderCalls = 0;
    /** @var list<string> */
    public array $products = [];
    public ?\Throwable $summaryError = null;
    public ?\Throwable $readError = null;

    /**
     * Defaults to an account that HAS a prior record, because most callers are
     * testing what happens to a brief that exists. An all-zero summary now
     * renders no block at all — the serviced quote is excluded from its own
     * history, so `seen: 0` means a genuinely blank account — and that case is
     * covered explicitly by CustomerBriefTest rather than by every harness.
     */
    public function __construct(
        public CustomerSummary $brief = new CustomerSummary(
            quotes: new QuoteStats(seen: 4, converted: 1, lost: 2),
            orders: new OrderStats(2, 3400.0, new \DateTimeImmutable('2026-06-01'), 'EUR'),
        ),
    ) {}

    #[\Override]
    public function summary(): CustomerSummary
    {
        ++$this->summaryCalls;
        if ($this->summaryError !== null) {
            throw $this->summaryError;
        }

        return $this->brief;
    }

    #[\Override]
    public function quotes(): array
    {
        ++$this->quoteCalls;
        if ($this->readError !== null) {
            throw $this->readError;
        }

        return [];
    }

    #[\Override]
    public function orders(): OrderHistory
    {
        ++$this->orderCalls;

        return new OrderHistory();
    }

    #[\Override]
    public function productPurchases(string $productId): array
    {
        $this->products[] = $productId;

        return [];
    }
}
