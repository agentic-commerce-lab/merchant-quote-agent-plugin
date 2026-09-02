<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * Whether this quote has already been asked to clarify, so the agent asks once
 * and then hands an ambiguity that survived the answer to a human.
 *
 * Modelled on QuoteEscalator's marker and for the same reason: without one, a
 * misconfigured shop with a talkative buyer collects one question per buyer
 * comment. It clears the moment a pass actually answers with an offer, so
 * "once" means once per stuck point rather than once per quote lifetime — a
 * genuinely new ambiguity months later still gets asked about instead of
 * escalating in silence.
 *
 * QuoteWriter shallow-merges customFields, so this cannot disturb the A2CN act
 * chain or the two markers the servicing loop already keeps there.
 */
final class ClarificationMarker
{
    public const MARKER_KEY = 'merchant_quote_agent_clarification_asked';

    public static function alreadyAsked(QuoteSnapshot $snapshot): bool
    {
        return ($snapshot->lifecycle->customFields[self::MARKER_KEY] ?? null) === true;
    }

    /** @return array<string, true> the fragment that records the question was asked */
    public static function set(): array
    {
        return [self::MARKER_KEY => true];
    }

    /**
     * The fragment that releases the quote for a fresh question, to be spread
     * into a servicing pass's stamp.
     *
     * Only a pass that ANSWERED may clear it. The pass that asked wrote this
     * marker itself, and erasing it would ask the same question again on the
     * next buyer comment instead of escalating.
     *
     * @return array<string, null> empty when the pass did not answer the buyer
     */
    public static function releaseFor(NegotiationOutcome $outcome): array
    {
        return $outcome->answeredTheBuyer() ? [self::MARKER_KEY => null] : [];
    }
}
