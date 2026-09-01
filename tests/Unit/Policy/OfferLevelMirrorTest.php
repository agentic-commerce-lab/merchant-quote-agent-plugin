<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\OfferLevelMirror;
use PHPUnit\Framework\TestCase;

/**
 * Issue #47. The negotiate prompt tells the model to answer at the level the
 * buyer asked at, and nothing enforced it: a buyer who itemised their ask
 * could get a quote-wide percentage instead. The rules-only path never had
 * this problem — `OfferProposer::deterministicOffer()` picks the level from
 * `perLineAsks` — so this closes the gap on the model path.
 */
final class OfferLevelMirrorTest extends TestCase
{
    public function testAQuoteWideAnswerToAPerLineAskBecomesLinePrices(): void
    {
        $offer = self::offer(new OfferedPrice(discountPercent: 10.0));

        $mirrored = OfferLevelMirror::mirror($offer, self::linesWithAnAskOn('a'));

        self::assertNull($mirrored->price->discountPercent, 'The quote-wide discount survived the conversion.');
        self::assertSame(
            [['a', 90.0], ['b', 180.0]],
            self::pairs($mirrored),
            'Every reference line must be priced, not only the asked one: the percentage is a '
            . 're-expression of the same concession, so dropping lines would shrink it.',
        );
    }

    /** The model's magnitude is kept exactly; only the level changes. */
    public function testTheConvertedPricesRoundThroughMoneyMath(): void
    {
        $offer = self::offer(new OfferedPrice(discountPercent: 7.5));

        $mirrored = OfferLevelMirror::mirror($offer, [self::line('a', 33.33, requested: 30.0)]);

        // 33.33 * 0.925 = 30.83025, which MoneyMath rounds to 30.83.
        self::assertSame([['a', 30.83]], self::pairs($mirrored));
    }

    public function testAPerLineAnswerToAPerLineAskIsUntouched(): void
    {
        $offer = self::offer(new OfferedPrice(linePricesNet: [new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice(
            'a',
            95.0,
        )]));

        $mirrored = OfferLevelMirror::mirror($offer, self::linesWithAnAskOn('a'));

        self::assertSame([['a', 95.0]], self::pairs($mirrored));
        self::assertNull($mirrored->price->discountPercent);
    }

    /**
     * The mirror case is deliberately not enforced: per-line prices satisfy a
     * quote-wide ask precisely and are already bounded line by line.
     */
    public function testAQuoteWideAskIsLeftAloneInEitherDirection(): void
    {
        $noAsks = [self::line('a', 100.0), self::line('b', 200.0)];

        $wide = OfferLevelMirror::mirror(self::offer(new OfferedPrice(discountPercent: 10.0)), $noAsks);
        self::assertSame(10.0, $wide->price->discountPercent);
        self::assertNull($wide->price->linePricesNet);

        $perLine = OfferLevelMirror::mirror(self::offer(
            new OfferedPrice(linePricesNet: [new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice('a', 95.0)]),
        ), $noAsks);
        self::assertSame([['a', 95.0]], self::pairs($perLine));
    }

    /** Without reference lines there is nothing to price, so the offer stands as it is. */
    public function testAnOfferWithNoReferenceLinesIsUntouched(): void
    {
        $mirrored = OfferLevelMirror::mirror(self::offer(new OfferedPrice(discountPercent: 10.0)), []);

        self::assertSame(10.0, $mirrored->price->discountPercent);
        self::assertNull($mirrored->price->linePricesNet);
    }

    /**
     * Only the price level changes. The reference lines matter most: the
     * authorizer bounds per-line offers against them, so losing them would
     * make LinePriceOfferCheck reject every converted line as "not on this
     * quote".
     */
    public function testEverythingOutsideThePriceLevelSurvivesTheConversion(): void
    {
        $offer = self::offer(new OfferedPrice(discountPercent: 10.0));
        $lines = self::linesWithAnAskOn('a');

        $mirrored = OfferLevelMirror::mirror($offer, $lines);

        self::assertEquals($lines, $mirrored->price->referenceLines);
        self::assertSame($offer->orderTotalNet, $mirrored->orderTotalNet);
        self::assertSame($offer->delivery, $mirrored->delivery);
        self::assertSame($offer->payment, $mirrored->payment);
    }

    private static function offer(OfferedPrice $price): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 300.0, price: $price);
    }

    /** @return list<QuoteLineSnapshot> */
    private static function linesWithAnAskOn(string $lineItemId): array
    {
        return [
            self::line('a', 100.0, requested: $lineItemId === 'a' ? 95.0 : null),
            self::line('b', 200.0, requested: $lineItemId === 'b' ? 190.0 : null),
        ];
    }

    private static function line(string $id, float $unitPriceNet, ?float $requested = null): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity(lineItemId: $id),
            quantity: 1,
            unitPriceNet: $unitPriceNet,
            totalNet: $unitPriceNet,
            requestedUnitPrice: $requested,
        );
    }

    /** @return list<array{0: string, 1: float}> */
    private static function pairs(ProposedOffer $offer): array
    {
        return array_map(static fn(\MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice $p): array => [
            $p->lineItemId,
            $p->unitPriceNet,
        ], $offer->price->linePricesNet ?? []);
    }
}
