<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
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
            '{"price":{"additionalDiscountPercent":15}}',
            '{"action":"offer","message":"We can do 10%, valid until 2026-09-11.","terms":{"discountPercent":10}}',
            PipelineHarness::rewordedReply(),
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
        // Asserted on the draft rather than the gateway, because the draft is
        // this file's subject -- and it is the same string either way.
        // `replyToBuyer` null-or-template is how a rejected rewording shows up
        // in the audit trail, and nothing else here would notice (#141).
        self::assertSame(PipelineHarness::rewordedReply(), $harness->writer->drafts[0]->replyToBuyer);
    }

    public function testANonPriceAskRecordsOneEscalatedRecord(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5},"negotiation":{"payment":{"requestedNetDays":30}}}',
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
        self::assertSame(
            QuoteEscalationReason::NonPriceTermRequested->value,
            $harness->writer->drafts[0]->escalationReason,
        );
    }

    public function testANoOfferProposedPassRecordsOneEscalatedRecord(): void
    {
        // OfferRound::play()'s $answer->offer === null branch: the model
        // itself declines via {"action":"escalate"} rather than proposing
        // something OfferAuthorizer then rejects. Distinguished from that
        // other null-offer branch (authorization rejected) by the
        // escalationReason recorded: ModelDeclined here (#222 -- the model
        // itself declining is its own reason, no longer folded into
        // ModelUnavailable), ProposalRejected there -- and by the call count:
        // exactly extract + negotiate, since a null offer never reaches
        // apply() or reply().
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"escalate","escalationReason":"Cannot serve this buyer."}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(2, $harness->spy->calls, 'A null offer must not reach apply() or reply().');
        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
        self::assertSame(
            QuoteEscalationReason::ModelDeclined->value,
            $harness->writer->drafts[0]->escalationReason,
            'Confirms the model-declined branch, not the authorization-rejected one.',
        );
    }

    /**
     * #49 removed the blanket second-round escalation; what still escalates a
     * per-line ask on a later round is the absence of a stored baseline on a
     * quote the servicing fingerprint says was already answered once. Without
     * the fingerprint marker this quote would now be answered, not escalated.
     */
    public function testASecondRoundPerLineAskOnAQuoteWithNoBaselineRecordsOneRecord(): void
    {
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","quantity":null,"targetUnitPrice":85,"remove":false}]}}',
            '{"action":"offer","message":"95 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":95}]}}',
        ]);
        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(comments: [
                NegotiationFixture::buyerComment('95 per unit?', '2026-08-28 09:00:00'),
                NegotiationFixture::agentComment('95 each it is.', '2026-08-28 09:30:00'),
                NegotiationFixture::buyerComment('make it 85 per unit', '2026-08-28 10:00:00'),
            ]),
            [ServicingFingerprint::MARKER_KEY => 'some-old-stamp'],
        );

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
        self::assertSame(QuoteEscalationReason::ProposalRejected->value, $harness->writer->drafts[0]->escalationReason);
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
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
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
