<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

/**
 * Shared inputs and readers for the rounding-control tests (spec 2026-09-28).
 * No private constructor: Task 4 brings this to ten methods, and mago's
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
}
