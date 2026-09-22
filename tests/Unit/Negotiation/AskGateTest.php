<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
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
            '{"price":{"additionalDiscountPercent":5},"negotiation":{"delivery":{"freeShipping":true,'
                . '"expedited":false,"requestedLeadTimeDays":null}}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% and free shipping please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'A non-price ask must not reach the negotiate call.');
        self::assertSame(
            QuoteEscalationReason::NonPriceTermRequested->value,
            $harness->writer->drafts[0]->escalationReason,
        );
    }

    public function testAnEmptyNonPriceShapeIsNotAnAskAndStillNegotiates(): void
    {
        // Models emit the whole `negotiation` object with `false` and nulls in
        // it even when the buyer asked for nothing non-price. Escalating on the
        // object's presence alone would send every quote to a human.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5},"negotiation":{"delivery":{"freeShipping":false,'
                . '"expedited":false,"requestedLeadTimeDays":null},"bundle":{"requested":false}}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            'ok',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
    }

    public function testAnOlderRoundsAskIsNotExtractedAgain(): void
    {
        // Re-reading the whole history re-extracts round one's "another 5%"
        // and applies it a second time to a total that already came down by it.
        // The negotiate call is shown round one's figures instead (#166) —
        // which is what its prompt claims it is given.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":2}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            'ok',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('please add another 5%', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment(
                'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11.',
                '2026-08-28 09:30:00',
            ),
            NegotiationFixture::buyerComment('can you do a little better?', '2026-08-28 10:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString('can you do a little better?', $harness->spy->userPrompts[0]);
        self::assertStringNotContainsString('another 5%', $harness->spy->userPrompts[0]);
        self::assertStringContainsString(
            'buyer asked (unstated) -> you offered 950.00',
            $harness->spy->userPrompts[1],
            'The negotiate prompt states the agent is shown its own earlier rounds, so it must be.',
        );
    }
}
