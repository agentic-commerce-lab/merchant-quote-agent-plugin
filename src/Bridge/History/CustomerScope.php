<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * The one place a history Criteria is built, and the one place the customer id
 * lives.
 *
 * Why a class rather than a filter each read adds for itself: a per-read filter
 * is a thing a new read can forget, and forgetting it means an unfiltered read
 * over every company in the shop. There is no method here that produces an
 * unscoped Criteria, so there is nothing to forget.
 *
 * The field PATH is the caller's, because it differs per entity — `customerId`
 * on a quote, `orderCustomer.customerId` on an order,
 * `order.orderCustomer.customerId` on an order line. Each is a literal at its
 * call site. The VALUE is always the bound id.
 *
 * QuoteVersionResolver is reused for the version even on orders: `QuoteVersion::Live`
 * maps to Defaults::LIVE_VERSION, which is not quote-specific, and one
 * collaborator beats a second way of saying the same thing. Orders are versioned
 * too, so the rule is not quote-only.
 */
final readonly class CustomerScope
{
    public function __construct(
        private string $customerId,
        private QuoteVersionResolver $versions,
    ) {}

    /**
     * True when the quote carried no customer. Callers use this to serve
     * NoCustomerHistory; criteria() stays safe either way — see the class
     * docblock.
     */
    public function isEmpty(): bool
    {
        return $this->customerId === '';
    }

    public function context(): Context
    {
        return $this->versions->contextFor(Context::createDefaultContext(), QuoteVersion::Live);
    }

    /** @param string $customerField the path from THIS entity to its customer id */
    public function criteria(string $customerField): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter($customerField, $this->customerId));

        return $criteria;
    }

    /**
     * @param ?string $seen the customer id actually on the loaded row; null when
     *     the association was not loaded, which must not pass as "fine"
     *
     * @throws CrossCustomerRead
     */
    public function verify(?string $seen, string $what): void
    {
        if ($seen !== $this->customerId) {
            throw CrossCustomerRead::of($what);
        }
    }
}
