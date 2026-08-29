<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use PHPUnit\Framework\TestCase;

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
        self::assertSame('We can do 5%.', $writer->drafts[0]->buyerComment);
        self::assertNull($writer->drafts[1]->buyerComment, 'Pass one leaked into pass two.');
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

    private static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::CommentWritten, 0);
    }
}
