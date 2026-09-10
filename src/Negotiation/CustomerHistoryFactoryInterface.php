<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/** Binds a reader to the customer from the serviced quote, never model-supplied text. */
interface CustomerHistoryFactoryInterface
{
    /**
     * @param string $customerId      the quote's own customer; '' yields NoCustomerHistory
     * @param string $servicedQuoteId the quote this pass is negotiating, excluded from its own
     *     history so that "history" means OTHER quotes. Without it the model reads a discount it
     *     has already applied here back as an unspent precedent — see CustomerScope::quoteCriteria().
     */
    public function for(string $customerId, string $servicedQuoteId): CustomerHistoryInterface;
}
