<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\PendingEscalation;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

/**
 * @mago-expect lint:too-many-methods
 * Eighteen cases, one per branch of NegotiationPipeline::negotiate() and the
 * outcomes it can return -- the count grows with the pipeline's own branches,
 * not with unrelated concerns that belong in a separate class. Same shape as
 * the existing suppression on QuoteBaselineTest (fifteen cases plus six
 * private helpers), so a later reader can tell this kind of growth, tied
 * one-for-one to the thing under test, from a class that should be split.
 */
final class NegotiationPipelineTest extends TestCase
{
    public function testAnInBandAskIsOffered(): void
    {
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off, valid until 2026-09-11.","terms":{"discountPercent":5}}',
            PipelineHarness::rewordedReply(),
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
        // Not just "a comment was posted": the model's rewording, exactly.
        // The deterministic template arriving here instead is not an error --
        // it is RewordingGuard rejecting the script, silently, with the
        // outcome and the call count above unchanged (#141).
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
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
        self::assertContains('updateQuote', $harness->gateway->calls, 'The escalation must still mark the quote.');
        self::assertNotContains('addComment', $harness->gateway->calls, 'Escalation is silent by default.');
        // What the gate actually buys. Without it the proposer's own
        // "no priced band decision" guard still keeps the call count at one,
        // but the reason degrades to needs_human_review — and QuoteEscalator
        // keys its once-per-quote marker on the reason, so the buyer's
        // de-duplication and the merchant's log both change.
        //
        // The mirror write comes first: a quote-wide 40% ask has no line of
        // its own, so AskMirror distributes it across the fixture's one line
        // (100.00 * 0.6 = 60.00) before the gate ever runs (#165).
        $writes = $harness->gateway->customFieldWrites;
        self::assertCount(2, $writes);
        self::assertSame([MirroredAsks::KEY => ['line-1' => 60.0]], $writes[0]);
        self::assertSame(
            QuoteEscalationReason::DiscountLimitExceeded->value,
            $writes[1][QuoteEscalator::MARKER_KEY] ?? null,
            'The band gate must escalate with the price reason, not a generic one.',
        );
    }

    public function testAnOutOfAuthorityAskNotifiesBuyerWhenConfigured(): void
    {
        $harness = PipelineHarness::with(['{"price":{"additionalDiscountPercent":40}}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('40% off or no deal', '2026-08-28 09:00:00'),
        ]);

        $settings = NegotiationFixture::settings(notifyBuyerOnEscalation: true);
        if ($harness->buyerNotification !== null) {
            $harness->buyerNotification->notify = true;
        }

        $outcome = $harness->pipeline->service($snapshot, $harness->gateway, $settings, NegotiationFixture::context());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertContains('addComment', $harness->gateway->calls);
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
            PipelineHarness::rewordedReply(),
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
        // 5% and 950.00, not the 10% the counter offered: the reply states
        // what the DATABASE came down by (ReplyTemplate::reduction over the
        // harness's re-read), and RewordingGuard rejects any other figure.
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
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

    public function testAnExtractionWithNoAskInAnyFieldAcknowledgesTheBuyer(): void
    {
        // #177: "Nice, thanks!" -- every field null or empty. The
        // pass must not reach the band (no unsolicited offer) and must not
        // escalate (#167). It must still ANSWER: the
        // comment moved the quote to change_requested, only a reply moves it
        // back to replied, and over UCP the buyer can neither accept nor
        // counter until it does.
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::snapshot(state: 'change_requested', comments: [
            NegotiationFixture::buyerComment('Nice, thanks!', '2026-09-18 09:58:40'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame(
            1,
            $harness->spy->calls,
            'The extract call still happens -- the extractor is what found nothing; negotiate and reply must not.',
        );
        self::assertSame(
            [ReplyTemplate::acknowledges(
                $snapshot->totals->buyerFacingTotal(),
                'EUR',
                $snapshot->lifecycle->expiresAt,
            )],
            $harness->gateway->comments,
        );
        self::assertSame([QuoteTransition::AdminResend], $harness->gateway->transitions);
        self::assertSame([], $harness->gateway->lineItemChanges, 'An acknowledgement writes nothing to prices.');
        self::assertSame([], $harness->gateway->quoteUpdates, 'Nor to the quote itself: no discount, no expiry.');
    }

    public function testAnAcknowledgementQuotesTheGrossTotalTheBuyerReads(): void
    {
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::grossSnapshot(comments: [
            NegotiationFixture::buyerComment('Apply the disconut to the whole quote', '2026-09-23 12:43:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString('stands at 1000.00 EUR', $harness->gateway->comments[0] ?? '');
    }

    public function testAnEmptyExtractionOnAFreshQuoteIsSentAtItsCurrentPrices(): void
    {
        // "Every read comment" is the scope the user chose: an RFQ whose
        // comment asks for nothing is sent as quoted -- always inside the
        // merchant's authority, and what the buyer asked for.
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::snapshot(state: 'open', comments: [
            NegotiationFixture::buyerComment('Please send me a quote.', '2026-09-23 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame([QuoteTransition::Sent], $harness->gateway->transitions);
    }

    public function testAnEscalatedQuoteStandsDownEvenOnARealPriceAsk(): void
    {
        // A human owns this quote until they send it. Running the pipeline
        // again escalated the same quote two and three times in PM testing,
        // and under a different reason told the buyer a second time.
        $harness = PipelineHarness::with(self::fivePercentOffer());
        $snapshot = self::escalated(sentAt: null, comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-23 10:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::HandedOver, $outcome);
        self::assertSame(0, $harness->spy->calls, 'An escalation awaiting a human must not pay for a model call.');
        self::assertSame([], $harness->gateway->calls, 'Standing down writes nothing at all.');
    }

    public function testAnEscalatedQuoteAHumanHasSentIsNegotiatedAgain(): void
    {
        $harness = PipelineHarness::with(self::fivePercentOffer());
        $snapshot = self::escalated(sentAt: '2026-09-23 10:00:00', comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-23 11:00:00'),
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

    public function testAnEscalatedQuoteAHumanHasAnsweredIsAcknowledged(): void
    {
        // The merchant sent an answer after the escalation, so the terms on
        // the quote are theirs and a receipt for them is true. Silence here
        // parked the quote in change_requested, where over UCP the buyer can
        // neither accept nor counter -- the same dead end again.
        $harness = PipelineHarness::with(['{}']);
        $escalated = NegotiationFixture::snapshot(state: 'change_requested', comments: [
            NegotiationFixture::buyerComment('ok, thanks', '2026-09-23 11:00:00'),
        ]);
        $snapshot = new QuoteSnapshot(
            identity: $escalated->identity,
            revision: $escalated->revision,
            totals: $escalated->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'change_requested',
                customFields: [
                    QuoteEscalator::MARKER_KEY => 'discount_limit_exceeded',
                    PendingEscalation::ESCALATED_AT_KEY => (new \DateTimeImmutable('2026-09-23 09:00:00'))->format(
                        'U.u',
                    ),
                ],
                lastAdminTransitionAt: new \DateTimeImmutable('2026-09-23 10:00:00'),
                lastAdminTransitionTo: 'replied',
            ),
            content: $escalated->content,
        );

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame([QuoteTransition::AdminResend], $harness->gateway->transitions);
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

    public function testAQuoteAHumanLeftInReviewIsNotTransitionedByUs(): void
    {
        // A merchant opened this quote in the administration and left it in
        // in_review. Nothing here is the agent's: no agent comment, so no
        // reply of ours was ever stranded.
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(state: 'in_review', comments: []);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame([], $harness->gateway->transitions, "A human's quote is not ours to move.");
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
        self::assertSame(
            QuoteEscalationReason::StructuralChangeRequested->value,
            $harness->writer->drafts[0]->escalationReason,
        );
    }

    public function testAPerLineTargetPriceIsNotStructuralAndStillNegotiates(): void
    {
        // The discriminator: a line change carrying only a target PRICE is a
        // price ask and squarely in the mandate.
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","quantity":null,"targetUnitPrice":95,"remove":false}]}}',
            '{"action":"offer","message":"95 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":95}]}}',
            PipelineHarness::rewordedReply(),
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
        // A per-line concession is announced as the quote-wide reduction the
        // database reports; `95` is a figure the template never wrote, and a
        // reply stating it falls back to the template without failing here.
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments);
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
        self::assertContains('updateQuote', $harness->gateway->calls);
        self::assertNotContains('addComment', $harness->gateway->calls, 'Escalation is silent by default.');
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

    public function testAMerchantWhoAnsweredFirstStopsThePassBeforeAnyModelCall(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            new QuoteComment(
                'called them, sending a revised offer',
                createdById: 'admin-1',
                createdAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            ),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::HandedOver, $outcome);
        self::assertSame(0, $harness->spy->calls, 'A human has this quote; the agent must not pay for a model call.');
        self::assertSame([], $harness->gateway->comments, 'The buyer must not hear from the agent as well.');
        self::assertSame([], $harness->gateway->calls, 'Standing down writes nothing at all.');
    }

    public function testTheBuyerComingBackAfterTheMerchantIsServicedAsAlways(): void
    {
        // The same three-reply script as testAnAskInTheCounterBandIsCountered:
        // the point is that a merchant's note older than the buyer's ask
        // changes nothing about a pass that would otherwise run.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":15}}',
            '{"action":"offer","message":"We can do 10%, valid until 2026-09-11.","terms":{"discountPercent":10}}',
            PipelineHarness::rewordedReply(),
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            new QuoteComment(
                'sent a revised offer',
                createdById: 'admin-1',
                createdAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            ),
            NegotiationFixture::buyerComment('still too expensive', '2026-09-16 11:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(
            NegotiationOutcome::Countered,
            $outcome,
            'A newer buyer ask re-enables the agent; this is not a permanent handover.',
        );
        self::assertSame(3, $harness->spy->calls);
    }

    public function testTheStandDownIsRecordedAsItsOwnOutcome(): void
    {
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            new QuoteComment(
                'mine now',
                createdById: 'admin-1',
                createdAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            ),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame('handed_over', $harness->writer->drafts[0]->outcome);
    }

    /** @return list<string> */
    private static function fivePercentOffer(): array
    {
        return [
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off, valid until 2026-09-11.","terms":{"discountPercent":5}}',
            PipelineHarness::rewordedReply(),
        ];
    }

    /**
     * Escalated at 2026-09-23 09:00, and sent by a merchant since when $sentAt is given.
     *
     * @param list<QuoteComment> $comments
     */
    private static function escalated(?string $sentAt, array $comments): QuoteSnapshot
    {
        $quote = NegotiationFixture::snapshot(state: 'change_requested', comments: $comments);

        return new QuoteSnapshot(
            identity: $quote->identity,
            revision: $quote->revision,
            totals: $quote->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'change_requested',
                customFields: [
                    QuoteEscalator::MARKER_KEY => QuoteEscalationReason::DiscountLimitExceeded->value,
                    PendingEscalation::ESCALATED_AT_KEY => (new \DateTimeImmutable('2026-09-23 09:00:00'))->format(
                        'U.u',
                    ),
                ],
                lastAdminTransitionAt: $sentAt === null ? null : new \DateTimeImmutable($sentAt),
                lastAdminTransitionTo: $sentAt === null ? null : 'replied',
            ),
            content: $quote->content,
        );
    }
}
