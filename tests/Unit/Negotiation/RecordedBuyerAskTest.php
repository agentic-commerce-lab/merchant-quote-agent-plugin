<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * What a pass leaves behind when it finds no ask in a buyer comment.
 *
 * #177 stops a pass whose extraction is empty in every field before the band,
 * and `ServiceQuoteHandler` stamps the servicing fingerprint whatever the
 * outcome. A read comment is now acknowledged -- the quote restated, no offer,
 * no escalation -- so a question a model mis-reads as empty gets a polite
 * restatement instead of an answer, once. A silent `nothing_to_do` is left for
 * a pass that read no comment, or one on an escalated quote. That trade-off
 * holds on the condition that those rows can be reviewed, and until this
 * column existed the row held the ask only in `interpreted_asks`, which is
 * exactly null on them.
 *
 * Two pieces of evidence, for two different readers: the record explains one
 * quote to someone already looking at it, and the log line is what makes the
 * RATE of these visible to someone who is not.
 */
final class RecordedBuyerAskTest extends TestCase
{
    public function testAPassThatFoundNoAskStillRecordsWhatItWasAsked(): void
    {
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
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
            'Nice, thanks!',
            $harness->writer->drafts[0]->buyerAsk,
            'A pass that found no ask must still say which comment it read, or the acknowledged rows cannot be reviewed.',
        );
        self::assertNull(
            $harness->writer->drafts[0]->interpretedAsks['price']['additionalDiscountPercent'] ?? null,
            'The extraction is empty on exactly these rows -- which is why the raw comment has to be here.',
        );
    }

    public function testAPassThatFoundNoAskSaysSoInTheLog(): void
    {
        // The audit row explains one quote to someone already looking at it.
        // Nothing counted how OFTEN the agent decides a comment holds no ask,
        // and that count is the only early warning there is: an over-escalating
        // agent is loud, a silent one is not, so an extract prompt that
        // regresses (#22 changes the prompt by design) shows up only as more
        // comments acknowledged instead of answered, and this log line is what
        // counts them.
        $harness = PipelineHarness::with(['{}']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('Nice, thanks!', '2026-09-18 09:58:40'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $context = $harness->logger->contextOf('Nothing to answer on this quote');
        self::assertNotNull(
            $context,
            'A read comment with no ask must be logged; this line is what counts how often the agent finds no ask.',
        );
        self::assertTrue(
            $context['commentRead'] ?? null,
            'A human wrote something and the agent found no ask in it. That is the case worth alerting on, '
            . 'and it has to be distinguishable from an ordinary duplicate trigger.',
        );
        self::assertTrue(
            $context['acknowledged'] ?? null,
            'The count that matters is still countable once the comment is answered: '
            . 'acknowledged separates a read comment the agent restated the quote for from a silent pass.',
        );
        self::assertArrayNotHasKey(
            'comment',
            $context,
            'The buyer\'s words belong in the audit record, not in the shop\'s log files.',
        );
    }

    public function testADuplicateTriggerIsTheSameLineWithTheFlagDown(): void
    {
        // Same message, `commentRead` false: no comment was read at all, so
        // this is the re-trigger the fingerprint usually stops earlier, and it
        // must not inflate the count that matters.
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

        self::assertFalse($harness->logger->contextOf('Nothing to answer on this quote')['commentRead'] ?? null);
        self::assertFalse($harness->logger->contextOf('Nothing to answer on this quote')['acknowledged'] ?? null);
    }

    public function testAModelFailureStillCarriesTheQuestionItFailedOn(): void
    {
        // Recorded BEFORE the extract call, which is the whole reason the
        // recording sits where it does: a pass that escalates because the
        // model was unreachable is precisely when a human needs to read what
        // the buyer asked.
        $harness = PipelineHarness::with(['not json at all']);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('can you do 5% on the widgets?', '2026-09-18 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame('can you do 5% on the widgets?', $harness->writer->drafts[0]->buyerAsk);
    }

    public function testAPassThatReadNoCommentRecordsNothing(): void
    {
        // A structured per-line ask arrives with no comment at all, so there
        // is no buyer text to record and inventing one would be the audit
        // trail claiming something that never happened. The null here says
        // the same thing the null `extractPromptHash` beside it does.
        $harness = PipelineHarness::with(
            [
                '{"action":"offer","message":"2% off.","terms":{"discountPercent":2}}',
                'We can do 2%.',
            ],
            reReadTotalNet: 980.0,
        );
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 98.0);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertNull($harness->writer->drafts[0]->buyerAsk);
        self::assertNull($harness->writer->drafts[0]->extractPromptHash);
    }
}
