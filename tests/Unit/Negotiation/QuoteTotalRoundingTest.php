<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rounding control, quote_total mode, at the one place offers are written
 * (spec 2026-09-28). Ten methods, the most mago's too-many-methods allows.
 * The four rules share one data-provided test for that reason.
 */
final class QuoteTotalRoundingTest extends TestCase
{
    /** @return array{0: AppliedOffer, 1: array<string, mixed>|null, 2: RecordingLogger} */
    private static function apply(
        FakeQuoteGateway $gateway,
        ProposedOffer $offer,
        ?float $asked = null,
        float $step = 10.0,
    ): array {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $logger = new RecordingLogger();
        $recorder->begin(NegotiationFixture::snapshot(), NegotiationFixture::context());

        $applied = (new OfferApplier(
            new OfferVerifier(),
            $logger,
            $recorder,
            new MarginFloorGuard(new FakePurchasePrices()),
        ))->apply(
            $gateway,
            NegotiationFixture::snapshot(),
            RoundingFixture::settings(RoundingMode::QuoteTotal, $step),
            $offer,
            $asked,
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        return [$applied, RoundingFixture::roundingMeta($writer), $logger];
    }

    private static function quoteWide(float $percent): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1255.0, price: new OfferedPrice(discountPercent: $percent));
    }

    public function testAGrossQuoteWithTwoRatesAndShippingLandsOnTheRoundTotal(): void
    {
        $gateway = new FakeQuoteGateway([RoundingFixture::mixedGross(), RoundingFixture::mixedGrossLanded()]);

        [, $meta, $logger] = self::apply($gateway, self::quoteWide(7.34));

        self::assertEquals(new Discount(DiscountType::Absolute, 103.45), RoundingFixture::writtenDiscount($gateway));
        self::assertEquals(
            ['mode' => 'quote_total', 'step' => 10.0, 'unrounded' => 1356.47, 'rounded' => 1360.0, 'skipped' => null],
            $meta,
        );
        // Shopware keeps an absolute discount line at exactly -value gross.
        self::assertEqualsWithDelta(1360.0, 1457.5 - 103.45 + 5.95, 1e-9);
        self::assertNull($logger->contextOf('did not land'));
    }

    public function testATaxFreeQuoteLandsOnTheRoundTotalToo(): void
    {
        // 1250.00 × 0.9266 + 5.00 = 1163.25 → 1170.00; 1255.00 − 1170.00 = 85.00.
        $gateway = new FakeQuoteGateway([RoundingFixture::netQuote(1255.0)]);

        self::apply($gateway, self::quoteWide(7.34));

        self::assertEquals(new Discount(DiscountType::Absolute, 85.0), RoundingFixture::writtenDiscount($gateway));
    }

    /** @return iterable<string, array{0: \Closure(): \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot, 1: float, 2: ?float, 3: float, 4: string}> */
    public static function skips(): iterable
    {
        yield 'tax added on top of net prices' => [
            static fn() => RoundingFixture::netQuote(1493.45),
            7.34,
            null,
            10.0,
            'tax_on_top',
        ];
        yield 'rounded, it would take back the 7.2% the buyer holds' => [
            RoundingFixture::mixedGrossHolding(...),
            7.34,
            null,
            10.0,
            'standing_price',
        ];
        yield 'the buyer asked for exactly this' => [
            RoundingFixture::mixedGross(...),
            7.34,
            7.34,
            10.0,
            'buyer_figure',
        ];
        yield 'rounded up to 1500.00, no discount is left' => [
            RoundingFixture::mixedGross(...),
            0.3,
            null,
            100.0,
            'to_zero',
        ];
    }

    /** @param \Closure(): \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $quote */
    #[DataProvider('skips')]
    public function testEachRuleWritesTheUnroundedPercentage(
        \Closure $quote,
        float $percent,
        ?float $asked,
        float $step,
        string $skipped,
    ): void {
        $gateway = new FakeQuoteGateway([$quote()]);

        [, $meta] = self::apply($gateway, self::quoteWide($percent), $asked, $step);

        self::assertEquals(
            new Discount(DiscountType::Percentage, $percent),
            RoundingFixture::writtenDiscount($gateway),
        );
        self::assertSame($skipped, $meta['skipped'] ?? null);
    }

    public function testAPerLineOfferIsNotRounded(): void
    {
        $gateway = new FakeQuoteGateway([RoundingFixture::mixedGross()]);
        $offer = new ProposedOffer(orderTotalNet: 1255.0, price: new OfferedPrice(linePricesNet: [
            new QuoteLinePrice('line-1', 95.0),
        ]));

        [, $meta] = self::apply($gateway, $offer);

        self::assertNull($meta);
        self::assertSame(95.0, $gateway->lineItemChanges[0]->unitPriceNet);
    }

    public function testATotalThatMissedItsRoundFigureIsLoggedNotHidden(): void
    {
        $gateway = new FakeQuoteGateway([RoundingFixture::mixedGross(), RoundingFixture::mixedGrossLanded(1361.0)]);

        [, , $logger] = self::apply($gateway, self::quoteWide(7.34));

        self::assertSame(['quoteId' => 'q1', 'landed' => 1361.0], $logger->contextOf('did not land'));
    }

    /** @return iterable<string, array{0: float, 1: Discount}> */
    public static function asks(): iterable
    {
        yield 'the model offers exactly what the buyer asked' => [7.34, new Discount(DiscountType::Percentage, 7.34)];
        // 1000.00 × 0.9266 = 926.60 → 930.00; 1000.00 − 930.00 = 70.00.
        yield 'the model offers less than the buyer asked' => [8.0, new Discount(DiscountType::Absolute, 70.0)];
    }

    /** End to end: OfferRound must hand the buyer's ask to the applier, or rule 2 never fires. */
    #[DataProvider('asks')]
    public function testThePipelineLeavesOnlyTheBuyersOwnFigureUnrounded(float $asked, Discount $written): void
    {
        $harness = PipelineHarness::with([
            sprintf('{"price":{"additionalDiscountPercent":%s}}', $asked),
            '{"action":"offer","message":"ok","terms":{"discountPercent":7.34}}',
            PipelineHarness::rewordedReply(),
        ]);

        $harness->pipeline->service(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment(sprintf('%s%% please', $asked), '2026-08-28 09:00:00'),
            ]),
            $harness->gateway,
            RoundingFixture::settings(RoundingMode::QuoteTotal, 10.0),
            NegotiationFixture::context(),
        );

        self::assertEquals($written, RoundingFixture::writtenDiscount($harness->gateway));
    }
}
