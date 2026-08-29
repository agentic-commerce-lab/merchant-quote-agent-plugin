<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * What the pipeline reads out of the conversation before it proposes anything:
 * which asks it refuses to answer, and which comment each prompt is shown.
 */
final class AskGateTest extends TestCase
{
    public function testANonPriceAskGoesToAHumanRatherThanBeingAnsweredAboutPriceOnly(): void
    {
        // Only `price` is composed into the proposal and QuoteUpdate cannot
        // write a delivery term, so answering the discount half would drop the
        // shipping half in silence — and granting it would promise a term that
        // never lands on the quote.
        $harness = PipelineHarness::with([
            '{"additional_discount_percent": 5, "negotiation": {"delivery": {"free_shipping": true,'
                . ' "expedited": false, "requested_lead_time_days": null}}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% and free shipping please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'A non-price ask must not reach the negotiate call.');
    }

    public function testAnEmptyNonPriceShapeIsNotAnAskAndStillNegotiates(): void
    {
        // Models emit the whole `negotiation` object with `false` and nulls in
        // it even when the buyer asked for nothing non-price. Escalating on the
        // object's presence alone would send every quote to a human.
        $harness = PipelineHarness::with([
            '{"additional_discount_percent": 5, "negotiation": {"delivery": {"free_shipping": false,'
                . ' "expedited": false, "requested_lead_time_days": null}, "bundle": {"requested": false}}}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'ok',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Offered, $outcome);
    }

    public function testAnOlderRoundsAskIsNotExtractedAgain(): void
    {
        // Re-reading the whole history re-extracts round one's "another 5%"
        // and applies it a second time to a total that already came down by it.
        // The negotiate call is shown the agent's earlier replies instead —
        // which is what its prompt claims it is given.
        $harness = PipelineHarness::with([
            '{"additional_discount_percent": 2}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'ok',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('please add another 5%', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('We can bring this quote down by 5%.', '2026-08-28 09:30:00'),
            NegotiationFixture::buyerComment('can you do a little better?', '2026-08-28 10:00:00'),
        ]);

        $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertStringContainsString('can you do a little better?', $harness->spy->userPrompts[0]);
        self::assertStringNotContainsString('another 5%', $harness->spy->userPrompts[0]);
        self::assertStringContainsString(
            'We can bring this quote down by 5%.',
            $harness->spy->userPrompts[1],
            'The negotiate prompt states the agent is shown its own earlier offers, so it must be.',
        );
    }
}
