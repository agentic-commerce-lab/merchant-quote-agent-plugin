<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot as BridgeLine;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot as BridgeSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle as PolicyLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity as PolicyLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;

/**
 * Between the bridge's full-fidelity read model and the policy layer's trimmed
 * one. Not a cast: both namespaces define their own QuoteLineSnapshot and
 * QuoteLineIdentity, deliberately, so that negotiation-core stays free of
 * anything it does not read.
 *
 * `requestedUnitPrice` is the one ask that arrives structured — a per-line
 * target entered in the storefront — so it reaches the deciders even when the
 * buyer left no comment at all.
 */
final class SnapshotAdapter
{
    private function __construct() {}

    public static function toPolicy(BridgeSnapshot $snapshot): PolicySnapshot
    {
        return new PolicySnapshot(
            currencyIso: $snapshot->identity->currencyIso,
            totalNet: $snapshot->totals->totalNet,
            lines: array_map(self::line(...), $snapshot->content->lines),
            lifecycle: new PolicyLifecycle(
                stateTechnicalName: $snapshot->lifecycle->stateTechnicalName,
                expirationDate: $snapshot->lifecycle->expiresAt?->format('Y-m-d'),
            ),
        );
    }

    public static function conversation(BridgeSnapshot $snapshot): BuyerConversation
    {
        $buyer = [];
        $agent = [];

        foreach ($snapshot->content->comments as $comment) {
            if ($comment->isAuthored()) {
                $buyer[] = $comment;

                continue;
            }

            $agent[] = $comment;
        }

        return new BuyerConversation($buyer, $agent);
    }

    private static function line(BridgeLine $line): PolicyLine
    {
        return new PolicyLine(
            identity: new PolicyLineIdentity(
                lineItemId: $line->identity->lineItemId,
                label: $line->identity->label,
                productId: $line->identity->productId,
            ),
            quantity: $line->quantity,
            unitPriceNet: $line->unitPriceNet,
            totalNet: $line->totalNet,
            requestedUnitPrice: $line->requestedUnitPrice,
        );
    }
}
