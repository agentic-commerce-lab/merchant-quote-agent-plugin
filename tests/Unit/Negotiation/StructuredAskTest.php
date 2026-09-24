<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\StructuredAsk;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * A storefront ask counts while the agent has not answered it yet: below the
 * quoted price AND not among the asks the last pass stamped.
 */
final class StructuredAskTest extends TestCase
{
    public function testAFreshAskBelowTheQuotedPriceIsOpen(): void
    {
        self::assertTrue(StructuredAsk::isOpen(NegotiationFixture::snapshot(
            totalNet: 850.0,
            requestedUnitPrice: 80.0,
        )));
    }

    public function testAnAskTheLastPassStampedIsNotOpenEvenWhileUnmet(): void
    {
        $snapshot = NegotiationFixture::snapshot(totalNet: 850.0, requestedUnitPrice: 80.0);

        self::assertFalse(StructuredAsk::isOpen(self::stampedAt80($snapshot)));
    }

    public function testAMetAskIsNotOpen(): void
    {
        self::assertFalse(StructuredAsk::isOpen(NegotiationFixture::snapshot(
            totalNet: 800.0,
            requestedUnitPrice: 80.0,
        )));
    }

    public function testAPriceEditedAfterTheStampIsOpenAgain(): void
    {
        $snapshot = NegotiationFixture::snapshot(totalNet: 850.0, requestedUnitPrice: 78.0);

        self::assertTrue(StructuredAsk::isOpen(self::stampedAt80($snapshot)));
    }

    private static function stampedAt80(QuoteSnapshot $snapshot): QuoteSnapshot
    {
        $answered = NegotiationFixture::snapshot(totalNet: 850.0, requestedUnitPrice: 80.0);

        return NegotiationFixture::withCustomFields($snapshot, [
            ServicingFingerprint::MARKER_KEY => ServicingFingerprint::stamp($answered, 'replied'),
        ]);
    }
}
