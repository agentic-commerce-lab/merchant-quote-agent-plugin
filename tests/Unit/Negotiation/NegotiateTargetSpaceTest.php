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

    public function testAPerLineAskTypedInACommentReachesTheLineTableInNet(): void
    {
        // #222, sw-ag.dev quote 1202: "770.21 a unit" on a gross quote was
        // filed as 647.24 net, but the negotiate prompt showed only the raw
        // comment beside NET unit prices, and the model escalated because
        // "770.21 is higher than 727.23". The gross fixture's net ratio is
        // 0.8: 90 gross is 72.00 net.
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":90.0}]}}',
            '{"action":"offer","message":"Done.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('Can you do 90 a unit?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $negotiatePrompt = $harness->spy->userPrompts[1] ?? '';
        self::assertMatchesRegularExpression(
            '/line-1 \|[^\n]*\| 72\.00$/m',
            $negotiatePrompt,
            'The line row must carry the converted ask in the "buyer asks per unit net" column.',
        );
        self::assertStringContainsString(
            'include tax',
            $negotiatePrompt,
            "On a gross quote the model must be told the buyer's own figures are gross.",
        );
    }

    public function testANetQuoteGetsNoGrossLabel(): void
    {
        // Review Focus 3: a net-stored quote's buyer writes net; a gross label
        // there would mislead the other way.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"Done.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $negotiatePrompt = $harness->spy->userPrompts[1] ?? '';
        self::assertStringContainsString(
            'Line items (',
            $negotiatePrompt,
            'The negotiate prompt must have been written.',
        );
        self::assertStringNotContainsString('include tax', $negotiatePrompt);
    }

    public function testInARenegotiationRoundTheCommentTargetWinsTheColumnOverAStandingStorefrontAsk(): void
    {
        // CommentLineTargets::adoptedBy(): in change_requested the newer comment
        // ask is the one priced, even over a storefront "Requested price". The
        // column must show that number, not the stale storefront one.
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":90.0}]}}',
            '{"action":"offer","message":"Done.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::grossSnapshot([NegotiationFixture::buyerComment(
            'Can you do 90 a unit?',
            '2026-08-28 09:00:00',
        )], requestedUnitPrice: 70.0);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertMatchesRegularExpression('/line-1 \|[^\n]*\| 72\.00$/m', $harness->spy->userPrompts[1] ?? '');
    }

    public function testOutsideARenegotiationRoundAStandingStorefrontAskWinsTheColumn(): void
    {
        // The sibling: in `open` adoptedBy() drops the comment target for a
        // line that carries a storefront ask, so the column shows that one,
        // as stored.
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":90.0}]}}',
            '{"action":"offer","message":"Done.","terms":{"discountPercent":5}}',
            'Here is your offer.',
        ]);
        $harness->before = NegotiationFixture::grossSnapshot(
            [NegotiationFixture::buyerComment('Can you do 90 a unit?', '2026-08-28 09:00:00')],
            state: 'open',
            requestedUnitPrice: 70.0,
        );

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertMatchesRegularExpression('/line-1 \|[^\n]*\| 70\.00$/m', $harness->spy->userPrompts[1] ?? '');
    }
}
