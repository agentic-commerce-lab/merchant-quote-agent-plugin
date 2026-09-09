<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/**
 * The quote custom field recording which per-line requested prices the AGENT
 * wrote, so the read model can hide them from the agent itself.
 *
 * A buyer's chat ask is mirrored onto `quote_line_item.requested_price` so the
 * quote shows what was asked for — but three guards read that field as
 * buyer-only, and all three break if the agent's own number reaches them:
 * ServicingFingerprint's ask component would differ from its own stamp and buy
 * a free pass, StructuredAsk::isUnmet() would report a countered ask as
 * unanswered, and AskGate would treat it as the answer to an ambiguous
 * comment. Subtracting the mirror at the single read boundary
 * (QuoteLineMapper) leaves every one of them seeing exactly what it saw
 * before.
 *
 * The key sits alongside merchant_quote_agent_serviced / _baseline /
 * _escalated / _attempts and is shallow-merged by the gateway, so it cannot
 * collide with them or with the A2CN act chain.
 */
final class MirroredAsks
{
    public const KEY = 'merchant_quote_agent_mirrored_asks';

    /**
     * Slack for one net→gross→net round trip, not a money tolerance.
     *
     * The marker holds the NET ask; the column holds the quote's own tax space.
     * Writing rounds to the cent there and reading rounds to the cent back, so
     * a mirrored 15.00 can return as 14.995. Deliberately not Policy's
     * `Epsilon::MONEY`: that one is offer-verification slack, a policy concept,
     * and the bridge does not import the policy layer.
     */
    private const ROUND_TRIP_SLACK = 0.01;

    private function __construct() {}

    /**
     * The custom-field fragment for QuoteUpdate: this round's mirror MERGED
     * over whatever earlier rounds wrote, newest per line winning.
     *
     * Merged rather than replaced because the gateway shallow-merges custom
     * fields one key deep, so writing this key at all replaces the whole map —
     * and a round that mirrors line 2 would drop line 1's entry, unhiding a
     * value the agent itself wrote as though the buyer had just asked for it.
     * Takes the whole custom fields so a caller cannot forget the merge.
     *
     * @param array<array-key, mixed> $customFields
     * @param array<string, float> $netByLineItemId
     *
     * @return array<string, array<string, float>>
     */
    public static function stamp(array $customFields, array $netByLineItemId): array
    {
        return [self::KEY => [...self::read($customFields), ...$netByLineItemId]];
    }

    /**
     * A marker that does not parse reads as nothing mirrored, per entry rather
     * than wholesale: the failure that matters is HIDING a real buyer ask, so
     * every doubt resolves towards showing it.
     *
     * @param array<array-key, mixed> $customFields
     *
     * @return array<string, float>
     */
    public static function read(array $customFields): array
    {
        $raw = $customFields[self::KEY] ?? null;

        if (!\is_array($raw)) {
            return [];
        }

        $asks = [];

        foreach ($raw as $lineItemId => $net) {
            if (!\is_string($lineItemId) || !is_numeric($net)) {
                continue;
            }

            $asks[$lineItemId] = (float) $net;
        }

        return $asks;
    }

    /**
     * Whether this line's requested price is the one the agent mirrored — and
     * not a number the buyer has since entered over it.
     *
     * @param array<string, float> $mirrored as returned by read()
     */
    public static function holds(array $mirrored, string $lineItemId, ?float $requestedUnitPriceNet): bool
    {
        $net = $mirrored[$lineItemId] ?? null;

        return (
            $net !== null
            && $requestedUnitPriceNet !== null
            && abs($requestedUnitPriceNet - $net) <= self::ROUND_TRIP_SLACK
        );
    }
}
