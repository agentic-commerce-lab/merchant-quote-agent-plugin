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

    /**
     * toPolicy(), anchored on the stored baseline when there is one — see
     * QuoteBaselineLines::anchor(). Before the first offer there is none, and
     * the live prices ARE the originals.
     */
    public static function anchored(BridgeSnapshot $snapshot): PolicySnapshot
    {
        $live = self::toPolicy($snapshot);

        return QuoteBaseline::read($snapshot)?->anchor($live) ?? $live;
    }

    /**
     * The comments split by who wrote them — and a merchant's own note belongs
     * to neither the buyer's side nor the agent's.
     *
     * It is not an ask: answering it writes a reply to an internal note in the
     * one thread the customer reads (#55). And it is not something the agent
     * said, so it must not appear as the agent's prior words in the negotiate
     * prompt either — a merchant's aside is not a promise the agent made, and
     * the model must not repeat it back to the buyer.
     *
     * An authored, non-buyer comment is the administration's own, and it is
     * now kept — in its own bucket — rather than dropped: still out of both
     * prompts, but datable, which is what lets a later stage tell that a human
     * already took the quote over. The merchant's channel for steering a pass
     * stays the strategy library and the policy settings, not a sentence the
     * buyer can also read.
     */
    public static function conversation(BridgeSnapshot $snapshot): BuyerConversation
    {
        $buyer = [];
        $agent = [];
        $merchant = [];

        foreach ($snapshot->content->comments as $comment) {
            if ($comment->isBuyerAuthored()) {
                $buyer[] = $comment;

                continue;
            }

            if ($comment->isAuthored()) {
                $merchant[] = $comment;

                continue;
            }

            $agent[] = $comment;
        }

        return new BuyerConversation($buyer, $agent, $merchant);
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
