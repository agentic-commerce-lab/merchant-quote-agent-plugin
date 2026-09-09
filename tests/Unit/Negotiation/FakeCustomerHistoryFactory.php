<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryFactoryInterface;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryInterface;
use MerchantQuoteAgentPlugin\Negotiation\NoCustomerHistory;

final class FakeCustomerHistoryFactory implements CustomerHistoryFactoryInterface
{
    /** @var list<string> */
    public array $boundTo = [];

    public function __construct(
        public CustomerHistoryInterface $history = new NoCustomerHistory('test has no history'),
    ) {}

    #[\Override]
    public function for(string $customerId): CustomerHistoryInterface
    {
        $this->boundTo[] = $customerId;

        return $this->history;
    }
}
