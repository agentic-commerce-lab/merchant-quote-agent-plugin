<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;

/**
 * The quote's comments split by who wrote them, which is the whole basis for
 * "is there anything new to answer?".
 *
 * All three buckets are narrow on purpose. `buyer` is the comments
 * SwagCommercial attributes to a customer or a B2B employee; `agent` is the
 * ones with no author at all, which is what the agent's own writes look like
 * (#3 measured all three columns null, pinned by AddCommentTest). `merchant`
 * is the administration's own notes, `createdById` alone — kept rather than
 * dropped, because MerchantHandover needs to know a human already answered,
 * but still out of both prompts: see SnapshotAdapter's conversation(), and
 * #55 for what it cost while a merchant's note lived in `buyer` instead.
 *
 * The day SwagCommercial starts stamping an author on a system-source comment
 * is the day the agent bucket needs a new discriminator — and AddCommentTest
 * is what will tell us.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10). The class was already AT that
 * threshold before this file's own task touched it — `hasNewBuyerAsk()`,
 * `newestBuyerText()`, and `newest()` alone measure 10, with zero headroom,
 * and that baseline is unrelated to what this task added. `agentSpokeLast()`
 * is written as small as the comparison allows — one `newest()` call over the
 * merged human buckets, one `&&` and one `||` to handle "no agent comment" and
 * "no human comment" — measuring 2, for a class total of 12. Splitting either
 * side further would trade a branch for a second method with the same branch
 * moved, not fewer of them.
 */
final readonly class BuyerConversation
{
    /**
     * @param list<QuoteComment> $buyer
     * @param list<QuoteComment> $agent
     * @param list<QuoteComment> $merchant the administration's own notes: `createdById` and neither buyer column
     */
    public function __construct(
        public array $buyer,
        public array $agent,
        public array $merchant = [],
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

    /** The newest merchant note, as a 'U.u' string, or null when there is none. */
    public function merchantSpokeAt(): ?string
    {
        return self::newest($this->merchant);
    }

    /** The newest buyer ask, as a 'U.u' string, or null when there is none. */
    public function buyerSpokeAt(): ?string
    {
        return self::newest($this->buyer);
    }

    /**
     * True when the agent's own reply is the newest comment on the quote.
     *
     * That shape IS a stranded reply: only the agent writes an author-less
     * comment (#3, pinned by AddCommentTest), so a pass that posted its reply
     * and then died before the transition leaves exactly this. No human can
     * produce it, which is what makes it safe for OfferRound to finish a
     * transition on the strength of it.
     */
    public function agentSpokeLast(): bool
    {
        $agent = self::newest($this->agent);
        $human = self::newest([...$this->buyer, ...$this->merchant]);

        return $agent !== null && ($human === null || $human < $agent);
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
