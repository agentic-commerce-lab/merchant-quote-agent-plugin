<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * Assembles the array `NegotiationPolicy::fromArray()` expects out of the
 * flat raw config values. Split out of QuoteAgentSettingsFactory so its own
 * per-field null-handling doesn't add to that class's complexity budget.
 *
 * Only `price` is left to assemble. Payment and delivery went when it became
 * clear AskGate escalates every non-price ask; the published volume tiers went
 * the same way, one step later, once they were a mandate claim with no
 * behaviour behind them.
 */
final class NegotiationPolicyArray
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     *
     * @throws \TypeError see RawValueGuard
     */
    public static function build(array $raw): array
    {
        $price = [
            // Null means the merchant cleared the field. Zero is the safe
            // reading: every price ask escalates.
            'maxDiscountPercent' => RawConfigValue::float($raw, 'maxDiscountPercent') ?? 0.0,
            'counterOfferMaxPercent' => RawConfigValue::float($raw, 'counterOfferMaxPercent'),
            // Also null when cleared, but there is no safe reading here: an
            // offer valid for zero days is one sent already expired (#57), so
            // this zero is the sentinel QuoteLimits' Positive constraint
            // rejects rather than a conservative default.
            'validityDays' => RawConfigValue::int($raw, 'validityDays') ?? 0,
        ];

        // Passed straight through. The admin field is a plain number; an
        // ISO-keyed map set via `system:config:set --json` also survives,
        // and QuoteLimits refuses a wrong-typed entry per currency so the
        // message names the one at fault.
        $ceiling = RawValue::at($raw, 'maxQuoteValueNet');
        if (\is_array($ceiling)) {
            $price['maxQuoteValueNet'] = $ceiling;
        } elseif (($net = RawConfigValue::float($raw, 'maxQuoteValueNet')) !== null) {
            $price['maxQuoteValueNet'] = $net;
        }

        return ['price' => $price];
    }
}
