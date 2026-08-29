<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * One round, end to end over the wired pipeline: what it writes back to the
 * buyer, what it refuses to write twice, and what it reports about itself.
 */
final class OfferRoundTest extends TestCase
{
    public function testAPerLineOfferTellsTheBuyerWhatTheQuoteActuallyCameDownBy(): void
    {
        // A per-line offer carries no `discountPercent` — deliberately, in both
        // modes — so the reply used to announce "0% off this quote" on a quote
        // whose line prices had just been cut. The figure the buyer is told is
        // the one the database reports: 1000.00 before, 950.00 after.
        $harness = PipelineHarness::with([
            '{"line_changes":[{"line_item_id":"line-1","quantity":null,"target_unit_price":95,"remove":false}]}',
            '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":95}],"message":"95 each."}',
            'a rewording that keeps none of the facts',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('95 per unit?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertStringContainsString('down by 5% to 950.00 EUR', $harness->gateway->comments[0]);
        self::assertStringNotContainsString('0%', $harness->gateway->comments[0]);
    }

    public function testASecondRoundPerLineAskGoesToAHuman(): void
    {
        // #2(a), still open: `withReferenceLines` is captured fresh every
        // round, so round two is bounded against round one's already-reduced
        // prices — measured at 15% then 27.75% cumulative against a 15% cap,
        // with the authorizer and the verifier both clean. An agent comment on
        // the quote is what says this is round two.
        $harness = PipelineHarness::with([
            '{"line_changes":[{"line_item_id":"line-1","quantity":null,"target_unit_price":85,"remove":false}]}',
            '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":95}],"message":"95 each."}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('95 per unit?', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('95 each it is.', '2026-08-28 09:30:00'),
            NegotiationFixture::buyerComment('make it 85 per unit', '2026-08-28 10:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertNotContains('updateLineItems', $harness->gateway->calls, 'Round two must not write line prices.');
        self::assertNotNull(
            $harness->logger->contextOf('per-line ask reached a second round'),
            'The escalation must be the second-round guard, not some other refusal.',
        );
    }

    public function testEveryPassEndsInOneStructuredEventCarryingThePromptHashes(): void
    {
        // The hashes are how #22 attributes an outcome to the prompt versions
        // that produced it, and #19 reads exactly this event.
        $harness = PipelineHarness::with([
            '{"additional_discount_percent": 5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until '
                . NegotiationFixture::EXPIRES
                . '.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service($snapshot, $harness->gateway, NegotiationFixture::settings());
        $context = $harness->logger->contextOf('pass finished');

        self::assertNotNull($context, 'Every pass must emit one structured event.');
        self::assertSame('offered', $context['outcome']);
        self::assertSame('q1', $context['quoteId']);
        self::assertSame('sc1', $context['salesChannelId']);

        foreach (['extractPromptHash', 'negotiatePromptHash', 'replyPromptHash'] as $key) {
            self::assertIsString($context[$key], $key . ' must reach the log.');
            self::assertNotSame('', $context[$key]);
        }
    }
}
