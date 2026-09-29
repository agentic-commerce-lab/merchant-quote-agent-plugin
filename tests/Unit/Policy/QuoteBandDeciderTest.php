<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecisionKind;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\PriceBandClassifier;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The counter band, reachable for the first time. Before this, an ask above
 * maxDiscountPercent always escalated — a faithful port of the TypeScript
 * "no auto counter-offer", which #18 replaces with the PM's fixed counter.
 */
final class QuoteBandDeciderTest extends TestCase
{
    /** A 1000.00 net quote whose buyer asks for $askPercent off. */
    private static function snapshotAsking(float $askPercent): QuoteSnapshot
    {
        $total = 1000.0;

        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: $total,
            lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1'),
                quantity: 1,
                unitPriceNet: $total,
                totalNet: $total,
            )],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
            buyerTargetNet: $total * (1 - ($askPercent / 100)),
        );
    }

    /** @return iterable<string, array{0: float, 1: Band, 2: float|null}> */
    public static function bands(): iterable
    {
        // ask, expected band, expected counteredRequestPercent
        yield 'below the cap grants' => [5.0, Band::Grant, null];
        yield 'exactly the cap grants' => [10.0, Band::Grant, null];
        yield 'just inside the counter band counters' => [10.5, Band::Counter, 10.5];
        yield 'at the counter ceiling counters' => [20.0, Band::Counter, 20.0];
        yield 'above the counter ceiling escalates' => [20.5, Band::Escalate, null];
    }

    #[DataProvider('bands')]
    public function testTheAskLandsInTheRightBand(float $ask, Band $expected, ?float $countered): void
    {
        $limits = new QuoteLimits(maxDiscountPercent: 10.0, counterOfferMaxPercent: 20.0);

        $decision = (new QuoteBandDecider())->decide(self::snapshotAsking($ask), $limits);

        self::assertSame($expected, (new PriceBandClassifier())->classify($decision));
        self::assertEqualsWithDelta($countered, $decision->autoReply?->counteredRequestPercent, 0.001);
    }

    public function testACounterIsPricedAtTheCapNotAtTheAsk(): void
    {
        $limits = new QuoteLimits(maxDiscountPercent: 10.0, counterOfferMaxPercent: 20.0);

        $decision = (new QuoteBandDecider())->decide(self::snapshotAsking(15.0), $limits);

        self::assertSame(QuoteDecisionKind::AutoReply, $decision->kind);
        self::assertEqualsWithDelta(10.0, $decision->autoReply?->discountPercent, 0.001);
    }

    public function testWithNoCounterCeilingConfiguredAnAboveCapAskStillEscalates(): void
    {
        $limits = new QuoteLimits(maxDiscountPercent: 10.0);

        $decision = (new QuoteBandDecider())->decide(self::snapshotAsking(15.0), $limits);

        self::assertSame(QuoteDecisionKind::Escalate, $decision->kind);
    }

    /**
     * 10 x 727.23 net, 7272.27 for the line (sw-ag.dev's 865.40 gross unit),
     * asking $targetNet: every figure is cent-rounded after the tax comes off.
     */
    private static function roundedQuoteAsking(float $targetNet): QuoteSnapshot
    {
        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 7272.27,
            lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1'),
                quantity: 10,
                unitPriceNet: 727.23,
                totalNet: 7272.27,
            )],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
            buyerTargetNet: $targetNet,
        );
    }

    public function testAStorefrontAskAtExactlyTheCapIsGrantedAtTheCap(): void
    {
        // 15% off 865.40 gross is 735.59, 618.14 net a unit: 6181.40 against
        // 7272.27 reads 15.0004%, cent noise and not an ask above the cap.
        $limits = new QuoteLimits(maxDiscountPercent: 15.0, counterOfferMaxPercent: 25.0);

        $decision = (new QuoteBandDecider())->decide(self::roundedQuoteAsking(6181.40), $limits);

        self::assertSame(Band::Grant, (new PriceBandClassifier())->classify($decision));
        self::assertSame(15.0, $decision->autoReply?->discountPercent, 'Granted at the cap, never past it.');
    }

    public function testAnAskAtExactlyTheCapIsGrantedWithNoCounterBandToo(): void
    {
        $decision = (new QuoteBandDecider())->decide(
            self::roundedQuoteAsking(6181.40),
            new QuoteLimits(maxDiscountPercent: 15.0),
        );

        self::assertSame(QuoteDecisionKind::AutoReply, $decision->kind);
    }

    public function testAnAskAtExactlyTheCounterCeilingCounters(): void
    {
        // sw-ag.dev quote 1036: 25% off 8654.00 gross is 6490.50, 5454.20 net,
        // which reads 25.00003% and used to escalate.
        $limits = new QuoteLimits(maxDiscountPercent: 15.0, counterOfferMaxPercent: 25.0);

        $decision = (new QuoteBandDecider())->decide(self::roundedQuoteAsking(5454.20), $limits);

        self::assertSame(Band::Counter, (new PriceBandClassifier())->classify($decision));
    }

    public function testAnAskACentPerUnitPastTheCapIsStillAboveIt(): void
    {
        // 617.13 a unit is 15.14% off: more than rounding can explain.
        $decision = (new QuoteBandDecider())->decide(
            self::roundedQuoteAsking(6171.30),
            new QuoteLimits(maxDiscountPercent: 15.0),
        );

        self::assertSame(QuoteDecisionKind::Escalate, $decision->kind);
    }
}
