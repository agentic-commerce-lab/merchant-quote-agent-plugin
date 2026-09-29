<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * The negotiate prompt's line table, header and rows together so the column
 * list and the columns cannot drift apart. Everything is net.
 *
 * Its own class only because OfferProposer sits at the cyclomatic-complexity
 * threshold and the requested-price column adds a branch.
 */
final class NegotiateLineBlock
{
    private function __construct() {}

    /**
     * @param list<QuoteLineSnapshot> $lines
     * @param array<string, float> $lineAsksNet a comment's adopted per-line targets, net (NegotiationContext)
     */
    public static function of(array $lines, array $lineAsksNet = []): string
    {
        return "Line items (lineItemId | productId | label | quantity | unit price net | buyer asks per unit net):\n"
        . implode("\n", array_map(
            static fn(QuoteLineSnapshot $l): string => sprintf(
                '%s | %s | %s | %d | %.2f | %s',
                $l->lineItemId(),
                $l->identity->productId ?? '',
                $l->label() ?? '',
                $l->quantity,
                $l->unitPriceNet,
                // The storefront's per-line "Requested price", else the target
                // the buyer typed in their comment, both net. Without the first
                // a structured-only ask reached the model as no ask at all
                // (sw-ag.dev quotes 1097/1099); without the second a comment's
                // gross per-unit figure did (quote 1202, #222).
                self::ask($l->requestedUnitPrice ?? $lineAsksNet[$l->lineItemId()] ?? null),
            ),
            $lines,
        ));
    }

    private static function ask(?float $netPrice): string
    {
        return $netPrice === null ? '' : sprintf('%.2f', $netPrice);
    }
}
