<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The gate that asks the buyer instead of guessing.
 *
 * Before it existed, an ambiguous ask reached the decider with nothing set,
 * landed in the grant band at roughly 0% and got a generic offer reply — which
 * advanced hasNewBuyerAsk(), so the buyer's real question was never resurfaced.
 */
final class ClarificationGateTest extends TestCase
{
    private const AMBIGUOUS = '{"clarificationQuestions":["Which line did you mean?"]}';

    public function testAnAmbiguousAskIsPutToTheBuyerAndNeverReachesTheNegotiateCall(): void
    {
        $harness = PipelineHarness::with([self::AMBIGUOUS]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('can you do 10% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Clarified, $outcome);
        self::assertSame(1, $harness->spy->calls, 'An ambiguous ask must not reach the negotiate call.');
        self::assertSame(['Which line did you mean?'], $harness->gateway->comments);
    }

    public function testTheSameAmbiguityEscalatesOnceWeHaveAlreadyAsked(): void
    {
        $harness = PipelineHarness::with([self::AMBIGUOUS]);
        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment('I mean the widgets', '2026-08-28 09:00:00'),
            ]),
            [ClarificationMarker::MARKER_KEY => true],
        );

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls);
    }

    public function testAStructuralAskThatIsAlsoAmbiguousEscalatesAsStructural(): void
    {
        // Guard order is load-bearing: changing WHAT is sold is outside the
        // mandate whether or not the ask is clear, so the structural gate above
        // must win. If this ever asks the buyer instead, the guards were
        // reordered.
        $harness = PipelineHarness::with([
            '{"clarificationQuestions":["Which line did you mean?"],'
                . '"structural":{"lineChanges":[{"lineItemId":"line-1","quantity":20}]}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('make it 20 of something', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);

        foreach ($harness->gateway->customFieldWrites as $write) {
            self::assertArrayNotHasKey(
                ClarificationMarker::MARKER_KEY,
                $write,
                'A structural ask must escalate as structural, never write the clarification marker.',
            );
        }
    }
}
