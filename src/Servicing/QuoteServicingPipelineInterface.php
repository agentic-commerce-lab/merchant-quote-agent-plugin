<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;

/**
 * The seam issue #18 fills in: snapshot → interpret ask → propose → authorize
 * → apply → verify → reply or escalate. Issue #4 registers no implementation,
 * so the handler's collaborator is null and a claimed quote is a log line.
 *
 * The gateway is a parameter rather than an implementer's constructor
 * dependency on purpose. The handler has already established that it is
 * non-null and licensed, and that it holds the lock; handing the same instance
 * over means #18 cannot end up with a second, differently-resolved gateway —
 * or a null one.
 */
interface QuoteServicingPipelineInterface
{
    public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void;
}
