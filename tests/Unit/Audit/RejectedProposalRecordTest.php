<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\ProposedAnswer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use PHPUnit\Framework\TestCase;

/**
 * A refused proposal has to say WHY in the audit row.
 *
 * Live quote 1019 escalated with `authorized: 0` and
 * `escalation_reason: proposal_rejected`, and `violations` was NULL — while an
 * APPROVED round recorded `[]`. The reason string reaches
 * ProposedAnswer::escalate() and was then dropped, and the matching log line
 * is an `info` that prod suppresses. So the one fact explaining the escalation
 * was stored nowhere and the pass could not be explained after the fact.
 */
final class RejectedProposalRecordTest extends TestCase
{
    public function testARefusedProposalRecordsTheViolationThatRefusedIt(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), new PassContext(ServicingTriggerReason::CommentWritten, 0));

        $recorder->recordProposal('{"action":"offer"}', ProposedAnswer::escalate(
            QuoteEscalationReason::ProposalRejected,
            'line "Widget" price 579.33 exceeds the 15% limit',
            'hash',
        ));
        $recorder->finish(null, null);

        self::assertSame(['line "Widget" price 579.33 exceeds the 15% limit'], $writer->drafts[0]->violations);
    }

    public function testAnApprovedProposalRecordsNoViolations(): void
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(NegotiationFixture::snapshot(), new PassContext(ServicingTriggerReason::CommentWritten, 0));

        $recorder->recordProposal('{"action":"escalate"}', ProposedAnswer::escalate(
            QuoteEscalationReason::NeedsHumanReview,
            '',
            'hash',
        ));
        $recorder->finish(null, null);

        self::assertNull($writer->drafts[0]->violations, 'An empty detail is not a violation list.');
    }
}
