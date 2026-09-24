<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

/**
 * sw-ag.dev quotes 1097 and 1099: the buyer entered a requested price in the
 * storefront (600.00 and 590.00 against 654.53, both inside the 15% cap) and
 * wrote no comment. The negotiate prompt listed the lines without that price,
 * so the model was shown no ask at all, proposed no terms, and the buyer was
 * answered with the unchanged quote.
 */
final class NegotiateRequestedPriceTest extends TestCase
{
    public function testTheNegotiatePromptShowsTheBuyersRequestedUnitPrice(): void
    {
        // A structured-only ask: no comment, so no extract call. The first
        // scripted reply is the negotiate answer.
        $harness = PipelineHarness::with([
            '{"action":"offer","message":"90 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":90}]}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::snapshot(requestedUnitPrice: 90.0);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $negotiatePrompt = $harness->spy->userPrompts[0] ?? '';
        self::assertStringContainsString(
            'Line items (lineItemId | productId | label | quantity | unit price net | buyer asks per unit net):',
            $negotiatePrompt,
        );
        self::assertStringContainsString('line-1 | prod-1 | Widget | 10 | 100.00 | 90.00', $negotiatePrompt);
    }

    public function testALineWithoutARequestedPriceLeavesTheColumnEmpty(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $negotiatePrompt = $harness->spy->userPrompts[1] ?? '';
        self::assertMatchesRegularExpression('/^line-1 \| prod-1 \| Widget \| 10 \| 100\.00 \| $/m', $negotiatePrompt);
    }
}
