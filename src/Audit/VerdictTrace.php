<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;

/**
 * The policy's verdict as a trace event. `recordDecision()` has always kept
 * only the band; this keeps what the band was made of.
 *
 * Flattened into meta, figure by figure, rather than meta being the encoded
 * decision: `QuoteEscalationDetails::$humanReviewRequests` are sentences the
 * extract model wrote, and `lineUnitPricesNet` carries line-item ids, so the
 * whole tree is content and only the figures and enums are meta.
 * `escalationReasons` qualifies because PriceEscalationReasons builds each one
 * from an enum value.
 */
final class VerdictTrace
{
    private function __construct() {}

    /** @return array{0: array<string, mixed>, 1: array<array-key, mixed>} */
    public static function of(NegotiationDecision $decision, ?NegotiationPolicy $policy = null): array
    {
        $price = $decision->price;

        return [
            [
                'overall' => $decision->overall->value,
                'priceKind' => $price->kind->value,
                'escalationReason' => $price->escalation?->reason->value,
                'requestedDiscountPercent' => $price->escalation?->requestedDiscountPercent,
                'discountPercent' => $price->autoReply?->discountPercent,
                'perLineAsks' => $price->autoReply?->perLineAsks,
                'validityDays' => $price->autoReply?->validityDays,
                'counteredRequestPercent' => $price->autoReply?->counteredRequestPercent,
                'escalationReasons' => $decision->escalationReasons,
                'policyHash' => $policy === null ? null : self::policyHash($policy),
            ],
            TracePayload::of($decision),
        ];
    }

    /**
     * Which merchant limits bound the verdict, without the limits themselves:
     * two rows with the same hash were decided under the same settings. Over
     * the validated NegotiationPolicy the decider read, never the model
     * access beside it, which carries the API key.
     *
     * ponytail: json_encode of the public readonly tree; a reordered currency
     * map changes the hash. Canonicalise (ProtocolHash) if that ever matters.
     */
    private static function policyHash(NegotiationPolicy $policy): string
    {
        return substr(hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)), offset: 0, length: 16);
    }
}
