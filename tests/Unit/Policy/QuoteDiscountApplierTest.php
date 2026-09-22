<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\QuoteDiscountApplier;
use PHPUnit\Framework\TestCase;

/**
 * #164's absolute quote-level target, honoured the same place
 * `additionalDiscountPercent` already is: QuoteDiscountApplier produces the
 * "effective" snapshot QuoteBandDecider prices the offer against.
 */
final class QuoteDiscountApplierTest extends TestCase
{
    public function testAnAbsoluteQuoteLevelTargetBecomesTheBuyerTarget(): void
    {
        $effective = (new QuoteDiscountApplier())->apply(
            self::snapshot(),
            new CommentInterpretation(price: new PriceAsk(targetTotal: 900.0)),
        );

        self::assertSame(900.0, $effective->buyerTargetNet);
        // Honoured like a plain percentage ask, not duplicated onto every
        // line: QuoteAutoReplyPricer already scales uniformly off
        // buyerTargetNet when no line carries a target of its own.
        self::assertNull($effective->lines[0]->requestedUnitPrice);
    }

    public function testAnExtraPercentStacksOnTopOfTheAbsoluteTarget(): void
    {
        // The prompt promises additionalDiscountPercent is "on top of any
        // requested prices already entered" — the absolute target is one of
        // those, so a further 10% comes off IT (900 * 0.9 = 810), not off
        // the original 1000.
        $effective = (new QuoteDiscountApplier())->apply(
            self::snapshot(),
            new CommentInterpretation(price: new PriceAsk(additionalDiscountPercent: 10.0, targetTotal: 900.0)),
        );

        self::assertSame(810.0, $effective->buyerTargetNet);
    }

    private static function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 1000.0,
            lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget'),
                quantity: 10,
                unitPriceNet: 100.0,
                totalNet: 1000.0,
            )],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
        );
    }
}
