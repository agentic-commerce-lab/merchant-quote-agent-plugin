<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\VerifyOfferInput;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A quote-wide write lands as SwagCommercial's negative discount line, while
 * totalNet carries shipping. NetFactor counted that negative line in its line
 * sum, so after every quote-wide write on a quote with shipping each line read
 * dearer than its reference — "A" at 105.26 against 105.00 — and the pass
 * escalated VerificationFailed after it had already written.
 */
final class LineOfferVerifierTest extends TestCase
{
    /** @param list<QuoteLineSnapshot> $finalLines */
    #[DataProvider('quoteWideWrites')]
    public function testAQuoteWideWriteInsideTheCapVerifiesClean(
        float $referenceTotal,
        array $referenceLines,
        float $finalTotal,
        array $finalLines,
    ): void {
        self::assertSame([], self::verify($referenceTotal, $referenceLines, $finalTotal, $finalLines));
    }

    /** @return iterable<string, array{float, list<QuoteLineSnapshot>, float, list<QuoteLineSnapshot>}> */
    public static function quoteWideWrites(): iterable
    {
        // Goods 200 net, shipping 10 net.
        $net = [self::line('A', 100.0), self::line('B', 50.0, 2)];
        yield 'shipping, 5% quote-wide' => [210.0, $net, 200.0, [...$net, self::line('discount', -10.0)]];
        yield 'shipping, 10% quote-wide at the cap' => [210.0, $net, 190.0, [...$net, self::line('discount', -20.0)]];
        yield 'no shipping, 10% quote-wide at the cap' => [
            200.0,
            $net,
            180.0,
            [...$net, self::line('discount', -20.0)],
        ];

        // Gross cart, mixed tax: A 100 net at 19%, B 50 net at 7%; lines read
        // gross (226 in all), totals net. 5% off is -11.30 gross, 10 net.
        $gross = [self::line('A', 119.0), self::line('B', 53.5, 2)];
        yield 'gross, mixed tax, shipping, 5% quote-wide' => [
            210.0,
            $gross,
            200.0,
            [...$gross, self::line('discount', -11.3)],
        ];
    }

    /**
     * The side effect of counting only positive lines: the floor now sees the
     * quote discount on top of a line's own cut — the price the buyer pays,
     * as PredictedWrite prices it before the write and CappedAuthority
     * measures a hold. "A" at its 10% cap plus 5% quote-wide is 14.5% off.
     */
    public function testALineAtItsCapPlusAQuoteDiscountIsBelowTheMinimum(): void
    {
        $reference = [self::line('A', 100.0), self::line('B', 50.0, 2)];
        $final = [self::line('A', 90.0), self::line('B', 50.0, 2), self::line('discount', -9.5)];

        self::assertSame(
            ['line "A" priced 85.50 net below the allowed minimum 90.00'],
            self::verify(200.0, $reference, 180.5, $final),
        );
    }

    /**
     * @param list<QuoteLineSnapshot> $referenceLines
     * @param list<QuoteLineSnapshot> $finalLines
     *
     * @return list<string>
     */
    private static function verify(
        float $referenceTotal,
        array $referenceLines,
        float $finalTotal,
        array $finalLines,
    ): array {
        return (new OfferVerifier())->verify(new VerifyOfferInput(
            reference: self::snapshot($referenceTotal, $referenceLines),
            final: self::snapshot($finalTotal, $finalLines),
            limits: new QuoteLimits(maxDiscountPercent: 10.0),
            now: new \DateTimeImmutable('2026-09-28T12:00:00Z'),
        ));
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private static function snapshot(float $totalNet, array $lines): QuoteSnapshot
    {
        return new QuoteSnapshot('EUR', $totalNet, $lines, new QuoteLifecycle('open'));
    }

    private static function line(string $id, float $unitPrice, int $quantity = 1): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity($id, $id),
            quantity: $quantity,
            unitPriceNet: $unitPrice,
            totalNet: $unitPrice * $quantity,
        );
    }
}
