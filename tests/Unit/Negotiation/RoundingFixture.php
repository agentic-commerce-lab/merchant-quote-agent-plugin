<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

/**
 * Shared inputs and readers for the rounding-control tests (spec 2026-09-28):
 * settings with rounding on, readers for the trace and the written discount,
 * and a two-VAT-rate quote with shipping in its gross, net and discounted
 * forms. No private constructor: this holds ten methods, and mago's
 * too-many-methods fails at eleven.
 */
final class RoundingFixture
{
    /** NegotiationFixture::settings()'s bands, with rounding on. */
    public static function settings(RoundingMode $mode, float $step): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(
                maxDiscountPercent: 10.0,
                counterOfferMaxPercent: 20.0,
                validityDays: 14,
                roundingMode: $mode,
                roundingStep: $step,
            )),
            llm: NegotiationFixture::modelAccess(),
            strategyPrompt: null,
        );
    }

    /** @return array<string, mixed>|null the meta of the first pass's `rounding` event; null when none was recorded */
    public static function roundingMeta(FakeDecisionWriter $writer): ?array
    {
        foreach ($writer->drafts[0]->trace as $event) {
            if ($event->kind === TraceKind::Rounding) {
                return $event->meta;
            }
        }

        return null;
    }

    /** The first quote discount written, past updates that set none. */
    public static function writtenDiscount(FakeQuoteGateway $gateway): ?Discount
    {
        foreach ($gateway->quoteUpdates as $update) {
            if ($update->discount !== null) {
                return $update->discount;
            }
        }

        return null;
    }

    /**
     * A gross quote with two VAT rates and shipping: 10 × 100.00 net at 19%
     * (1190.00 gross), 5 × 50.00 net at 7% (267.50 gross), 5.95 gross
     * shipping (5.00 net). 1255.00 net, 1463.45 gross.
     */
    public static function mixedGross(): QuoteSnapshot
    {
        return self::quote(self::goods(1000.0 / 1190.0, 250.0 / 267.5), 1255.0, 1463.45);
    }

    /** mixedGross() already holding 7.2% quote-wide: a discount line of -90.00 net, -104.94 gross. */
    public static function mixedGrossHolding(): QuoteSnapshot
    {
        return self::quote(
            [...self::goods(1000.0 / 1190.0, 250.0 / 267.5), self::discountLine(-90.0, -104.94)],
            1165.0,
            1358.51,
            new Discount(DiscountType::Percentage, 7.2),
        );
    }

    /**
     * mixedGross() once an absolute 103.45 has landed: the buyer-facing total
     * on 1360.00, net relief 88.72 (103.45 split 1190 : 267.5 over 19% and 7%).
     */
    public static function mixedGrossLanded(float $totalGross = 1360.0): QuoteSnapshot
    {
        return self::quote(
            [...self::goods(1000.0 / 1190.0, 250.0 / 267.5), self::discountLine(-88.72, -103.45)],
            1166.28,
            $totalGross,
            new Discount(DiscountType::Absolute, 103.45),
        );
    }

    /**
     * The same goods and shipping on a `net` quote (stored prices net, ratio
     * 1.0): 1255.00 net. A `$totalGross` equal to `$totalNet` is tax-free;
     * above it, tax is added on top. Lines not in `$totalNet` read as other
     * costs (5.00 shipping by default).
     */
    public static function netQuote(float $totalGross, float $totalNet = 1255.0): QuoteSnapshot
    {
        return self::quote(self::goods(1.0, 1.0), $totalNet, $totalGross);
    }

    /** @return list<QuoteLineSnapshot> */
    private static function goods(float $ratio19, float $ratio7): array
    {
        return [
            new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 10,
                unitPriceNet: 100.0,
                totalNet: 1000.0,
                netRatio: $ratio19,
            ),
            new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-2', 'Gadget', 'prod-2'),
                quantity: 5,
                unitPriceNet: 50.0,
                totalNet: 250.0,
                netRatio: $ratio7,
            ),
        ];
    }

    private static function discountLine(float $net, float $gross): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity('discount', 'Quote discount'),
            quantity: 1,
            unitPriceNet: $net,
            totalNet: $net,
            netRatio: $net / $gross,
        );
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private static function quote(
        array $lines,
        float $totalNet,
        float $totalGross,
        ?Discount $discount = null,
    ): QuoteSnapshot {
        $base = NegotiationFixture::snapshot(state: 'in_review');

        return new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: new QuoteTotals(totalNet: $totalNet, discount: $discount, totalGross: $totalGross),
            lifecycle: $base->lifecycle,
            content: new QuoteContent(lines: $lines),
        );
    }
}
