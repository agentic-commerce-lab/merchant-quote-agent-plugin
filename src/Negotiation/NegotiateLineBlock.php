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

    /** @param list<QuoteLineSnapshot> $lines */
    public static function of(array $lines): string
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
                // The storefront's per-line "Requested price", in net. Without it
                // a structured-only ask reached the model as no ask at all
                // (sw-ag.dev quotes 1097/1099) and was answered with 0%.
                $l->requestedUnitPrice === null ? '' : sprintf('%.2f', $l->requestedUnitPrice),
            ),
            $lines,
        ));
    }
}
