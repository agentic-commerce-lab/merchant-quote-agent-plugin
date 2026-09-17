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
        // A tie goes to the human, matching BuyerConversation::agentSpokeLast()
        // — two admin-request writes (a line edit and a transition, say) can
        // land in the same millisecond, and the merchant must not lose that.
        return $buyer === null || $merchant >= $buyer;
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
     * Known ceiling, accepted: `updatedAt` carries no authorship, so a line
     * timestamp is weak evidence of a buyer at best — strongest when the
     * buyer's own target changed, which is exactly what the token
     * comparison above checks, but never proof. A merchant who writes ANY
     * column on the line moves the same `updatedAt` — the unit price is
     * the one they have every reason to touch, since it is what
     * OfferApplier overwrites. If that line's ask token already differs
     * from the stamp — a real, still-unread buyer ask — the merchant's
     * later write to the SAME row is what this method reads back as the
     * buyer's timestamp, dating the ask after the merchant acted and
     * hiding a stand-down that should have covered it. A full fix needs
     * per-write authorship this column does not carry; short of that, this
     * stays a known gap rather than a claimed mitigation.
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
