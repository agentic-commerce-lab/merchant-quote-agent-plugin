<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\BuyerNotificationPreference;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
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
 * the field. Internal detail belongs in the log.
 *
 * Which is why the comment is a fixed constant and escalate() takes no text
 * from its caller, by design. A caller-supplied detail string was the leak:
 * a parameter every caller fills and nothing reads is one "unused" warning
 * away from being printed. There is no channel for it now. $reason stays
 * because it drives the marker, not the copy.
 *
 * This is a permanent property of the seam, not a #5 detail. Issue #18 owns
 * the full "reply or escalate" surface and should route its escalations
 * through here rather than growing a second path — which means #18's copy
 * ("we cannot grant a 40% discount", a currency mismatch, a verification
 * failure) is customer-facing too, with the internal reason kept in the log.
 * If #18 needs the sentence to vary, it varies by $reason, in here.
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
     * The notifier and the buyer-notice preference are optional so existing
     * construction sites and tests keep working; with no preference wired the
     * escalation is silent toward the buyer.
     */
    public function __construct(
        private readonly ?EscalationNotifierInterface $notifier = null,
        private readonly ?BuyerNotificationPreference $buyerNotification = null,
    ) {}

    /**
     * The customFields fragment that releases a quote for a fresh escalation,
     * to be spread into a servicing pass's stamp.
     *
     * A quote the agent once escalated is fair game again: a fixed
     * configuration must be able to escalate afresh if it breaks afresh, and
     * the marker is what would otherwise silence it. But only a pass that
     * actually ANSWERED may clear it — a pass that escalated wrote this marker
     * itself, and erasing it would re-escalate the quote on every following
     * buyer comment, which is the comment spam the marker exists to prevent.
     *
     * @return array<string, null> empty when the pass did not answer the buyer
     */
    public static function releaseFor(NegotiationOutcome $outcome): array
    {
        return $outcome->answeredTheBuyer() ? [self::MARKER_KEY => null] : [];
    }

    public function escalate(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteEscalationReason $reason,
        ?bool $notifyBuyer = null,
    ): void {
        $quoteId = $snapshot->identity->quoteId;

        if (($snapshot->lifecycle->customFields[self::MARKER_KEY] ?? null) === $reason->value) {
            return;
        }

        $shouldNotify = $notifyBuyer ?? $this->shouldNotifyBuyer($snapshot->identity->salesChannelId);

        if ($shouldNotify) {
            $gateway->addComment($quoteId, self::BUYER_MESSAGE);
        }

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [self::MARKER_KEY => $reason->value]));

        // After the buyer is told (if enabled) and the marker is stamped, never before: the
        // marker's early return above is what makes this once per quote per
        // reason, and a notifier that throws must not cost the buyer their
        // comment. Guarded even though the contract forbids throwing — an
        // implementation that forgets must not break escalation.
        //
        // The comment/marker order above is deliberate too, and #140 asked it
        // to be reversed. It stays. If updateQuote throws after addComment
        // succeeded, the next pass comments again — the buyer hears it twice.
        // Reversed, an addComment that throws after the marker was stamped is
        // suppressed by that marker on every later pass, and the buyer is
        // never told at all, silently, forever. For a notice whose whole
        // purpose is that the buyer is not left in silence, failing toward
        // "said twice" is the right way round.
        try {
            $this->notifier?->notify(EscalationNotice::of($snapshot, $reason));
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty: the notifier owns its own logging, and there
            // is nothing useful left to say from here that it has not said.
        }
    }

    private function shouldNotifyBuyer(?string $salesChannelId): bool
    {
        return $this->buyerNotification?->notifyBuyerOnEscalation($salesChannelId) ?? false;
    }
}
