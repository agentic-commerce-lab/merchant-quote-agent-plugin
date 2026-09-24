<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\VerifyOfferInput;
use MerchantQuoteAgentPlugin\Policy\MarginFloorVerifier;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use PHPUnit\Framework\TestCase;

final class MarginFloorVerifierTest extends TestCase
{
    public function testALineAtItsFloorPasses(): void
    {
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 110.0)]);

        self::assertSame([], (new MarginFloorVerifier())->verify($final, ['a' => 110.0]));
    }

    public function testALineBelowItsFloorIsAViolation(): void
    {
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 108.0)]);

        self::assertSame(
            ['line "a" priced 108.00 net below its minimum-margin floor 110.00'],
            (new MarginFloorVerifier())->verify($final, ['a' => 110.0]),
        );
    }

    public function testAQuoteDiscountStackedUnderTheFloorIsAViolation(): void
    {
        // The line itself says 110, but a -11 quote-discount line takes it to 99.
        $final = MarginFloorsTest::quote([
            MarginFloorsTest::line('a', 110.0),
            MarginFloorsTest::line('d', -11.0, 1, null),
        ]);

        self::assertCount(1, (new MarginFloorVerifier())->verify($final, ['a' => 110.0]));
    }

    public function testNoFloorsChecksNothing(): void
    {
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 1.0)]);

        self::assertSame([], (new MarginFloorVerifier())->verify($final, []));
    }

    public function testOfferVerifierRunsTheFloorCheck(): void
    {
        $reference = MarginFloorsTest::quote([MarginFloorsTest::line('a', 120.0)]);
        $final = MarginFloorsTest::quote([MarginFloorsTest::line('a', 108.0)]);

        $violations = (new OfferVerifier())->verify(new VerifyOfferInput(
            reference: $reference,
            final: $final,
            limits: new QuoteLimits(maxDiscountPercent: 20.0, validityDays: 14, minMarginPercent: 10.0),
            now: new \DateTimeImmutable(),
            floors: ['a' => 110.0],
        ));

        self::assertContains('line "a" priced 108.00 net below its minimum-margin floor 110.00', $violations);
    }
}
