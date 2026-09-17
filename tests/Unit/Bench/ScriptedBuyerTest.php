<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bench;

use MerchantQuoteAgentPlugin\Tests\Bench\BuyerMoveKind;
use MerchantQuoteAgentPlugin\Tests\Bench\ScriptedBuyer;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use PHPUnit\Framework\TestCase;

/**
 * Snapshots come from `NegotiationFixture::snapshot()` (tests/Unit/Negotiation),
 * not a bench-local builder: it already builds a `QuoteSnapshot` for a given
 * `totalNet` and its `snapshot()` method is public, so there is nothing here
 * that needs its own fixture class.
 */
final class ScriptedBuyerTest extends TestCase
{
    public function testItAcceptsOnceTheOfferReachesItsTarget(): void
    {
        $buyer = new ScriptedBuyer(targetDiscountPercent: 10.0, concessionRatio: 0.6, patience: 4);

        $move = $buyer->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 890.0),
            'We can offer 11% off.',
            round: 1,
        );

        self::assertSame(BuyerMoveKind::Accept, $move->kind);
    }

    public function testItCountersAcrossTheRemainingGapWhenTheOfferFallsShort(): void
    {
        $buyer = new ScriptedBuyer(targetDiscountPercent: 10.0, concessionRatio: 0.6, patience: 4);

        $move = $buyer->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 960.0),
            'We can offer 4% off.',
            round: 1,
        );

        self::assertSame(BuyerMoveKind::Counter, $move->kind);
        self::assertNotNull($move->comment);
    }

    public function testItWalksOnceItsPatienceIsSpent(): void
    {
        // Without this, a scenario whose agent never concedes enough would run to
        // maxRounds every time and the round-count measure would report the cap
        // rather than the negotiation.
        $buyer = new ScriptedBuyer(targetDiscountPercent: 10.0, concessionRatio: 0.6, patience: 2);

        self::assertSame(
            BuyerMoveKind::Walk,
            $buyer->respond(
                NegotiationFixture::snapshot(totalNet: 1000.0),
                NegotiationFixture::snapshot(totalNet: 995.0),
                'We cannot move further.',
                round: 3,
            )->kind,
        );
    }

    public function testAnUnchangedTotalIsNotReadAsAConcession(): void
    {
        // An escalated or clarified pass leaves the price alone. Treating that as
        // a 0% concession and countering against it would measure the agent's
        // silence as a negotiating position.
        $buyer = new ScriptedBuyer(targetDiscountPercent: 10.0, concessionRatio: 0.6, patience: 4);

        $move = $buyer->respond(
            NegotiationFixture::snapshot(totalNet: 1000.0),
            NegotiationFixture::snapshot(totalNet: 1000.0),
            'A colleague will come back to you.',
            round: 1,
        );

        self::assertSame(BuyerMoveKind::Counter, $move->kind);
    }
}
