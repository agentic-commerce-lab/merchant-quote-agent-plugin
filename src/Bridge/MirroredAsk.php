<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/**
 * One line's entry in the MirroredAsks marker: the net ask the agent mirrored,
 * and the number that write stored in `requested_price`.
 *
 * The stored number is recorded at the write because it cannot be worked out
 * again later (QA-08). It is the net ask divided by the line's net ratio and
 * rounded to the cent, and the ratio moves when the agent reprices the line:
 * 289.81 net was stored as 318.79 at 305.06/335.57, but reads back as 318.80
 * at the repriced 293.05/322.36. That one cent unhid the mirror, the agent
 * read its own write as a fresh buyer ask, and a pass with nothing to answer
 * cut the price without a word.
 *
 * Its own class because parsing two entry shapes would take MirroredAsks
 * over the class complexity limit.
 */
final readonly class MirroredAsk
{
    private function __construct(
        public float $net,
        /** Null on an entry written before QA-08, which recorded the net ask alone. */
        public ?float $stored,
    ) {}

    /** What QuoteLineTaxRules writes for a net ask: the quote's own tax space, to the cent. */
    public static function storedValue(float $net, float $netRatio): float
    {
        return round($net / $netRatio, precision: 2);
    }

    /** @param float $netRatio the line's ratio as the write converts the ask through it */
    public static function written(float $net, float $netRatio): self
    {
        return new self($net, self::storedValue($net, $netRatio));
    }

    /** Null when the entry does not parse: see MirroredAsks::read() for why that reads as nothing mirrored. */
    public static function parse(mixed $entry): ?self
    {
        // A bare number is the legacy entry: the net ask, nothing else.
        if (is_numeric($entry)) {
            return new self((float) $entry, null);
        }

        if (!\is_array($entry) || !is_numeric($entry['net'] ?? null) || !is_numeric($entry['stored'] ?? null)) {
            return null;
        }

        return new self((float) $entry['net'], (float) $entry['stored']);
    }

    /**
     * Whether `$storedRequestedPrice` is what this mirror wrote.
     *
     * A recorded value is compared exactly, and `$netRatio` plays no part: a
     * buyer's one-cent edit must not hide behind it. Only a legacy entry still
     * converts through the line's current ratio, which a reprice moves by up
     * to a cent, so it holds within one cent. A legacy entry is never written
     * again, so the slack hides no future edit, and without it a "thanks" on
     * a pre-QA-08 quote negotiates again. Those retire as their quotes close.
     */
    public function holds(?float $storedRequestedPrice, float $netRatio): bool
    {
        if ($storedRequestedPrice === null) {
            return false;
        }

        if ($this->stored !== null) {
            return number_format($storedRequestedPrice, 2, '.', '') === number_format($this->stored, 2, '.', '');
        }

        // ponytail: no zero-ratio guard. QuoteLineNet reads a zero total as
        // 1.0, so no line carries a 0.0 ratio, and the write divides by the
        // same ratio unguarded.
        $cents = round(
            abs(round($storedRequestedPrice, precision: 2) - self::storedValue($this->net, $netRatio)) * 100,
        );

        return $cents <= 1.0;
    }

    /**
     * The entry as the marker stores it. A legacy entry stays the bare net
     * number it was written as.
     *
     * @return float|array{net: float, stored: float}
     */
    public function marker(): float|array
    {
        return $this->stored === null ? $this->net : ['net' => $this->net, 'stored' => $this->stored];
    }
}
