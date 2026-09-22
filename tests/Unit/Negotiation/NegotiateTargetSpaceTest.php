<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

/**
 * The negotiate half of the seam BuyerPriceSpaceTest pins on the extract half.
 *
 * Extraction converts the buyer's figure into net and the band decider reads
 * it there — but the negotiate prompt is written entirely in net (quote total,
 * unit prices) and used to carry nothing of the ask but the buyer's own
 * sentence, which still holds their GROSS number. The model then read "3000"
 * against a net total and priced to it.
 *
 * sw-ag.dev quote 1055: a 7%-tax quote, buyer "max cost should be 3000k".
 * Extraction filed the correct 2803.74 net target; the model answered 4.35%,
 * landing on 2999.75 net — 3209.73 gross, 209.73 ABOVE the ask — while its
 * own message claimed it had met a "target budget of 3,000 EUR". The band had
 * granted room for 10.6%, so nothing but the prompt stopped it from meeting
 * the ask outright.
 */
final class NegotiateTargetSpaceTest extends TestCase
{
    public function testTheNegotiatePromptCarriesTheBuyersTargetInTheNetSpaceItPricesIn(): void
    {
        // The gross fixture: 800.00 net behind 1000.00 gross. A buyer naming
        // 950 gross is asking for 760.00 net — a 5% ask, inside the cap.
        $harness = PipelineHarness::with([
            '{"price":{"targetTotal":950.0}}',
            '{"action":"offer","message":"5% for you.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('950 for everything please', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $negotiatePrompt = $harness->spy->userPrompts[1] ?? '';
        self::assertStringContainsString(
            '760.00',
            $negotiatePrompt,
            "The model prices in net and must be told the buyer's target in net.",
        );
    }
}
