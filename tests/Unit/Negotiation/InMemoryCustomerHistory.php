<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderHistory;
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

    public function __construct(
        public CustomerSummary $brief = new CustomerSummary(),
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
