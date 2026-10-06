<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * The gate for the one ask that arrives without a comment: a per-line target
 * the buyer typed into the storefront, which SwagCommercial keeps in
 * `quote_line_item.requested_price`.
 */
final class StructuredAskGateTest extends TestCase
{
    public function testAStructuredPriceAskIsAnsweredWithoutAComment(): void
    {
        // The storefront's per-line "Requested price" field is an ask on its
        // own: SwagCommercial writes it to `quote_line_item.requested_price`
        // and a buyer who uses it need not also type a comment. The pipeline
        // used to read the conversation as the only source of an ask and
        // return NothingToDo here, so a quote whose line said 98 against a
        // quoted 100 was recorded as "no action needed" and never answered.
        //
        // No extract call: there is no comment to interpret, and the ask is
        // already structured. Two calls, not three.
        $harness = PipelineHarness::with(
            [
                '{"action":"offer","message":"2% off.","terms":{"discountPercent":2}}',
                'We can do 2%.',
            ],
            reReadTotalNet: 980.0,
        );
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 98.0);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(2, $harness->spy->calls, 'A structured ask needs no extraction call.');
    }

    /**
     * QA-08's sibling: round two of a storefront negotiation. The agent
     * answered the buyer's comment; the buyer then lowered the line's
     * requested price and typed nothing, so the agent's reply is still the
     * newest comment. The pass wrote the new offer and then suppressed the
     * reply, because ReplyComposer looked for a newer COMMENT: the price
     * moved and the buyer was told nothing. A price write and a reply happen
     * together or not at all.
     */
    public function testARoundTwoStorefrontAskIsWrittenAndAnswered(): void
    {
        $harness = PipelineHarness::with([
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            PipelineHarness::rewordedReply(),
        ]);
        $snapshot = NegotiationFixture::snapshot(state: 'change_requested', requestedUnitPrice: 90.0, comments: [
            NegotiationFixture::buyerComment('what can you do on price?', '2026-09-24 09:00:00'),
            NegotiationFixture::agentComment('This quote stands at 1000.00 EUR.', '2026-09-24 09:05:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(2, $harness->spy->calls, 'No comment to extract: negotiate, then reply.');
        self::assertNotSame([], $harness->gateway->quoteUpdates, 'The offer was written.');
        self::assertSame([PipelineHarness::rewordedReply()], $harness->gateway->comments, 'And the buyer was told.');
        self::assertContains(QuoteTransition::Sent, $harness->gateway->transitions);
    }

    /**
     * The same round two, crashed between the offer write and the reply: the
     * line now reads 90 against a requested 90, so the ask is met and nothing
     * is open, and the agent's round-one reply is still the newest comment.
     * That looked like a reply stranded before its `sent`, and the retry sent
     * a written price with no word to the buyer. The line was written AFTER
     * the agent last spoke, so that reply is not this price's: the buyer is
     * answered, with the quote as it now stands.
     */
    public function testAnOfferWrittenAfterTheAgentLastSpokeIsAnsweredNotJustSent(): void
    {
        $written = NegotiationFixture::snapshot(state: 'in_review');
        $quote = static fn(string $lineWrittenAt): QuoteSnapshot => new QuoteSnapshot(
            identity: $written->identity,
            revision: $written->revision,
            totals: new QuoteTotals(totalNet: 900.0, totalGross: 900.0),
            lifecycle: $written->lifecycle,
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 10,
                unitPriceNet: 90.0,
                totalNet: 900.0,
                requestedUnitPrice: 90.0,
                updatedAt: new \DateTimeImmutable($lineWrittenAt),
            )], comments: [
                NegotiationFixture::buyerComment('what can you do on price?', '2026-09-24 09:00:00'),
                NegotiationFixture::agentComment('This quote stands at 900.00 EUR.', '2026-09-24 09:05:00'),
            ]),
        );

        // The reply landed after the write and only the `sent` died: that is
        // the stranded reply, finished without a second word.
        $harness = PipelineHarness::with([]);
        $outcome = $harness->pipeline->service(
            $quote('2026-09-24 09:04:00'),
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );
        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame([], $harness->gateway->comments);

        $harness = PipelineHarness::with([]);
        $snapshot = $quote('2026-09-24 09:10:00');

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame(
            [ReplyTemplate::acknowledges(900.0, 'EUR', $snapshot->lifecycle->expiresAt)],
            $harness->gateway->comments,
            'A written price went out without a reply.',
        );
        self::assertSame([QuoteTransition::Sent], $harness->gateway->transitions);
        self::assertSame(0, $harness->spy->calls, 'The answer is the template: no model call.');
    }

    public function testACommentPointingAtPricesAlreadyGrantedIsAcknowledged(): void
    {
        // The one empty extraction the extract prompt asks for BY NAME: "a
        // comment that merely POINTS at [a requested price] ... send
        // null/empty fields and NO clarificationQuestion". While the target is
        // unmet that is safe — isOpen() carries the pass and the appliers
        // read the line directly, which ClarificationGateTest pins.
        //
        // Once the target has been granted, the line reads 98 against a quoted
        // 98, so isOpen() is false and the same comment lands in #177's gate.
        // There is no concession left to make, and an escalation would hand a
        // human a quote nobody needs to look at.
        //
        // The known limit #180 pinned here is now answered: a comment that
        // merely points at an already-granted price gets the acknowledgement
        // that restates it, not silence.
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('can you do these prices?', '2026-08-28 09:00:00'),
        ], requestedUnitPrice: 100.0);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame(1, $harness->spy->calls, 'The extract call ran; nothing after it did.');
        // The record is what makes this reviewable at all: the row says which
        // comment was acknowledged, which is the whole point of #177's
        // buyer_ask column.
        self::assertSame('can you do these prices?', $harness->writer->drafts[0]->buyerAsk);
    }

    public function testAStructuredPriceThatIsNotBelowTheQuotedOneIsNotAnAsk(): void
    {
        // `requested_price` is sticky: it stays on the line after the agent has
        // answered it, and QuoteAutoReplyPricer only ever takes it as
        // `min(requested, quoted)`. Treating its mere presence as an ask would
        // make every later trigger on an answered quote look like new work.
        // An answered quote: a fresh one with no comment is acknowledged
        // instead (QA-02, NegotiationPipelineTest).
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(state: 'replied', requestedUnitPrice: 100.0, comments: [
            NegotiationFixture::agentComment('This quote stands at 1000.00 EUR.', '2026-09-24 09:05:00'),
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

    public function testAThanksAfterACounteredStructuredAskIsAcknowledged(): void
    {
        // The storefront asked 80 against 100 and the cap countered to 85:
        // SwagCommercial keeps `requested_price` at 80, so the line still asks
        // for less than it is quoted at, forever. The ask is ANSWERED all the
        // same — the last pass stamped its token — and a later "thanks" must
        // be acknowledged, not sent back to the band, where the write moves
        // nothing and escalates as no_further_concession with the quote stuck
        // in_review and the buyer unable to accept.
        $harness = PipelineHarness::with(['{}']);
        $answered = NegotiationFixture::snapshot(state: 'replied', totalNet: 850.0, requestedUnitPrice: 80.0);
        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(
                state: 'change_requested',
                totalNet: 850.0,
                requestedUnitPrice: 80.0,
                comments: [
                    NegotiationFixture::buyerComment("thanks, I'll take it", '2026-09-24 09:00:00'),
                ],
            ),
            [ServicingFingerprint::MARKER_KEY => ServicingFingerprint::stamp($answered, 'replied')],
        );

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Acknowledged, $outcome);
        self::assertSame(1, $harness->spy->calls, 'The extract call ran; nothing after it did.');
        self::assertSame(
            [ReplyTemplate::acknowledges(
                $snapshot->totals->buyerFacingTotal(),
                'EUR',
                $snapshot->lifecycle->expiresAt,
            )],
            $harness->gateway->comments,
        );
        self::assertSame([QuoteTransition::AdminResend], $harness->gateway->transitions);
        self::assertSame([], $harness->gateway->customFieldWrites, 'No escalation marker.');
    }

    public function testAStorefrontAskFarBeyondTheCounterCeilingEscalatesBeforeAnyModelCall(): void
    {
        // #223: 0.84 net requested against 727.23, no
        // comment, was granted 15% and ordered. Here: 1.00 against 100.00 is
        // a 99% ask; NegotiationFixture's settings cap at 10% and counter up
        // to 20%, so the band must escalate as discount_limit_exceeded.
        $harness = PipelineHarness::with([]);
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 1.0);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(0, $harness->spy->calls, 'The band decides before any model call.');
        self::assertSame(
            QuoteEscalationReason::DiscountLimitExceeded->value,
            $harness->writer->drafts[0]->escalationReason,
        );
    }

    public function testAStorefrontAskAtExactlyTheCapIsGrantedNotCountered(): void
    {
        // 15% off 865.40 gross is 618.14 net a unit: 15.0004% on 10 x 727.23
        // (7272.27), cent noise that used to land in the counter band and tell
        // the buyer their own figure was "countered".
        $quote = static fn(string $state, float $unit, float $total): QuoteSnapshot => new QuoteSnapshot(
            identity: NegotiationFixture::snapshot()->identity,
            revision: NegotiationFixture::snapshot()->revision,
            totals: new QuoteTotals(totalNet: $total, totalGross: $total),
            lifecycle: NegotiationFixture::snapshot(state: $state)->lifecycle,
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 10,
                unitPriceNet: $unit,
                totalNet: $total,
                requestedUnitPrice: 618.14,
            )], comments: []),
        );
        $harness = PipelineHarness::with([
            '{"action":"offer","message":"15% off.","terms":{"discountPercent":15}}',
            'We can do 15%.',
        ]);
        $harness->gateway->replaceSnapshots([
            $quote('in_review', 727.23, 7272.27),
            $quote('in_review', 618.14, 6181.43),
        ]);

        $outcome = $harness->pipeline->service(
            $quote('open', 727.23, 7272.27),
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0, counterOfferMaxPercent: 25.0),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame('grant', $harness->writer->drafts[0]->band);
    }
}
