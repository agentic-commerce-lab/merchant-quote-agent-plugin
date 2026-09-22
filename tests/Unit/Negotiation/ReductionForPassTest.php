<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ReductionForPass;
use PHPUnit\Framework\TestCase;

/**
 * Which of the three things a pass can report, decided in one place.
 *
 * `OfferRoundTest` pins two of them end to end -- quote 1045's hold and quote
 * 1039's never-raise escalation. This file is the unit's own table, and it
 * exists for the case those two shapes do not reach: a write that moved the
 * total and still has nothing to announce.
 */
final class ReductionForPassTest extends TestCase
{
    public function testAPassThatWroteNothingIsAHold(): void
    {
        self::assertSame([null, false], ReductionForPass::of(34456.73, 34000.0, grantedThisPass: false));
    }

    public function testAPassThatMovedTheTotalReportsWhatItMovedIt(): void
    {
        self::assertSame([15.0, false], ReductionForPass::of(1000.0, 850.0, grantedThisPass: true));
    }

    /**
     * The gap #175's first fix left open.
     *
     * A hold was defined as "the write moved nothing", which is not the same
     * question as "the sentence has something to say". `percent()` prints two
     * decimals, so 0.50 EUR off 34456.73 -- a per-line cut on one small line
     * of a quote the size the issue was filed from -- is a real write whose
     * figure still prints `0`, and the buyer would read "We can bring this
     * quote down by 0% to 34456.23 EUR": the exact sentence the issue exists
     * to remove, reached by the one route the fix did not cover.
     *
     * Reported as a hold instead. Nothing is lost by it: `GrantedDiscount`
     * records the figure to four decimals for the audit trail, which is where
     * a movement too small to state belongs.
     */
    public function testAWriteTooSmallToPrintIsAHoldRatherThanAZeroPercentDiscount(): void
    {
        self::assertSame([null, false], ReductionForPass::of(34456.73, 34456.23, grantedThisPass: true));
    }

    /**
     * #174's third outcome: the figure disagrees with a write that already
     * landed, so the pass escalates instead of composing a reply. The caught
     * `NegativeReduction` must not reach the caller -- see `OfferRound` for
     * what an uncaught one would do to a retrying worker.
     */
    public function testAnIncreaseIsReportedAsADisagreementNotAFigure(): void
    {
        self::assertSame([null, true], ReductionForPass::of(1818.20, 2008.83, grantedThisPass: true));
    }
}
