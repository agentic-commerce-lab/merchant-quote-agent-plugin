<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * A factory that never reads a company's history, for a caller that must not
 * -- the nightly self-improvement replay (Task 11), which runs in a batch
 * process with no serviced quote of its own and answers every arm from the
 * same blank slate on purpose (see ReplayEvaluator's own docblock).
 */
final readonly class NoCustomerHistoryFactory implements CustomerHistoryFactoryInterface
{
    #[\Override]
    public function for(string $customerId, string $servicedQuoteId): CustomerHistoryInterface
    {
        return new NoCustomerHistory('the nightly self-improvement replay never reads customer history');
    }
}
