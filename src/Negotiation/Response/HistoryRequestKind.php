<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/**
 * Which history read the model is asking for.
 *
 * A backed enum because that is what puts these three strings into the generated
 * JSON schema as the ONLY permitted values — the model cannot invent a fourth,
 * and there is no free-text field here for an injected instruction to ride in on.
 *
 * Note what is absent and must stay absent: anything naming a customer. The
 * company is bound server-side from the quote being serviced.
 */
enum HistoryRequestKind: string
{
    /** The company's 25 newest live quotes, with what we granted on each. */
    case QuoteHistory = 'quote_history';

    /** The company's lifetime order figures plus its 10 newest orders, with line detail. */
    case Orders = 'orders';

    /** What this company paid for one SKU. Needs `productId`. */
    case ProductPurchases = 'product_purchases';
}
