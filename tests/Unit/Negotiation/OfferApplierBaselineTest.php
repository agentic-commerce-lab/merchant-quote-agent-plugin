<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** #49: the baseline is captured on the first writing pass and never moved. */
final class OfferApplierBaselineTest extends TestCase
{
    private static function applier(): OfferApplier
    {
        return new OfferApplier(new OfferVerifier(), new NullLogger(), new DecisionRecorder(new FakeDecisionWriter()));
    }

    private static function quoteWideOffer(): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: 5.0));
    }

    /**
     * #49. The pre-write snapshot IS the pre-negotiation state on the first
     * pass that writes, so the baseline rides in the update the applier
     * already makes — no extra write, no extra revision bump.
     */
    public function testTheFirstWritingPassStoresTheBaseline(): void
    {
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot(), NegotiationFixture::snapshot()]);

        self::applier()
            ->apply($gateway, NegotiationFixture::snapshot(), NegotiationFixture::settings(), self::quoteWideOffer());

        $stored = null;

        foreach ($gateway->customFieldWrites as $write) {
            $stored ??= $write[QuoteBaseline::KEY] ?? null;
        }

        self::assertIsArray($stored, 'The first writing pass stored no baseline.');
        self::assertSame(1000.0, $stored['totalNet']);
        self::assertSame(100.0, $stored['lines'][0]['unitPriceNet']);
        self::assertSame(10, $stored['lines'][0]['quantity'], 'Quantities are what keep NetFactor coherent.');
    }

    /** A quote that already has one keeps it: the anchor must never move. */
    public function testASecondPassDoesNotOverwriteTheBaseline(): void
    {
        $withBaseline = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(totalNet: 900.0),
            NegotiationFixture::baselineOf(1000.0, 100.0),
        );
        $gateway = new FakeQuoteGateway([$withBaseline, $withBaseline]);

        self::applier()->apply($gateway, $withBaseline, NegotiationFixture::settings(), self::quoteWideOffer());

        $keysWritten = [];

        foreach ($gateway->customFieldWrites as $write) {
            $keysWritten = [...$keysWritten, ...array_keys($write)];
        }

        self::assertNotContains(
            QuoteBaseline::KEY,
            $keysWritten,
            'The baseline was rewritten, so the anchor moves with every round.',
        );
    }

    /**
     * The verifier must measure against the baseline, not the pre-write
     * snapshot. Asserted through behaviour rather than a spy because
     * OfferVerifier is final: the line is at 90 now but started at 100, and
     * the offer prices it at 85. Against the baseline that is 15% off and
     * breaches the 10% cap; against the pre-write snapshot it is 5.6% off and
     * looks clean. Only one of those fails.
     */
    public function testTheVerifierMeasuresAgainstTheBaselineNotThePreWriteSnapshot(): void
    {
        $before = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(totalNet: 900.0),
            NegotiationFixture::baselineOf(1000.0, 100.0),
        );
        $after = NegotiationFixture::snapshot(totalNet: 850.0);
        $gateway = new FakeQuoteGateway([$before, $after]);

        $offer = new ProposedOffer(orderTotalNet: 900.0, price: new OfferedPrice(linePricesNet: [new QuoteLinePrice(
            'line-1',
            85.0,
        )]));

        $applied = self::applier()->apply($gateway, $before, NegotiationFixture::settings(), $offer);

        self::assertFalse(
            $applied->verified,
            'An 85 line against a 100 baseline is 15% off and must breach the 10% cap; '
            . 'passing means the pre-write 90 was used as the reference.',
        );
    }
}
