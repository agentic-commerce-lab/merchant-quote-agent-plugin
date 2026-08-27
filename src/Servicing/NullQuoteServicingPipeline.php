<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;

final readonly class NullQuoteServicingPipeline implements QuoteServicingPipelineInterface
{
    #[\Override]
    public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
    {
        // Issue #4 baseline: triggering, queueing, locking, and error paths are verified.
        // Full LLM negotiation strategy is implemented in Issue #18.
    }
}
