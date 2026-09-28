<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Audit\ReviewStatus;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;

/**
 * Whether an AI agent acted on this quote, so the storefront can tell the buyer.
 *
 * Unlike the two markers the servicing loop already keeps on customFields,
 * this one is set once and NEVER cleared. Those two exist to be released — a
 * quote may escalate afresh, and a genuinely new ambiguity may be asked about
 * again. This one records something that happened, and a later human reply
 * does not make it untrue. That opposite lifecycle is why it is its own key
 * rather than a reuse of QuoteEscalator's.
 *
 * Gated on the outcome, and the gate is neither "a pass completed" nor
 * answeredTheBuyer().
 *
 * Not "a pass completed", because HandedOver is a pass that found a human
 * merchant already on the quote and wrote nothing. Disclosing there would tell
 * the buyer an AI handled a quote a person handled, and a false statement to
 * the buyer is worse than a missing one. NothingToDo is the same shape with
 * nothing happening at all.
 *
 * Not answeredTheBuyer() either, which covers only Offered and Countered.
 * Clarified put an agent-written question in front of the buyer.
 * Acknowledged restated the quote to the buyer in an agent-written comment.
 * Escalated is included even on a sales channel where the buyer notice is
 * switched off and the buyer sees no agent message: the agent still made a
 * determination about their quote, and that determination is what is being
 * disclosed.
 *
 * So: did the agent ACT on this quote.
 *
 * QuoteWriter shallow-merges customFields, so this cannot disturb the A2CN act
 * chain or the markers already there.
 */
final class AgentDisclosure
{
    /**
     * Read by Resources/views/storefront/page/account/quote-detail/index.html.twig
     * as a string literal — Twig cannot import the constant. AgentDisclosureTest
     * pins the value so the two cannot drift apart silently.
     */
    public const MARKER_KEY = 'merchant_quote_agent_handled';

    /**
     * The fragment that discloses agent handling, to be spread into a servicing
     * pass's stamp.
     *
     * stampForPass() withholds it for a Draft Mode pass that drafted a reply.
     *
     * @return array<string, true> empty when the agent did not act on the quote
     */
    public static function stampFor(NegotiationOutcome $outcome): array
    {
        return match ($outcome) {
            NegotiationOutcome::Offered,
            NegotiationOutcome::Countered,
            NegotiationOutcome::Clarified,
            NegotiationOutcome::Escalated,
            NegotiationOutcome::Acknowledged,
                => [self::MARKER_KEY => true],
            NegotiationOutcome::HandedOver, NegotiationOutcome::NothingToDo => [],
        };
    }

    /**
     * stampFor(), except for a Draft Mode pass that drafted a reply: the
     * merchant reviews and sends what the buyer reads, so it was not the agent
     * that told the buyer anything. An escalation drafts nothing and still
     * discloses, because the agent's determination stands either way.
     *
     * @return array<string, true>
     */
    public static function stampForPass(NegotiationOutcome $outcome, QuoteAgentSettings $settings): array
    {
        if ($settings->draftMode && ReviewStatus::awaitsReview($outcome)) {
            return [];
        }

        return self::stampFor($outcome);
    }
}
