<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryFactoryInterface;
use MerchantQuoteAgentPlugin\Negotiation\CustomerHistoryInterface;
use MerchantQuoteAgentPlugin\Negotiation\NoCustomerHistory;

/**
 * Binds a customer id to a reader, once, per pass.
 *
 * This is the ONLY place a customer id enters the history machinery, and its
 * caller passes the id off the quote the pass is servicing. Nothing
 * model-supplied reaches it: `for()` is never called with a value that came out
 * of a model response, and CustomerHistoryInterface has no method that takes a
 * customer at all.
 */
final readonly class CustomerHistoryFactory implements CustomerHistoryFactoryInterface
{
    public function __construct(
        private QuoteHistoryReads $quotes,
        private OrderHistoryReads $orders,
        private QuoteVersionResolver $versions,
    ) {}

    /** @param string $customerId the quote's own customer; '' when the row is broken */
    #[\Override]
    public function for(string $customerId): CustomerHistoryInterface
    {
        $scope = new CustomerScope($customerId, $this->versions);

        if ($scope->isEmpty()) {
            return new NoCustomerHistory('the quote carries no customer id');
        }

        return new DalCustomerHistory($scope, $this->quotes, $this->orders);
    }
}
