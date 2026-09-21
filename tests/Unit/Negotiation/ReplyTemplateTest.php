<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegativeReduction;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use PHPUnit\Framework\TestCase;

final class ReplyTemplateTest extends TestCase
{
    public function testReductionIsUnchangedForAGenuineDecrease(): void
    {
        self::assertSame(5.0, ReplyTemplate::reduction(1000.0, 950.0));
    }

    public function testReductionIsZeroWhenNothingMoved(): void
    {
        self::assertSame(0.0, ReplyTemplate::reduction(1000.0, 1000.0));
    }

    /**
     * #174: the clamp that rendered an increase as "0%" is gone. A negative
     * movement now means the never-raise check upstream (OfferApplier) did
     * not do its job, and that must fail loudly rather than describe an
     * increase as a discount.
     */
    public function testAnIncreaseIsAHardFailureNotAClampedZero(): void
    {
        $this->expectException(NegativeReduction::class);

        ReplyTemplate::reduction(1818.20, 2008.83);
    }

    public function testHoldsStatesTheTotalAndTheDateWithNoPercentage(): void
    {
        $sentence = ReplyTemplate::holds(34000.0, 'EUR', new \DateTimeImmutable('2026-10-05'));

        self::assertSame('This quote stands at 34000.00 EUR. The offer remains valid until 2026-10-05.', $sentence);
        self::assertStringNotContainsString('%', $sentence);
    }
}
