<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\History\CustomerSummary;
use MerchantQuoteAgentPlugin\Bridge\Data\History\OrderHistory;
use MerchantQuoteAgentPlugin\Bridge\Data\History\ProductPurchase;
use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteHistoryEntry;

/**
 * The company's history, behind a port so the negotiation engine stays
 * Shopware-free and the loop is testable without a kernel.
 *
 * NO METHOD TAKES A CUSTOMER ID, AND NONE EVER MAY. The id is bound to the
 * implementation at construction, from the quote the pass is servicing
 * (CustomerHistoryFactory). The input to a negotiation pass is buyer-authored
 * free text; a customer parameter here would be a cross-company read one prompt
 * injection away. `productId` is the only model-supplied value that reaches an
 * implementation, and HistoryRequestResolver allow-lists it against this
 * quote's own lines before it gets here.
 *
 * "Customer" means the COMPANY: a SwagCommercial b2b_employee has no customer
 * row of its own, and organization units hang off the same customer, so one id
 * covers every employee and every unit. Note that an employee_account can span
 * several companies (it carries default_employee_id), which is exactly why the
 * scope is the QUOTE's customer and never the acting account.
 */
interface CustomerHistoryInterface
{
    public function summary(): CustomerSummary;

    /** @return list<QuoteHistoryEntry> newest first */
    public function quotes(): array;

    public function orders(): OrderHistory;

    /** @return list<ProductPurchase> newest first */
    public function productPurchases(string $productId): array;
}
