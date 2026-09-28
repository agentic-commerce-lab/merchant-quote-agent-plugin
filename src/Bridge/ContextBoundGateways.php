<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;

/**
 * A gateway whose reads and writes run under a given context — a Draft Mode
 * version, or the admin user sending a draft. The seam exists so the review
 * services can be tested with a fake gateway; QuoteGatewayFactory is final.
 */
interface ContextBoundGateways
{
    /** Null when the SwagCommercial license toggle is off. */
    public function forContext(Context $context): ?QuoteGatewayInterface;
}
