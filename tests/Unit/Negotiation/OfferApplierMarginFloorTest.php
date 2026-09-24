<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The minimum-margin floor at the one place offers are written (spec 2026-09-24). */
final class OfferApplierMarginFloorTest extends TestCase
{
    public static function applier(FakePurchasePrices $prices): OfferApplier
    {
        return new OfferApplier(
            new OfferVerifier(),
            new NullLogger(),
            new DecisionRecorder(new FakeDecisionWriter()),
            new MarginFloorGuard($prices),
        );
    }

    public static function settings(?float $minMarginPercent): QuoteAgentSettings
    {
        $settings = NegotiationFixture::settings(maxDiscountPercent: 25.0);

        return $settings->withPolicy($settings->policy->withPrice(new QuoteLimits(
            maxDiscountPercent: 25.0,
            validityDays: 14,
            minMarginPercent: $minMarginPercent,
        )));
    }

    public static function quoteWide(float $percent): ProposedOffer
    {
        return new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(discountPercent: $percent));
    }

    public function testAQuoteWideOfferBelowTheFloorIsWrittenAsLinePricesAtTheFloor(): void
    {
        // Purchase 80, margin 10% -> floor 88. 15% off 100 would be 85.
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        $applied = self::applier(new FakePurchasePrices(['prod-1' => 80.0]))
            ->apply($gateway, NegotiationFixture::snapshot(), self::settings(10.0), self::quoteWide(15.0));

        self::assertContains('updateLineItems', $gateway->calls);
        self::assertSame(88.0, $gateway->lineItemChanges[0]->unitPriceNet);
        self::assertNull($gateway->quoteUpdates[0]->discount, 'No quote discount to reset, so none is written.');
        self::assertTrue($applied->verified);
    }

    public function testAFlooredOfferResetsAnExistingQuoteDiscount(): void
    {
        // A 5% quote discount is on (the -100 line, so every line costs 95% of
        // its price). Naming line-1 at 80 lands at 76, under its floor of 88,
        // so the clamp writes line-1 at 88 AND line-2 at 95 -- the old
        // discount folded into its own price -- then resets the discount.
        // A quote-wide ask cannot show the fold on a first pass: a line only
        // keeps the old discount when it is deeper than the ask, and then the
        // floor (capped at today's price) cannot bind.
        $base = NegotiationFixture::snapshot(totalNet: 2000.0);
        $snapshot = new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: new QuoteTotals(1900.0, new Discount(DiscountType::Percentage, 5.0), 1900.0),
            lifecycle: $base->lifecycle,
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(new QuoteLineIdentity('line-1', 'Widget', 'prod-1'), 10, 100.0, 1000.0),
                new QuoteLineSnapshot(new QuoteLineIdentity('line-2', 'Gadget', 'prod-2'), 10, 100.0, 1000.0),
                new QuoteLineSnapshot(new QuoteLineIdentity('discount', 'Discount', null), 1, -100.0, -100.0),
            ], comments: []),
        );
        $gateway = new FakeQuoteGateway([$snapshot]);
        $offer = new ProposedOffer(orderTotalNet: 1900.0, price: new OfferedPrice(linePricesNet: [
            new QuoteLinePrice('line-1', 80.0),
        ]));

        self::applier(new FakePurchasePrices(['prod-1' => 80.0]))
            ->apply($gateway, $snapshot, self::settings(10.0), $offer);

        $written = [];
        foreach ($gateway->lineItemChanges as $change) {
            $written[$change->lineItemId] = $change->unitPriceNet;
        }
        self::assertSame(['line-1' => 88.0, 'line-2' => 95.0], $written);
        self::assertSame(DiscountType::Percentage, $gateway->quoteUpdates[0]->discount?->type);
        self::assertSame(0.0, $gateway->quoteUpdates[0]->discount?->value);
        self::assertSame($snapshot->revision, $gateway->firstExpectedRevision);
    }

    public function testAnOfferAboveTheFloorIsWrittenExactlyAsProposed(): void
    {
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        self::applier(new FakePurchasePrices(['prod-1' => 80.0]))
            ->apply($gateway, NegotiationFixture::snapshot(), self::settings(10.0), self::quoteWide(5.0));

        self::assertNotContains('updateLineItems', $gateway->calls);
        self::assertSame(5.0, $gateway->quoteUpdates[0]->discount?->value);
    }

    public function testWithoutAMarginThePurchasePricesAreNeverRead(): void
    {
        $prices = new FakePurchasePrices(['prod-1' => 99.0]);
        $gateway = new FakeQuoteGateway([NegotiationFixture::snapshot()]);

        self::applier($prices)
            ->apply($gateway, NegotiationFixture::snapshot(), self::settings(null), self::quoteWide(15.0));

        self::assertSame([], $prices->calls);
        self::assertSame(15.0, $gateway->quoteUpdates[0]->discount?->value);
    }

    public function testAFlooredOfferLeavesALineWhosePriceDoesNotMoveUnwritten(): void
    {
        // line-1: purchase 80 -> floor 88, 15% off lands at 85, raised to 88.
        // line-2: purchase 50 x 1.10 = 55 is above today's 50.00, so its floor
        // is capped at 50.00; 15% off is held there, the price does not move,
        // and rewriting it could shift it a cent through the gross conversion.
        $base = NegotiationFixture::snapshot(totalNet: 1500.0);
        $snapshot = new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: new QuoteTotals(1500.0, null, 1500.0),
            lifecycle: $base->lifecycle,
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(new QuoteLineIdentity('line-1', 'Widget', 'prod-1'), 10, 100.0, 1000.0),
                new QuoteLineSnapshot(new QuoteLineIdentity('line-2', 'Gadget', 'prod-2'), 10, 50.0, 500.0),
            ], comments: []),
        );
        $gateway = new FakeQuoteGateway([$snapshot]);

        self::applier(new FakePurchasePrices(['prod-1' => 80.0, 'prod-2' => 50.0]))
            ->apply(
                $gateway,
                $snapshot,
                self::settings(10.0),
                new ProposedOffer(orderTotalNet: 1500.0, price: new OfferedPrice(discountPercent: 15.0)),
            );

        self::assertCount(1, $gateway->lineItemChanges);
        self::assertSame('line-1', $gateway->lineItemChanges[0]->lineItemId);
        self::assertSame(88.0, $gateway->lineItemChanges[0]->unitPriceNet);
    }

    public function testAWriteTheDatabaseLandsBelowTheFloorFailsVerification(): void
    {
        // The offer is fine (88 = the floor); the database says 80 afterwards.
        $gateway = new FakeQuoteGateway([
            NegotiationFixture::snapshot(),
            NegotiationFixture::snapshot(totalNet: 800.0),
        ]);
        $offer = new ProposedOffer(orderTotalNet: 1000.0, price: new OfferedPrice(linePricesNet: [
            new QuoteLinePrice('line-1', 88.0),
        ]));

        $applied = self::applier(new FakePurchasePrices(['prod-1' => 80.0]))
            ->apply($gateway, NegotiationFixture::snapshot(), self::settings(10.0), $offer);

        self::assertFalse($applied->verified);
        self::assertContains(
            'line "Widget" priced 80.00 net below its minimum-margin floor 88.00',
            $applied->violations,
        );
    }
}
