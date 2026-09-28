<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Negotiation\AppliedOffer;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteAutoReplyDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @mago-expect lint:too-many-methods
 * One test per thing DecisionRecorder can be asked to do, plus recordRefusal()
 * -- the second way a row gets written, alongside begin()/finish() -- and its
 * one real risk, that it disturbs a pass already in flight. Splitting the
 * class would separate assertions that are only meaningful read together.
 */
final class DecisionRecorderTest extends TestCase
{
    public function testAFinishedPassIsHandedToTheWriterOnce(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered, 'extract-hash'));

        self::assertCount(1, $writer->drafts);
        self::assertSame('offered', $writer->drafts[0]->outcome);
        self::assertSame('extract-hash', $writer->drafts[0]->extractPromptHash);
        self::assertSame('q1', $writer->drafts[0]->quoteId);
    }

    public function testASecondPassDoesNotInheritTheFirstPassDraft(): void
    {
        // The one real risk in a mutable recorder living in a long-running
        // worker: a leaked draft answering for the next quote. begin() resets
        // unconditionally, and this is what proves it.
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordReply('We can do 5%.', 'reply-hash');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->finish(new NegotiationPass(NegotiationOutcome::NothingToDo));

        self::assertCount(2, $writer->drafts);
        self::assertSame('We can do 5%.', $writer->drafts[0]->replyToBuyer);
        self::assertNull($writer->drafts[1]->replyToBuyer, 'Pass one leaked into pass two.');
        self::assertNull($writer->drafts[1]->replyPromptHash);
    }

    public function testFinishWithoutBeginWritesNothing(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame([], $writer->drafts);
    }

    public function testAThrownPassRecordsTheErrorChainAndNoOutcome(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $error = new \RuntimeException('outer', previous: new \LogicException('inner'));

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->finish(null, $error);

        $draft = $writer->drafts[0];
        self::assertNull($draft->outcome);
        self::assertSame(\RuntimeException::class, $draft->errorClass);
        self::assertCount(2, $draft->errorChain ?? []);
        self::assertSame('inner', $draft->errorChain[1]['message']);
    }

    public function testTheDurationIsRecorded(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertIsInt($writer->drafts[0]->durationMs);
        self::assertGreaterThanOrEqual(0, $writer->drafts[0]->durationMs);
    }

    /**
     * The numerator of #21's "average granted discount against the band"
     * readout: nothing populates it unless recordApplied() measures it off
     * the database totals, the same two numbers the buyer's reply is built
     * from.
     */
    public function testAGrantingPassRecordsTheRealDiscountPercentage(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(totalNet: 1000.0), self::context());
        $recorder->recordApplied(
            new AppliedOffer(true, [], NegotiationFixture::snapshot(totalNet: 950.0), 1000.0),
            ['updateQuote'],
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame(5.0, $writer->drafts[0]->discountPercentGranted);
    }

    /**
     * The strategy version is the prompt actually sent, stamped alongside
     * maxDiscountPercent -- the same "record what the configuration was"
     * step, at its call site in NegotiationPipeline.
     */
    public function testRecordDecisionCarriesTheStrategyVersionOntoTheDraft(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordDecision(self::grantDecision(), 10.0, 'feedfacefeedfacefeedfacefeedface');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame('feedfacefeedfacefeedfacefeedface', $writer->drafts[0]->strategyVersionId);
    }

    public function testRecordDecisionWithoutAStrategySelectedLeavesTheColumnNull(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordDecision(self::grantDecision(), 10.0);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertNull($writer->drafts[0]->strategyVersionId);
    }

    /**
     * Which rung assigned the strategy is what makes a fall-through visible
     * to a merchant -- see StrategyAssignmentSource. Stored as its scalar
     * value, alongside the version id it explains, at the same call site.
     */
    public function testRecordDecisionCarriesTheAssignmentSourceOntoTheDraft(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordDecision(
            self::grantDecision(),
            10.0,
            'feedfacefeedfacefeedfacefeedface',
            StrategyAssignmentSource::Rule,
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame('rule', $writer->drafts[0]->strategyAssignmentSource);
    }

    public function testRecordDecisionWithoutAnAssignmentSourceLeavesTheColumnNull(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordDecision(self::grantDecision(), 10.0, 'feedfacefeedfacefeedfacefeedface');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertNull($writer->drafts[0]->strategyAssignmentSource);
    }

    public function testARefusalBeforeAnyPassWritesOneEscalatedRecord(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->recordRefusal(
            NegotiationFixture::snapshot(),
            self::context(),
            QuoteEscalationReason::NotConfigured,
            ['No LLM API key is set.'],
        );

        self::assertCount(1, $writer->drafts);
        $draft = $writer->drafts[0];
        self::assertSame('q1', $draft->quoteId);
        self::assertSame('escalated', $draft->outcome);
        self::assertSame('not_configured', $draft->escalationReason);
        self::assertSame(['No LLM API key is set.'], $draft->violations);
        // A pass that did not run has no duration. #21 reads durationMs as
        // servicing latency and takes p50/p95 over it; a sub-millisecond
        // refusal folded into that distribution would deflate both.
        self::assertNull($draft->durationMs);
        self::assertNull($draft->band);
        self::assertNull($draft->model);
    }

    public function testARefusalDoesNotDisturbAPassThatIsAlreadyOpen(): void
    {
        // The failure mode the design claims is impossible: recordRefusal()
        // never reads or assigns the draft, so it cannot swallow, truncate or
        // duplicate a pass that is mid-flight. Asserted rather than argued --
        // this is the whole reason a second write path was affordable.
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordReply('We can do 5%.', 'reply-hash');
        $recorder->recordRefusal(
            NegotiationFixture::snapshot(),
            self::context(),
            QuoteEscalationReason::NotConfigured,
            [],
        );
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertCount(2, $writer->drafts);
        self::assertSame('escalated', $writer->drafts[0]->outcome);
        self::assertNull($writer->drafts[0]->replyToBuyer);
        self::assertNull($writer->drafts[0]->violations, 'An empty problem list is not a violation.');
        self::assertSame('offered', $writer->drafts[1]->outcome);
        self::assertSame('We can do 5%.', $writer->drafts[1]->replyToBuyer);
    }

    public function testATraceEventIsBufferedOnTheOpenPass(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->trace(TraceKind::ReplyGuard, ['accepted' => false], ['reason' => 'it is empty']);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $kinds = array_map(static fn($t): string => $t->kind->value, $writer->drafts[0]->trace);
        self::assertContains('reply_guard', $kinds);
    }

    public function testAPassOpensWithTheQuoteAsItWas(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $snapshot = NegotiationFixture::snapshot();

        $recorder->begin($snapshot, self::context());
        $recorder->finish(new NegotiationPass(NegotiationOutcome::NothingToDo));

        $first = $writer->drafts[0]->trace[0];
        self::assertSame('quote_before', $first->kind->value);
        self::assertSame(0, $first->position);
        self::assertSame(['lineCount' => \count($snapshot->content->lines)], $first->meta);
        self::assertSame($snapshot->identity->quoteId, $first->content['identity']['quoteId'] ?? null);
    }

    public function testARefusalRowCarriesTheQuoteItRefusedToo(): void
    {
        $writer = new FakeDecisionWriter();

        (new DecisionRecorder($writer))->recordRefusal(
            NegotiationFixture::snapshot(),
            self::context(),
            QuoteEscalationReason::NotConfigured,
            ['no model'],
        );

        self::assertSame(
            ['quote_before'],
            array_map(static fn(TraceDraft $t): string => $t->kind->value, $writer->drafts[0]->trace),
        );
    }

    public function testTheVerdictAndTheAppliedQuoteAreTraced(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $after = NegotiationFixture::snapshot(totalNet: 950.0);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $recorder->recordDecision(
            new NegotiationDecision(
                Band::Grant,
                QuoteDecision::autoReply(new QuoteAutoReplyDetails(5.0, false, [], 14)),
            ),
            10.0,
        );
        $recorder->recordApplied(new AppliedOffer(true, [], $after, 1000.0), ['updateLineItems']);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $kinds = array_map(static fn(TraceDraft $t): string => $t->kind->value, $writer->drafts[0]->trace);
        self::assertSame(['quote_before', 'policy_verdict', 'quote_after'], $kinds);
        self::assertSame(950.0, $writer->drafts[0]->trace[2]->content['totals']['totalNet'] ?? null);
    }

    public function testATraceWithoutAnOpenPassIsDropped(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->trace(TraceKind::ReplyGuard, ['accepted' => false]);

        self::assertNull($recorder->decisionId());
        self::assertSame([], $writer->drafts);
    }

    public function testTheDecisionIdIsKnownFromBeginAndIsTheWrittenRowsId(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::snapshot(), self::context());
        $id = $recorder->decisionId();
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));
        self::assertSame($id, $writer->drafts[0]->id);
        self::assertNull($recorder->decisionId(), 'finish() closes the pass; no id may outlive it.');
    }

    /**
     * The reply states the gross total (ReplyComposer), so the row has to
     * carry one too, read off the same two snapshots as the net figures. An
     * export with only net made every taxed reply look like a mismatch.
     */
    public function testTheGrossTotalsComeFromTheSameSnapshotsAsTheNet(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);

        $recorder->begin(NegotiationFixture::grossSnapshot(), self::context()); // 800 net, 1000 gross
        $recorder->recordApplied(new AppliedOffer(true, [], NegotiationFixture::snapshot(totalNet: 950.0), 800.0), []);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $draft = $writer->drafts[0];
        self::assertSame(800.0, $draft->totalNetBefore);
        self::assertSame(1000.0, $draft->totalGrossBefore);
        self::assertSame(950.0, $draft->totalGrossAfter);
    }

    public function testTheVerdictNamesThePolicyThatBoundIt(): void
    {
        $hash = static function (float $maxDiscountPercent): mixed {
            $writer = new FakeDecisionWriter();
            $recorder = new DecisionRecorder($writer);
            $recorder->begin(NegotiationFixture::snapshot(), self::context());
            $recorder->recordDecision(
                self::grantDecision(),
                $maxDiscountPercent,
                policy: new NegotiationPolicy(new QuoteLimits($maxDiscountPercent, validityDays: 14)),
            );
            $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

            return $writer->drafts[0]->trace[1]->meta['policyHash'];
        };

        self::assertIsString($hash(10.0));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $hash(10.0));
        self::assertSame($hash(10.0), $hash(10.0), 'The same settings must give the same hash.');
        self::assertNotSame($hash(10.0), $hash(12.0), 'A changed limit must change the hash.');
    }

    private static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::CommentWritten, 0);
    }

    private static function grantDecision(): NegotiationDecision
    {
        return new NegotiationDecision(
            Band::Grant,
            QuoteDecision::autoReply(new QuoteAutoReplyDetails(
                discountPercent: 5.0,
                perLineAsks: false,
                lineUnitPricesNet: [],
                validityDays: 14,
            )),
        );
    }
}
