<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * merchant_quote_agent_decision, rolled up for one company.
 *
 * Aggregated in PHP rather than in SQL because the input is at most a few dozen
 * rows and the rules describe recorded passes: each reduction compares that
 * pass’s opening and closing totals, never the original quote baseline. The
 * latest recorded reduction is neither cumulative nor proof of delivery.
 * `offersMade` counts authorized proposal passes, before writes and verification.
 *
 * Sorts by timestamp itself rather than trusting the query's ORDER BY, so a
 * NULL created_at or an index change cannot silently reorder the answer.
 */
final readonly class DecisionRollup
{
    /**
     * @param list<string>         $quoteIdsWithOffers distinct quotes with an authorized proposal pass
     * @param array<string, float> $grantedByQuote     quote id => latest recorded per-pass reduction
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
