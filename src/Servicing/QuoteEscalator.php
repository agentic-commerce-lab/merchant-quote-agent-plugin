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
 * THE COMMENT TEXT IS BUYER-VISIBLE. SwagCommercial's
 * AccountQuoteDetailPageLoader loads a quote's comments with no authorship
 * filter and no visibility flag, and the storefront renders them as a customer
 * conversation with a reply box — so whatever is written here is shown to the
 * customer. Keep the sentence neutral: no reason value, no problem list, no
 * mention of an agent. The merchant still gets the diagnostic, because
 * ServicingPreflight logs the problems at error level.
 *
 * Issue #18 owns the full "reply or escalate" surface and should route its
 * escalations through here rather than growing a second path.
 */
final class QuoteEscalator
{
    public const MARKER_KEY = 'merchant_quote_agent_escalated';

    private const BUYER_MESSAGE = 'A member of our team will review this quote personally and get back to you.';

    /**
     * @param string $detail the merchant-facing diagnostic. Deliberately NOT
     *                       written to the comment — see the class docblock;
     *                       ServicingPreflight logs it instead.
     */
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

        $gateway->addComment($quoteId, self::BUYER_MESSAGE);

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [self::MARKER_KEY => $reason->value]));
    }
}
