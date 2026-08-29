<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

/**
 * Per-outcome path coverage: one record with the right outcome for each way
 * a pass can end. RecordedPassTest owns the pass-boundary mechanics --
 * begin/finish, audit-write failure, logger failure -- this class owns the
 * remaining outcomes so the claim "every path writes exactly one record"
 * (#18's done-when clause) is evidenced path by path rather than asserted.
 */
final class RecordedOutcomePathsTest extends TestCase
{
    public function testACounteredPassRecordsTheCounterBand(): void
    {
        $harness = PipelineHarness::with([
            '{"additional_discount_percent": 15}',
            '{"action":"offer","discount_percent":10,"message":"We can do 10%, valid until 2026-09-11."}',
            'Our best is 10%. Valid until 2026-09-11.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('15% please', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('countered', $harness->writer->drafts[0]->outcome);
        self::assertSame('counter', $harness->writer->drafts[0]->band);
    }

    public function testANonPriceAskRecordsOneEscalatedRecord(): void
    {
        $harness = PipelineHarness::with([
            '{"additional_discount_percent":5,"negotiation":{"payment":{"requested_net_days":30}}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% and net 30?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
        self::assertNotNull($harness->writer->drafts[0]->interpretedAsks);
    }

    public function testAnOutOfAuthorityAskRecordsTheBandThatRefusedIt(): void
    {
        // Same escalate-on-band branch RecordedPassTest already exercises at
        // 40%; kept at 80% because the point here is the band/maxDiscountPercent
        // fields recordDecision() writes, which no existing test asserts.
        $harness = PipelineHarness::with(['{"additional_discount_percent":80}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('80% off?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalate', $harness->writer->drafts[0]->band);
        self::assertSame(10.0, $harness->writer->drafts[0]->maxDiscountPercent);
    }

    public function testASecondRoundPerLineAskRecordsOneRecord(): void
    {
        $harness = PipelineHarness::with([
            '{"line_changes":[{"line_item_id":"line-1","quantity":null,"target_unit_price":85,"remove":false}]}',
            '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":95}],"message":"95 each."}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('95 per unit?', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('95 each it is.', '2026-08-28 09:30:00'),
            NegotiationFixture::buyerComment('make it 85 per unit', '2026-08-28 10:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
    }

    public function testAThrownGatewayFailureStillWritesARecordAndRethrows(): void
    {
        // The finally is the whole point: a pass that dies mid-write is exactly
        // the pass a merchant most needs a record of. transitionThrows is the
        // gateway's existing throw-once-then-clear hook (updateQuoteThrows
        // does not exist), and OfferApplier::claim() calls transition() first,
        // before any other write -- uncaught here since claim() only catches
        // IllegalTransitionException.
        $harness = PipelineHarness::with([
            '{"additional_discount_percent":5}',
            '{"action":"offer","discount_percent":5,"message":"5% off."}',
        ]);
        $gatewayFailure = new \RuntimeException('the shop is down');
        $harness->gateway->transitionThrows = $gatewayFailure;
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        try {
            $harness->pipeline->service(
                $snapshot,
                $harness->gateway,
                NegotiationFixture::settings(),
                NegotiationFixture::context(),
            );
            self::fail('The gateway failure should have propagated.');
        } catch (\RuntimeException $e) {
            self::assertSame($gatewayFailure, $e);
        }

        self::assertCount(1, $harness->writer->drafts);
        self::assertNull($harness->writer->drafts[0]->outcome, 'A thrown pass has no outcome.');
        self::assertSame(\RuntimeException::class, $harness->writer->drafts[0]->errorClass);
        self::assertNotNull($harness->writer->drafts[0]->errorChain);
    }
}
