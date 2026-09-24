<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DecisionRecorderDraftTest extends TestCase
{
    public function testADraftedOfferIsRecordedAsPendingWithItsVersion(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft('0190aaaa0000700080000000000000aa', 'open|1|x');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame('pending', $writer->drafts[0]->reviewStatus);
        self::assertSame('0190aaaa0000700080000000000000aa', $writer->drafts[0]->draftVersionId);
        self::assertSame('open|1|x', $writer->drafts[0]->reviewFingerprint);
    }

    public function testAClarificationDraftHasNoVersionButIsStillPending(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft(null, 'open|1|x');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Clarified));

        self::assertSame('pending', $writer->drafts[0]->reviewStatus);
        self::assertNull($writer->drafts[0]->draftVersionId);
    }

    /** The version of an escalated pass is deleted by the pipeline; the row must not point at it. */
    public function testAnEscalatedPassKeepsNoDraft(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft('0190aaaa0000700080000000000000aa', 'open|1|x');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Escalated));

        self::assertNull($writer->drafts[0]->reviewStatus);
        self::assertNull($writer->drafts[0]->draftVersionId);
        self::assertNull($writer->drafts[0]->reviewFingerprint);
    }

    public function testAFailedPassKeepsNoDraft(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft('0190aaaa0000700080000000000000aa', 'open|1|x');
        $recorder->finish(null, new \RuntimeException('boom'));

        self::assertNull($writer->drafts[0]->reviewStatus);
        self::assertNull($writer->drafts[0]->draftVersionId);
    }

    public function testTheFirstFingerprintWinsAndALaterNullVersionKeepsTheVersion(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft('0190aaaa0000700080000000000000aa', 'first');
        $recorder->recordDraft(null, 'second');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame('first', $writer->drafts[0]->reviewFingerprint);
        self::assertSame('0190aaaa0000700080000000000000aa', $writer->drafts[0]->draftVersionId);
    }

    public function testAnAutonomousPassHasNoReviewStatus(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertNull($writer->drafts[0]->reviewStatus);
    }

    /** @return array{0: DecisionRecorder, 1: FakeDecisionWriter} */
    private static function recorder(): array
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(QuoteSnapshotFixture::snapshot(), new PassContext(ServicingTriggerReason::cases()[0], 0));

        return [$recorder, $writer];
    }
}
