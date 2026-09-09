<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * merchant_quote_agent_decision, rolled up for one company.
 *
 * Aggregated in PHP rather than in SQL because the input is at most a few dozen
 * rows and the rules are commercial, not relational: "what did they end up
 * with" is the LATEST grant on a quote, not the sum or the max, and only an
 * `authorized` pass is an offer at all.
 *
 * Sorts by timestamp itself rather than trusting the query's ORDER BY, so a
 * NULL created_at or an index change cannot silently reorder the answer.
 */
final readonly class DecisionRollup
{
    /**
     * @param list<string>         $quoteIdsWithOffers quotes the agent actually priced
     * @param array<string, float> $grantedByQuote     quote id => the grant it ended on
     */
    public function __construct(
        public int $offersMade = 0,
        public array $quoteIdsWithOffers = [],
        public array $grantedByQuote = [],
        public ?float $lastGrantedDiscountPercent = null,
    ) {}

    /**
     * @param list<array{quote_id: string, authorized: int|null, discount_percent_granted: float|null, created_at: string}> $rows
     */
    public static function of(array $rows): self
    {
        usort($rows, static fn(array $a, array $b): int => $a['created_at'] <=> $b['created_at']);

        $made = 0;
        $withOffers = [];
        $granted = [];
        $last = null;

        foreach ($rows as $row) {
            if (($row['authorized'] ?? 0) === 1) {
                ++$made;
                $withOffers[$row['quote_id']] = true;
            }

            if ($row['discount_percent_granted'] !== null) {
                $granted[$row['quote_id']] = $row['discount_percent_granted'];
                $last = $row['discount_percent_granted'];
            }
        }

        return new self($made, array_keys($withOffers), $granted, $last);
    }
}
