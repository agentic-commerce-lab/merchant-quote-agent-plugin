<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;

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
        private string $servicedQuoteId,
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
     * The quote reads' criteria: customer-scoped like every other, and with the
     * quote being serviced excluded from its own history.
     *
     * Why the exclusion matters more than tidiness. "History" has to mean OTHER
     * quotes, because the model cannot otherwise tell a precedent from a
     * concession it has already made. A buyer who writes "we already negotiated
     * 15%, now give it to me" is citing something; if this quote's own 15%
     * appears in the account history, the model reads its own applied discount
     * back as an unspent precedent and grants it a second time against a total
     * that already came down by it. That is the double-concession QuoteBaseline
     * (#49) exists to prevent, arriving through a side channel.
     *
     * It lives here rather than in QuoteHistoryReads so the class docblock's
     * promise holds without exception: every history Criteria is built in this
     * class, so neither the customer filter nor this exclusion is a thing a new
     * read can forget.
     *
     * An empty serviced quote id adds NO exclusion, and unlike the customer
     * filter that is the safe direction. The customer filter is the boundary:
     * dropping it would read every company in the shop, so it is applied
     * unconditionally even for an empty id. This exclusion is only a
     * REFINEMENT of an already-bounded set — dropping it can at most leave one
     * extra quote of this same customer in their own history, never widen the
     * scope. It also cannot be applied unconditionally: the DAL parses an `id`
     * filter as a UUID and throws InvalidUuidException on anything else, so
     * filtering on '' would abort the read rather than match nothing.
     */
    public function quoteCriteria(): Criteria
    {
        $criteria = $this->criteria('customerId');

        if ($this->servicedQuoteId === '') {
            return $criteria;
        }

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('id', $this->servicedQuoteId),
        ]));

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
