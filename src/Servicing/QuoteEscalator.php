<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;

/**
 * Tells the BUYER their quote is going to a human, by writing into the quote
 * conversation they read in their storefront account. The merchant's half of
 * an escalation is not here at all — it is the log line the caller writes.
 *
 * That is not a guess about the audience. SwagCommercial loads a quote's
 * comments with no authorship filter and no visibility flag, renders them in
 * the account quote detail page with a reply composer, and exposes a
 * customer-scope store-api route whose whole job is marking them seen. This is
 * the buyer↔merchant conversation, and there is no private half of it.
 *
 * SO EVERYTHING WRITTEN HERE IS CUSTOMER-FACING COPY. No reason value, no
 * problem list, no field names, no mention of an agent — and no constraint or
 * mapping message, several of which echo the offending value rather than just
 * the field. Internal detail belongs in the log, and callers must pass copy
 * that is safe to publish, not diagnostics.
 *
 * This is a permanent property of the seam, not a #5 detail. Issue #18 owns
 * the full "reply or escalate" surface and should route its escalations
 * through here rather than growing a second path — which means #18's copy
 * ("we cannot grant a 40% discount", a currency mismatch, a verification
 * failure) is customer-facing too, with the internal reason kept in the log.
 *
 * The comment goes through the gateway, so it carries AgentContext::STATE and
 * cannot re-trigger servicing.
 *
 * Once per quote per reason. Without the marker a misconfigured shop with a
 * talkative buyer collects one comment per buyer comment, which is loud in
 * the wrong sense. The marker joins the two the servicing loop already keeps
 * on customFields, and QuoteWriter shallow-merges, so it cannot disturb the
 * A2CN act chain.
 */
final class QuoteEscalator
{
    public const MARKER_KEY = 'merchant_quote_agent_escalated';

    private const BUYER_MESSAGE = 'A member of our team will review this quote personally and get back to you.';

    /**
     * @param string $detail buyer-safe context, reserved for #18 to vary the
     *                       copy by reason. Nothing writes it today, and it is
     *                       never the place for a diagnostic — see the class
     *                       docblock.
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
