<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use PHPUnit\Framework\TestCase;

/**
 * One record per pass, on every path. The paths are the point: a record that
 * only appears when the agent succeeds tells #21 nothing about why the other
 * 3,000 quotes did not get an offer.
 */
final class RecordedPassTest extends TestCase
{
    /** The extract prompt is a fixed literal in every harness; its hash is deterministic. */
    private const EXTRACT_HASH = '4080383490419ffce52d9a66c5fac20b0ef345d963019d3ce63bff32b76a5e6b';

    public function testAnOfferedPassWritesOneRecordCarryingWhatTheBuyerWasTold(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            PipelineHarness::rewordedReply(),
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertCount(1, $harness->writer->drafts);

        $draft = $harness->writer->drafts[0];
        self::assertSame('offered', $draft->outcome);
        self::assertSame('grant', $draft->band);
        self::assertSame('q1', $draft->quoteId);
        self::assertSame(1000.0, $draft->totalNetBefore);
        self::assertIsInt($draft->durationMs);
        // The clause in this test's name, actually checked: the record has to
        // carry the sentence the buyer received, not merely exist.
        self::assertSame(PipelineHarness::rewordedReply(), $draft->replyToBuyer);
    }

    public function testAnOfferedPassRecordsEveryStageItPassedThrough(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            PipelineHarness::rewordedReply(),
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

        $draft = $harness->writer->drafts[0];

        self::assertNotNull($draft->interpretedAsks, 'AskInterpreter did not record.');
        self::assertNotNull($draft->rawProposal, 'OfferProposer did not record the raw answer.');
        self::assertTrue($draft->authorized, 'OfferProposer did not record the authorization result.');
        self::assertTrue($draft->verified, 'OfferApplier did not record the verification result.');
        self::assertNotNull($draft->writes, 'OfferApplier did not record what it wrote.');
        self::assertContains('recalculate', $draft->writes);
        self::assertSame(950.0, $draft->totalNetAfter);
        self::assertNotNull($draft->replyToBuyer);
        self::assertStringContainsString('%', $draft->replyToBuyer);
    }

    public function testAModelOutageAtTheNegotiateCallStillCarriesTheExtractHash(): void
    {
        // recordAsk() now runs before the negotiate call, so the extract hash
        // is on the draft by the time the second call fails. The empty string
        // reply is the mechanism ScriptedClient actually offers for a
        // non-transient failure: ModelPlatform::content() throws
        // ModelUnavailable directly on empty message content, with no retry
        // and no GuzzleException involved (unlike an omitted queue entry,
        // which throws OutOfBoundsException instead).
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertCount(1, $harness->writer->drafts);
        $draft = $harness->writer->drafts[0];
        self::assertSame('escalated', $draft->outcome);
        self::assertSame(
            self::EXTRACT_HASH,
            $draft->extractPromptHash,
            'The extract hash recorded before the outage should survive it.',
        );
    }

    public function testAStructuralAskEscalationRecordsTheExtractHashOnly(): void
    {
        // The transposition #22 needs to depend on: the structural-ask path
        // never calls negotiate, so a swapped hash assignment would put the
        // extract hash on negotiatePromptHash instead of leaving it null.
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","quantity":20,"targetUnitPrice":null,"remove":false}]}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('make it 20 units', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        $draft = $harness->writer->drafts[0];
        self::assertSame('escalated', $draft->outcome);
        self::assertSame(self::EXTRACT_HASH, $draft->extractPromptHash);
        self::assertNull($draft->negotiatePromptHash);
    }

    public function testABandEscalationAlsoRecordsTheExtractHashOnly(): void
    {
        // Same transposition risk on the other gate that escalates before
        // negotiate is ever called: the price band, not the ask's shape.
        $harness = PipelineHarness::with(['{"price":{"additionalDiscountPercent":40}}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('40% off or no deal', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        $draft = $harness->writer->drafts[0];
        self::assertSame('escalated', $draft->outcome);
        self::assertSame(self::EXTRACT_HASH, $draft->extractPromptHash);
        self::assertNull($draft->negotiatePromptHash);
        // Also the only coverage of the band/maxDiscountPercent fields
        // recordDecision() writes -- the band that refused the ask, and the
        // authority it was measured against.
        self::assertSame('escalate', $draft->band);
        self::assertSame(10.0, $draft->maxDiscountPercent);
    }

    public function testANothingToDoPassStillWritesARecord(): void
    {
        // #21 needs to distinguish "the agent decided not to act" from "the
        // agent never ran". Without a record for this path they look the same.
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('nothing_to_do', $harness->writer->drafts[0]->outcome);
    }

    public function testAModelFailureWritesARecordWithTheEscalatedOutcome(): void
    {
        // An unusable extract answer is the deterministic way to reach
        // ModelUnavailable now that settings always carry model access: the
        // interpreter cannot map it and raises, unlike an empty ScriptedClient
        // queue, which throws LogicException the pipeline does not catch.
        $harness = PipelineHarness::with(['not json at all']);
        $settings = NegotiationFixture::settings();
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, $settings, NegotiationFixture::context());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
    }

    public function testAnAuditWriteFailureDoesNotFailThePass(): void
    {
        // A thrown audit write would roll the message back into Messenger's
        // retry and re-answer the buyer. A missing record beats a duplicated
        // buyer message.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            'We can offer 5% off.',
        ]);
        $harness->writer->throws = new \RuntimeException('the database is on fire');
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertNotNull($harness->logger->contextOf('could not be recorded'));
        // FakeDecisionWriter appends before it throws, so this pins that the
        // writer was invoked with a draft on this path -- it does not prove
        // the writer would still append if it threw before appending.
        self::assertCount(1, $harness->writer->drafts);
    }

    public function testALoggerFailureDoesNotFailASuccessfulPass(): void
    {
        // record() runs inside a finally; a throw there would replace a
        // successful return with a failure, and a broken logger must not be
        // able to do that.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            PipelineHarness::rewordedReply(),
        ]);
        $harness->logger->throws = new \RuntimeException('the logger is broken');
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% off?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        // Pins that the writer was invoked with a draft on this path; not
        // proof it would survive a writer that throws before appending.
        self::assertCount(1, $harness->writer->drafts);
    }

    public function testALoggerFailureDoesNotReplaceTheOriginalExceptionOnAFailedPass(): void
    {
        // The direction that actually matters: PHP replaces an in-flight
        // exception with whatever a finally throws. Without the inner
        // try/catch in record(), the caller -- and Messenger's retry --
        // would see the logger's failure instead of the real one.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            PipelineHarness::rewordedReply(),
        ]);
        $harness->logger->throws = new \RuntimeException('the logger is broken');
        $gatewayFailure = new \RuntimeException('the gateway is down');
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
        } catch (\Throwable $e) {
            self::assertSame($gatewayFailure, $e);
        }

        // Pins that the writer was invoked with a draft on this path; not
        // proof it would survive a writer that throws before appending.
        self::assertCount(1, $harness->writer->drafts);
    }
}
