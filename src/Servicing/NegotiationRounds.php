<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * How many agent passes one quote has already bought, and the point at which a
 * human takes it over (#142).
 *
 * The discount a negotiation can concede is bounded — QuoteBaselineLines::anchor()
 * measures every round against the baseline the first pass stamped — but until
 * this existed nothing bounded what a negotiation could COST. Each new buyer
 * comment moves ServicingFingerprint, which is the only gate between a trigger
 * and the pipeline, so a buyer who keeps commenting keeps buying model calls.
 *
 * Counted as PASSES, not as rounds the agent answered. Everything past the
 * fingerprint gate buys at least the extract call, and a quote that escalates on
 * every pass answers nobody while paying for every attempt — a cap on answers
 * would never fire on exactly the quote that is spending.
 *
 * The key sits alongside merchant_quote_agent_serviced / _escalated / _attempts
 * / _baseline and is shallow-merged by the gateway, so it cannot collide with
 * them or with the A2CN act chain.
 *
 * DELIBERATELY NOT CLEARED by a successful pass, unlike
 * ServiceQuoteHandler::ATTEMPTS_KEY. That counter is a crash budget and
 * clearing it on a normal exit is what makes it one; clearing this one would
 * remove the bound entirely. The escape hatch is manual and rare by design:
 * clear `merchant_quote_agent_rounds` on the quote to hand it back to the agent.
 */
final class NegotiationRounds
{
    public const KEY = 'merchant_quote_agent_rounds';

    /**
     * Fifteen passes, which bounds one quote at roughly 45 model calls.
     *
     * Not configurable, and that is an argued position rather than an omission:
     * every optional number in config.xml reads blank as "no limit", a safety
     * control cannot, and #57 established that a new config.xml field reaches no
     * shop that already has the plugin without a migration. See the design doc
     * for the shape the field should take if a merchant ever earns it.
     */
    public const MAX = 15;

    private function __construct() {}

    public static function completed(QuoteSnapshot $snapshot): int
    {
        $stored = $snapshot->lifecycle->customFields[self::KEY] ?? null;

        // customFields is a JSON column. Anything that is not an int is not a
        // count, and the same coercion guards the crash budget next door.
        return \is_int($stored) ? $stored : 0;
    }

    public static function exhausted(QuoteSnapshot $snapshot): bool
    {
        return self::completed($snapshot) >= self::MAX;
    }

    /**
     * The customFields fragment for QuoteUpdate, spread-friendly so the caller
     * needs no conditional.
     *
     * @return array<string, int>
     */
    public static function increment(QuoteSnapshot $snapshot): array
    {
        return [self::KEY => self::completed($snapshot) + 1];
    }
}
