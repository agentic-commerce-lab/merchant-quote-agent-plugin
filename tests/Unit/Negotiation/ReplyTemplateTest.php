<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegativeReduction;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Negotiation\RewordingGuard;
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

    public function testAcknowledgesRestatesTheQuoteAndNamesBothNextSteps(): void
    {
        $sentence = ReplyTemplate::acknowledges(3500.17, 'EUR', new \DateTimeImmutable('2026-10-08'));

        self::assertSame(
            'Thank you for your message. This quote stands at 3500.17 EUR. '
            . 'The offer remains valid until 2026-10-08. '
            . 'You can accept it as it is, or tell us what you would like changed.',
            $sentence,
        );
    }

    public function testAcknowledgesInventsNoValidityForAQuoteWithoutOne(): void
    {
        $sentence = ReplyTemplate::acknowledges(3500.17, 'EUR', null);

        self::assertSame(
            'Thank you for your message. This quote stands at 3500.17 EUR. '
            . 'You can accept it as it is, or tell us what you would like changed.',
            $sentence,
        );
    }

    /**
     * No model writes this sentence, so the guard never sees it at runtime.
     * Running it through anyway pins the copy to the same rules every
     * buyer-facing sentence obeys: a later edit that adds a figure, a
     * concession word or a sixth sentence fails here.
     */
    public function testAcknowledgesPassesTheRewordingGuard(): void
    {
        $validUntil = new \DateTimeImmutable('2026-10-08');

        self::assertNull(RewordingGuard::unsafeBecause(
            ReplyTemplate::acknowledges(3500.17, 'EUR', $validUntil),
            null,
            3500.17,
            $validUntil,
        ));

        // The guard has no "no expiry" input, so the undated variant can only
        // be judged against a date it deliberately leaves out. Dropped facts
        // are the guard's last check: failing there and nowhere earlier
        // proves no invented figure, concession or extra sentence.
        self::assertSame('it dropped the validity date', RewordingGuard::unsafeBecause(
            ReplyTemplate::acknowledges(3500.17, 'EUR', null),
            null,
            3500.17,
            $validUntil,
        ));
    }
}
