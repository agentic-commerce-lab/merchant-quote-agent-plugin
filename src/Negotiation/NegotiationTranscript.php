<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;

/**
 * The negotiate prompt's memory of its own thread (#166).
 *
 * Before this existed, the negotiate call read only the newest buyer comment
 * and re-anchored on the baseline, so the earlier rounds of THIS negotiation —
 * what the buyer asked two messages ago, what was offered for it — were
 * nowhere in the prompt. `HistoryRounds` is a different thing: the buyer's
 * past QUOTES and orders, not this quote's own earlier rounds.
 *
 * A round is one buyer comment followed by the agent's reply that answered
 * it, in the order `BuyerConversation` already carries them. The newest buyer
 * comment never closes a round — it has no reply yet, which is exactly what
 * makes it THIS round's ask (see `newestBuyerText()`).
 *
 * ponytail: the offer figure is read off the agent's OWN reply text with a
 * plain regex, not a stored ledger. `RewordingGuard` already guarantees every
 * reply that reaches a buyer states the new total formatted the way
 * `ReplyTemplate::money()` writes it (2 decimals, comma-grouping tolerated),
 * so this is reading a fact the system already enforces, not guessing at
 * prose. The buyer's own figure has no such guarantee — free text may state
 * no number at all — so a round with an unparsable ask still closes (as
 * "(unstated)") rather than being dropped, which would silently hide that a
 * round happened at all. Ceiling: a buyer comment stating two numbers (an
 * old price and a new one) picks the first. Upgrade path: read the figure
 * back off the decision record's own `interpretedAsks` column instead, once a
 * reader for it exists that does not cost OfferProposer a sixth constructor
 * parameter (see AGENTS.md's parameter cap).
 */
final class NegotiationTranscript
{
    /** Bounds the prompt on a long thread; see the class docblock. */
    private const MAX_ROUNDS = 5;

    private const UNSTATED = '(unstated)';

    private function __construct() {}

    public static function of(BuyerConversation $conversation): string
    {
        $rounds = \array_slice(self::rounds($conversation), -self::MAX_ROUNDS);

        return implode("\n", array_map(static fn(array $round): string => sprintf(
            'buyer asked %s -> you offered %s',
            $round[0],
            $round[1],
        ), $rounds));
    }

    /** @return list<array{0: string, 1: string}> */
    private static function rounds(BuyerConversation $conversation): array
    {
        $rounds = [];
        $ask = null;

        foreach (self::timeline($conversation) as [$side, $text]) {
            if ($side === 'buyer') {
                $ask = self::looseFigure($text) ?? self::UNSTATED;

                continue;
            }

            if ($ask === null) {
                continue;
            }

            $offer = self::strictMoney($text);

            if ($offer !== null) {
                $rounds[] = [$ask, $offer];
            }

            $ask = null;
        }

        return $rounds;
    }

    /** @return list<array{0: string, 1: string}> chronological (side, text) pairs */
    private static function timeline(BuyerConversation $conversation): array
    {
        $entries = [
            ...array_map(self::entry('buyer'), $conversation->buyer),
            ...array_map(self::entry('agent'), $conversation->agent),
        ];

        usort($entries, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn(array $entry): array => [$entry[1], $entry[2]], $entries);
    }

    /** @return \Closure(QuoteComment): array{0: string, 1: string, 2: string} */
    private static function entry(string $side): \Closure
    {
        return static fn(QuoteComment $comment): array => [
            $comment->createdAt?->format('U.u') ?? '0',
            $side,
            $comment->comment,
        ];
    }

    /** The figure `ReplyTemplate::money()` wrote, ungrouped, or null if the text carries none. */
    private static function strictMoney(string $text): ?string
    {
        $matches = [];

        if (preg_match('#\d[\d,]*\.\d{2}\b#', $text, $matches) !== 1) {
            return null;
        }

        /** @var array{0: string} $matches */
        return str_replace(',', '', $matches[0]);
    }

    /** Any figure of two digits or more in free buyer text, or null if it states none. */
    private static function looseFigure(string $text): ?string
    {
        $matches = [];

        if (preg_match('#\d{2,}(?:\.\d+)?#', $text, $matches) !== 1) {
            return null;
        }

        /** @var array{0: string} $matches */
        return $matches[0];
    }
}
