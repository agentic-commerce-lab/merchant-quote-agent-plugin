<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;

interface QuoteServicingPipelineInterface
{
    public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void;
}
