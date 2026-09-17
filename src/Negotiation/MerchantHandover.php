<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;

/**
 * Has a human merchant already handled this quote?
 *
 * True when the merchant acted more recently than the buyer's newest input.
 * It is not a permanent handover: the buyer's next ask is newer than the
 * merchant's action by definition, and re-enables the agent by itself.
 *
 * A merchant acting by hand does not TRIGGER a pass — QuoteServicingTrigger
 * filters their comments and their transitions both. This exists for the pass
 * already in flight: the buyer asks, the message queues, and the merchant
 * answers by hand in the seconds before a worker picks it up, or while the
 * message is being redelivered after lock contention. Without this the agent
 * answers on top of them, and because OfferApplier writes line prices it can
 * also overwrite the prices the merchant just set.
 *
 * Static, in the idiom of StructuredAsk and MirroredAsks: it is a predicate
 * over a snapshot, and NegotiationPipeline is already at the five-parameter
 * constructor cap.
 */
final class MerchantHandover
{
    private function __construct() {}

    public static function tookOver(QuoteSnapshot $snapshot, BuyerConversation $conversation): bool
    {
        $merchant = self::latest(
            $conversation->merchantSpokeAt(),
            self::at($snapshot->lifecycle->lastAdminTransitionAt),
        );

        if ($merchant === null) {
            return false;
        }

        $buyer = self::latest($conversation->buyerSpokeAt(), self::freshAskAt($snapshot));

        // No datable buyer input at all, and a merchant who acted: theirs.
        return $buyer === null || $merchant > $buyer;
    }

    /**
     * When the buyer last moved a per-line target, which is the one ask that
     * arrives without a comment.
     *
     * Only the line whose requested price differs from the asks the last pass
     * stamped. `updatedAt` moves on ANY write to the line, our own price
     * concessions included, so the newest line timestamp on its own would read
     * our last pass as a fresh buyer ask on every quote we have ever serviced.
     *
     * Known ceiling, accepted: a merchant who hand-edits the buyer's own
     * `requested_price` column moves that line's `updatedAt` themselves and
     * would be read here as the buyer. The column is the buyer's own, the
     * administration gives the merchant no reason to touch it, and the one
     * writer that does — ours — is already hidden by MirroredAsks.
     */
    private static function freshAskAt(QuoteSnapshot $snapshot): ?string
    {
        $stamped = ServicingFingerprint::stampedAsks($snapshot->lifecycle->customFields);
        $stampedTokens = $stamped === '' ? [] : array_flip(explode(',', $stamped));

        $newest = null;

        foreach ($snapshot->content->lines as $line) {
            $token = ServicingFingerprint::askToken($line);

            if ($token === null || isset($stampedTokens[$token])) {
                continue;
            }

            $newest = self::latest($newest, self::at($line->updatedAt));
        }

        return $newest;
    }

    private static function at(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format('U.u');
    }

    /**
     * Compared as fixed-width 'U.u' strings rather than floats, for
     * ServicingFingerprint::newestCreatedAt()'s reason: the columns are
     * datetime(3) and a float comparison at microsecond scale is exactly the
     * rounding this must not have.
     */
    private static function latest(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return $left > $right ? $left : $right;
    }
}
