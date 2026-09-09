<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\History\DecisionRollup;
use PHPUnit\Framework\TestCase;

/**
 * Our own decision table is the richest history we have, and the only place
 * that knows we made an offer at all. It is keyed by quote_id only, which is
 * why it is aggregated from rows a customer-filtered quote read already
 * returned.
 */
final class DecisionRollupTest extends TestCase
{
    /** @return list<array{quote_id: string, authorized: int|null, discount_percent_granted: float|null, created_at: string}> */
    private static function rows(): array
    {
        return [
            [
                'quote_id' => 'q1',
                'authorized' => 1,
                'discount_percent_granted' => 3.0,
                'created_at' => '2026-08-01 10:00:00.000',
            ],
            [
                'quote_id' => 'q1',
                'authorized' => 1,
                'discount_percent_granted' => 5.0,
                'created_at' => '2026-08-02 10:00:00.000',
            ],
            [
                'quote_id' => 'q2',
                'authorized' => 0,
                'discount_percent_granted' => null,
                'created_at' => '2026-08-03 10:00:00.000',
            ],
            [
                'quote_id' => 'q3',
                'authorized' => 1,
                'discount_percent_granted' => 2.5,
                'created_at' => '2026-08-04 10:00:00.000',
            ],
        ];
    }

    public function testOnlyAuthorizedPassesCountAsOffersMade(): void
    {
        // An escalated pass is not an offer. Counting it would tell the model we
        // have been generous with an account we have never actually priced.
        self::assertSame(3, DecisionRollup::of(self::rows())->offersMade);
    }

    public function testQuotesWeOfferedOnAreListedOnceEach(): void
    {
        $rollup = DecisionRollup::of(self::rows());

        self::assertSame(['q1', 'q3'], $rollup->quoteIdsWithOffers);
    }

    public function testThePerQuoteGrantIsTheLatestOnThatQuote(): void
    {
        // Round two improved q1 from 3% to 5%. What the buyer ended up with is
        // 5%, and that is the number the next negotiation is anchored against.
        self::assertSame(['q1' => 5.0, 'q3' => 2.5], DecisionRollup::of(self::rows())->grantedByQuote);
    }

    public function testTheLastGrantIsTheNewestAcrossEveryQuote(): void
    {
        self::assertSame(2.5, DecisionRollup::of(self::rows())->lastGrantedDiscountPercent);
    }

    public function testAnAccountWeNeverPricedRollsUpToNothing(): void
    {
        $rollup = DecisionRollup::of([]);

        self::assertSame(0, $rollup->offersMade);
        self::assertSame([], $rollup->quoteIdsWithOffers);
        self::assertSame([], $rollup->grantedByQuote);
        self::assertNull($rollup->lastGrantedDiscountPercent);
    }

    public function testRowsOutOfOrderStillResolveToTheNewestGrant(): void
    {
        // The SQL orders by created_at, but nothing downstream should depend on
        // that: a NULL created_at or an index change would break it silently.
        $rollup = DecisionRollup::of(array_reverse(self::rows()));

        self::assertSame(2.5, $rollup->lastGrantedDiscountPercent);
        self::assertSame(['q1' => 5.0, 'q3' => 2.5], $rollup->grantedByQuote);
    }

    public function testAPendingDecisionWithBothColumnsNullSitsAlongsideRealGrantsWithoutBecomingZero(): void
    {
        // The real table is mostly pending rows: escalated, not yet ruled on,
        // both `authorized` and `discount_percent_granted` NULL. NULL must stay
        // NULL here -- a pending row folding into 0/0.0 would misreport "we
        // denied this" or "we granted nothing" for a quote nobody has decided.
        $rows = [
            [
                'quote_id' => 'q1',
                'authorized' => null,
                'discount_percent_granted' => null,
                'created_at' => '2026-08-01 10:00:00.000',
            ],
            [
                'quote_id' => 'q2',
                'authorized' => 1,
                'discount_percent_granted' => 0.0,
                'created_at' => '2026-08-02 10:00:00.000',
            ],
        ];

        $rollup = DecisionRollup::of($rows);

        self::assertSame(1, $rollup->offersMade);
        self::assertSame(['q2'], $rollup->quoteIdsWithOffers);
        // A granted 0% is real information, not "we don't know" -- it must not
        // collide with, or be dropped alongside, q1's genuine NULL.
        self::assertSame(['q2' => 0.0], $rollup->grantedByQuote);
        self::assertSame(0.0, $rollup->lastGrantedDiscountPercent);
    }
}
