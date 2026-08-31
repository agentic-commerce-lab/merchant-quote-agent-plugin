<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;

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
 *
 * The settings are handed over rather than read again, for the same reason
 * the gateway is: the handler has already resolved them for this quote's
 * sales channel and refused the quote if they were unusable. #18 cannot end
 * up reading a different sales channel's bands.
 *
 * The outcome comes back because the handler cannot infer it. #5 clears the
 * escalation marker on a successful pass — correct while nothing else could
 * escalate, and wrong the moment this pipeline can: clearing a marker the
 * pipeline just wrote makes the quote re-escalate on every later comment.
 */
interface QuoteServicingPipelineInterface
{
    /**
     * The context carries what only the message knows — why the quote was
     * queued and which attempt this is. #19 records both: without the attempt
     * number a redelivered quote reads as a second negotiation.
     */
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome;
}
