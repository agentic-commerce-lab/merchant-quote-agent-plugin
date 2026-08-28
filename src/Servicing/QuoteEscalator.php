<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;

/**
 * Puts an escalation where the merchant is already looking: a comment on the
 * quote. The comment goes through the gateway, so it carries
 * AgentContext::STATE and cannot re-trigger servicing.
 *
 * Once per quote per reason. Without the marker a misconfigured shop with a
 * talkative buyer collects one comment per buyer comment, which is loud in
 * the wrong sense. The marker joins the two the servicing loop already keeps
 * on customFields, and QuoteWriter shallow-merges, so it cannot disturb the
 * A2CN act chain.
 *
 * Issue #18 owns the full "reply or escalate" surface and should route its
 * escalations through here rather than growing a second path.
 */
final class QuoteEscalator
{
    public const MARKER_KEY = 'merchant_quote_agent_escalated';

    public function escalate(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteEscalationReason $reason,
        string $detail,
    ): void {
        $quoteId = $snapshot->identity->quoteId;

        if (($snapshot->lifecycle->customFields[self::MARKER_KEY] ?? null) === $reason->value) {
            return;
        }

        $gateway->addComment($quoteId, sprintf(
            'This quote needs a human: the automated agent could not handle it (%s). %s',
            $reason->value,
            $detail,
        ));

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [self::MARKER_KEY => $reason->value]));
    }
}
