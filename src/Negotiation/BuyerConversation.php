<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;

/**
 * The quote's comments split by who wrote them, which is the whole basis for
 * "is there anything new to answer?".
 *
 * Authorship is the discriminator because #3 measured an agent comment as null
 * on createdById, customerId and employeeId alike, pinned by AddCommentTest.
 * The day SwagCommercial starts stamping an author is the day this switches to
 * reading createdById — and that test is what will tell us.
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

    /** Every buyer comment, oldest first, as the extract prompt expects. */
    public function buyerText(): string
    {
        return implode("\n", array_map(static fn(QuoteComment $c): string => $c->comment, $this->buyer));
    }

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
