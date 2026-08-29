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
}
