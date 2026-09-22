<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The buyer's own words on the pass that read them.
 *
 * #177 ends a pass whose extraction is empty in every field as `NothingToDo`,
 * and `ServiceQuoteHandler` stamps the servicing fingerprint whatever the
 * outcome — so a question a model mis-reads as empty is answered with silence,
 * once. That trade-off was accepted on the condition that those rows can be
 * reviewed, and until this column existed the row held the ask only in
 * `interpreted_asks`, which is exactly null on them.
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

        self::assertSame(NegotiationOutcome::NothingToDo, $outcome);
        self::assertSame(
            'Nice, thanks!',
            $harness->writer->drafts[0]->buyerAsk,
            'A nothing_to_do row that does not say which comment it passed over cannot be reviewed, '
            . 'and reviewing them is what makes answering with silence acceptable.',
        );
        self::assertNull(
            $harness->writer->drafts[0]->interpretedAsks['price']['additionalDiscountPercent'] ?? null,
            'The extraction is empty on exactly these rows -- which is why the raw comment has to be here.',
        );
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
