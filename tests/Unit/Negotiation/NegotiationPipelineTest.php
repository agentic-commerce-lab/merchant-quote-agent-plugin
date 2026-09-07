<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

final class NegotiationPipelineTest extends TestCase
{
    public function testAnInBandAskIsOffered(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off, valid until 2026-09-11.","terms":{"discountPercent":5}}',
            'We can offer 5% off. Valid until 2026-09-11.',
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
        self::assertSame(3, $harness->spy->calls);
    }

    public function testAnOutOfAuthorityAskEscalatesAfterOneCall(): void
    {
        // The whole point of the gating: the band already knows 40% is out of
        // reach, so the negotiate and reply calls are never paid for.
        $harness = PipelineHarness::with(['{"price":{"additionalDiscountPercent":40}}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('40% off or no deal', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'An out-of-authority ask must not reach the model twice.');
        self::assertContains('addComment', $harness->gateway->calls, 'The escalation must still reach a human.');
        // What the gate actually buys. Without it the proposer's own
        // "no priced band decision" guard still keeps the call count at one,
        // but the reason degrades to needs_human_review — and QuoteEscalator
        // keys its once-per-quote marker on the reason, so the buyer's
        // de-duplication and the merchant's log both change.
        self::assertSame(
            [[QuoteEscalator::MARKER_KEY => QuoteEscalationReason::DiscountLimitExceeded->value]],
            $harness->gateway->customFieldWrites,
            'The band gate must escalate with the price reason, not a generic one.',
        );
        // The band gate escalates through OfferRound::escalated(), which
        // holds no recorder of its own — the deterministic gate's own verdict
        // must still reach the audit trail, not just the buyer-facing marker.
        self::assertSame(
            QuoteEscalationReason::DiscountLimitExceeded->value,
            $harness->writer->drafts[0]->escalationReason,
        );
    }

    public function testAnAskInTheCounterBandIsCountered(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":15}}',
            '{"action":"offer","message":"We can do 10%, valid until 2026-09-11.","terms":{"discountPercent":10}}',
            'Our best is 10%. Valid until 2026-09-11.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('15% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Countered, $outcome);
    }

    public function testNothingNewCostsNothingAndWritesNothing(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame(0, $harness->spy->calls);
        self::assertSame([], $harness->gateway->calls);
    }

    public function testAReplyPostedByADeadPassStillReachesReplied(): void
    {
        // #31: the reply is two writes — the comment, then the `sent`
        // transition. A worker dying between them left the quote in
        // `in_review` for good: the retry reads the agent's own comment as the
        // newest, AskInterpreter returns null, and the pipeline used to stop
        // before anything could finish the transition. The buyer held a
        // correct, verified offer against a quote that still said "in review".
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(state: 'in_review', comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-08-28 09:30:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame([QuoteTransition::Sent], $harness->gateway->transitions);
        self::assertSame([], $harness->gateway->comments, 'The buyer must not be answered a second time.');
        self::assertSame(0, $harness->spy->calls, 'Finishing a transition must not cost a model call.');
    }

    public function testAStructuralAskEscalatesRatherThanBeingSilentlyDropped(): void
    {
        // Nothing in the policy layer acts on a quantity change or a removal:
        // CommentLineTargets reads lineChanges only for target PRICES, and
        // addProducts is read nowhere at all. Without this guard the agent
        // would answer about price while the buyer's actual ask — more units —
        // vanished. Changing what is being sold is outside a price mandate,
        // and addProduct on a variant product still segfaults the worker (#3).
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","quantity":20,"targetUnitPrice":null,"remove":false}]}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('make it 20 units', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'A structural ask must not reach the negotiate call.');
    }

    public function testAPerLineTargetPriceIsNotStructuralAndStillNegotiates(): void
    {
        // The discriminator: a line change carrying only a target PRICE is a
        // price ask and squarely in the mandate.
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","quantity":null,"targetUnitPrice":95,"remove":false}]}}',
            '{"action":"offer","message":"95 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":95}]}}',
            '95 each, valid until 2026-09-11.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('95 per unit?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertNotSame(NegotiationOutcome::Escalated, $outcome);
    }

    public function testAModelFailureEscalatesRatherThanFallingBackToRules(): void
    {
        $harness = PipelineHarness::with(['this is not json']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertContains('addComment', $harness->gateway->calls);
        // ModelUnavailable is caught inside run(), so $error never reaches
        // record() and errorClass/errorChain stay null — without this reason
        // reaching the draft, a provider outage is indistinguishable from any
        // other escalation in the failure-count-by-cause readout.
        self::assertSame(QuoteEscalationReason::ModelUnavailable->value, $harness->writer->drafts[0]->escalationReason);
    }

    public function testAVerificationFailureEscalatesAndLeavesTheChangesInPlace(): void
    {
        $harness = PipelineHarness::with(
            [
                '{"price":{"additionalDiscountPercent":5}}',
                '{"action":"offer","message":"ok","terms":{"discountPercent":5}}',
            ],
            reReadTotalNet: 400.0,
        );
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertContains('updateQuote', $harness->gateway->calls, 'The applied changes must not be rolled back.');
        self::assertCount(1, $harness->writer->drafts);
        self::assertSame('escalated', $harness->writer->drafts[0]->outcome);
    }
}
