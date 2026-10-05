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
 * a free pass, StructuredAsk::isOpen() would report a countered ask as
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
     * An earlier legacy entry is written back as the bare number it was, not
     * given a stored value nobody recorded (MirroredAsk::marker()).
     *
     * @param array<array-key, mixed> $customFields
     * @param array<string, MirroredAsk> $asks
     *
     * @return array<string, array<string, float|array{net: float, stored: float}>>
     */
    public static function stamp(array $customFields, array $asks): array
    {
        return [self::KEY => array_map(static fn(MirroredAsk $ask): float|array => $ask->marker(), [
                ...self::read($customFields),
                ...$asks,
            ])];
    }

    /**
     * A marker that does not parse reads as nothing mirrored, per entry rather
     * than wholesale: the failure that matters is HIDING a real buyer ask, so
     * every doubt resolves towards showing it.
     *
     * @param array<array-key, mixed> $customFields
     *
     * @return array<string, MirroredAsk>
     */
    public static function read(array $customFields): array
    {
        $raw = $customFields[self::KEY] ?? null;

        if (!\is_array($raw)) {
            return [];
        }

        $asks = [];

        foreach ($raw as $lineItemId => $entry) {
            $ask = MirroredAsk::parse($entry);

            if (\is_string($lineItemId) && $ask !== null) {
                $asks[$lineItemId] = $ask;
            }
        }

        return $asks;
    }

    /**
     * Whether the stored requested price is exactly what the agent wrote in
     * the quote's tax space. A net-space tolerance can hide a buyer's later
     * one-cent edit and falsely mark an unserviced ask as already answered.
     *
     * @param array<string, MirroredAsk> $mirrored as returned by read()
     * @param float $netRatio the line's current ratio; read only for a legacy entry (MirroredAsk::holds())
     */
    public static function holds(
        array $mirrored,
        string $lineItemId,
        ?float $storedRequestedPrice,
        float $netRatio,
    ): bool {
        return ($mirrored[$lineItemId] ?? null)?->holds($storedRequestedPrice, $netRatio) ?? false;
    }
}
