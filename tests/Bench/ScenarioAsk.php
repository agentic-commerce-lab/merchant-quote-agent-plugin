<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\BuyerPriceSpace;

/**
 * Renders `{unit*<factor>}` in a scenario's ask as factor x the quote's own
 * unit price, two decimals. The same rule as scripts/eval/scenarios.mjs
 * render(), for the in-process bench: an absolute price only ever made sense
 * against one product (Ruling A14).
 */
final class ScenarioAsk
{
    private const PLACEHOLDER = '/\{unit\*([0-9]+(?:\.[0-9]+)?)\}/';

    private function __construct() {}

    /**
     * Renders against $line's own stored unit price, in the quote's price
     * space. From the stored line total, not unitPriceNet / netRatio:
     * unitPriceNet is cent-rounded per unit, so that rebuild lands a cent off
     * on some multi-quantity lines. No line: the ask goes out as written.
     */
    public static function forLine(string $text, ?QuoteLineSnapshot $line): string
    {
        if ($line === null) {
            return $text;
        }

        $total = $line->totalInQuotePriceSpace ?? BuyerPriceSpace::fromNet($line->totalNet, $line->netRatio);

        return self::render($text, $total / $line->quantity);
    }

    public static function render(string $text, float $unitPrice): string
    {
        return (
            preg_replace_callback(
                self::PLACEHOLDER,
                static fn(array $match): string => number_format(round((float) $match[1] * $unitPrice, 2), 2, '.', ''),
                $text,
            ) ?? $text
        );
    }
}
