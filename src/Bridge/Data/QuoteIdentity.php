<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteIdentity
{
    /**
     * `customerId` is the COMPANY on a B2B shop, not a person: SwagCommercial's
     * b2b_employee has no customer row of its own (only
     * business_partner_customer_id), and b2b_components_organization units hang
     * off the same customer. So this one id scopes the whole company's history
     * across every employee and every organization unit.
     *
     * Defaulted to '' because it is read off the entity and a shop with a
     * broken row must degrade to "no history", never to an unfiltered read.
     */
    public function __construct(
        public string $quoteId,
        public string $quoteNumber,
        public string $currencyIso,
        public string $salesChannelId = '',
        public string $customerId = '',
    ) {}
}
