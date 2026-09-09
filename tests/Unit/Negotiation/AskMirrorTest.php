<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * A buyer who types their number gets the same record as a buyer who uses the
 * storefront's "Requested price" field: SwagCommercial 7.13's line-item asks
 * are what both UIs render, and until now a chat ask reached the deciders and
 * then evaporated, so admin and storefront both showed `-` for it.
 *
 * Mirrored for EVERY outcome, not just an offer — an escalation is exactly
 * when a human opens the quote and needs to see what was asked for.
 */
final class AskMirrorTest extends TestCase
{
    public function testAPriceAskTypedInChatIsWrittenOntoTheLine(): void
    {
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":95}]}}',
            '{"action":"offer","message":"95.00 per unit, valid until 2026-09-11.","terms":{"discountPercent":5}}',
            'We can do 95.00 per unit. Valid until 2026-09-11.',
        ]);

        $outcome = $this->serviceWith($harness, 'can I get the widget for 95?');

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame([95.0], self::mirroredPrices($harness));
    }

    public function testAPriceAskIsRecordedEvenWhenThePassEscalates(): void
    {
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":50}]}}',
        ]);

        $outcome = $this->serviceWith($harness, '50 per unit or we walk');

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame([50.0], self::mirroredPrices($harness));
    }

    /** Without the marker the agent reads its own write back as a fresh buyer ask. */
    public function testTheMirrorIsRecordedOnTheQuoteSoTheAgentCanTellItFromABuyersOwnAsk(): void
    {
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":50}]}}',
        ]);

        $this->serviceWith($harness, '50 per unit or we walk');

        self::assertContains([MirroredAsks::KEY => ['line-1' => 50.0]], $harness->gateway->customFieldWrites);
    }

    /**
     * The marker is merged, never replaced: an earlier round's mirror on
     * another line must keep hiding the value it wrote, or that line's stored
     * ask resurfaces as an unanswered one.
     */
    public function testAnEarlierRoundsMirrorSurvivesThisRoundsWrite(): void
    {
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":50}]}}',
        ]);
        $harness->before = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment('50 per unit or we walk', '2026-08-28 09:00:00'),
            ]),
            [MirroredAsks::KEY => ['line-9' => 12.0]],
        );

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertContains(
            [MirroredAsks::KEY => ['line-9' => 12.0, 'line-1' => 50.0]],
            $harness->gateway->customFieldWrites,
        );
    }

    public function testAnAskWithNoPerLineTargetMirrorsNothing(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off, valid until 2026-09-11.","terms":{"discountPercent":5}}',
            'We can offer 5% off. Valid until 2026-09-11.',
        ]);

        $this->serviceWith($harness, '5% please');

        self::assertSame([], self::mirroredPrices($harness));
        self::assertSame([], self::markerWrites($harness));
    }

    /**
     * A stale structured ask beats a comment target outside a renegotiation
     * round, so the policy layer prices against the buyer's 90 and never looks
     * at the comment's 80. Displaying 80 would show a number the agent ignored.
     */
    public function testATargetTheMergerDoesNotAdoptIsNotDisplayed(): void
    {
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":80}]}}',
            '{"action":"offer","message":"90.00 per unit, valid until 2026-09-11.","terms":{"discountPercent":10}}',
            'We can do 90.00 per unit. Valid until 2026-09-11.',
        ]);
        $harness->before = NegotiationFixture::snapshot(comments: [NegotiationFixture::buyerComment(
            'make it 80',
            '2026-08-28 09:00:00',
        )], requestedUnitPrice: 90.0);

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame([], self::mirroredPrices($harness));
    }

    private function serviceWith(PipelineHarness $harness, string $comment): NegotiationOutcome
    {
        return $harness->pipeline->service(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment($comment, '2026-08-28 09:00:00'),
            ]),
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );
    }

    /** @return list<float> */
    private static function mirroredPrices(PipelineHarness $harness): array
    {
        $prices = [];

        foreach ($harness->gateway->lineItemChanges as $change) {
            if (!$change instanceof QuoteLineItemChange || $change->requestedUnitPriceNet === null) {
                continue;
            }

            $prices[] = $change->requestedUnitPriceNet;
        }

        return $prices;
    }

    /** @return list<array<string, mixed>> */
    private static function markerWrites(PipelineHarness $harness): array
    {
        return array_values(array_filter(
            $harness->gateway->customFieldWrites,
            static fn(array $write): bool => \array_key_exists(MirroredAsks::KEY, $write),
        ));
    }
}
