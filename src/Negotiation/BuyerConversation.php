<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;

/**
 * The quote's comments split by who wrote them, which is the whole basis for
 * "is there anything new to answer?".
 *
 * Both buckets are narrow on purpose. `buyer` is the comments SwagCommercial
 * attributes to a customer or a B2B employee; `agent` is the ones with no
 * author at all, which is what the agent's own writes look like (#3 measured
 * all three columns null, pinned by AddCommentTest). A merchant's note, which
 * carries `createdById` alone, is in neither — see SnapshotAdapter's
 * conversation(), and #55 for what it cost while it was in `buyer`.
 *
 * The day SwagCommercial starts stamping an author on a system-source comment
 * is the day the agent bucket needs a new discriminator — and AddCommentTest
 * is what will tell us.
 */
final readonly class BuyerConversation
{
    /**
     * @param list<QuoteComment> $buyer
     * @param list<QuoteComment> $agent
     */
    public function __construct(
        public array $buyer,
        public array $agent,
    ) {}

    /**
     * True when a buyer comment is newer than every agent reply. A re-trigger
     * with nothing new must not cost a model call.
     */
    public function hasNewBuyerAsk(): bool
    {
        $newestBuyer = self::newest($this->buyer);

        if ($newestBuyer === null) {
            return false;
        }

        $newestAgent = self::newest($this->agent);

        return $newestAgent === null || $newestBuyer > $newestAgent;
    }

    /**
     * The agent's own earlier replies, oldest first. The negotiate prompt
     * states it is shown them, and a later round is unreadable without them.
     */
    public function agentText(): string
    {
        return implode("\n", array_map(static fn(QuoteComment $c): string => $c->comment, $this->agent));
    }

    /**
     * The one comment a pass answers. Every earlier ask was already extracted
     * and already answered; re-reading the whole history re-applies round
     * one's "another 5%" to a total that has already come down by it.
     */
    public function newestBuyerText(): string
    {
        $newest = null;
        $text = '';

        foreach ($this->buyer as $comment) {
            $at = $comment->createdAt?->format('U.u') ?? '0';

            if ($newest === null || $at > $newest) {
                $newest = $at;
                $text = $comment->comment;
            }
        }

        return $text;
    }

    /** @param list<QuoteComment> $comments */
    private static function newest(array $comments): ?string
    {
        $newest = null;

        foreach ($comments as $comment) {
            $at = $comment->createdAt?->format('U.u');

            if ($at !== null && ($newest === null || $at > $newest)) {
                $newest = $at;
            }
        }

        return $newest;
    }
}
