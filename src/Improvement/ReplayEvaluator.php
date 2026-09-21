<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\GrantedDiscount;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\CappedAuthority;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationContext;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationProposal;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;

/**
 * One decision, one strategy prompt, one answer -- and nothing written.
 *
 * The sequence is NegotiationPipeline::answer() and OfferRound::play() with
 * the last stages cut off: decide, then propose, then stop. OfferApplier is a
 * separate class and is never reached, the gateway is never handed in, and the
 * recorder is wired to TallyingDecisionWriter, so a replay cannot touch the
 * quote, the conversation or the audit trail.
 *
 * The ONLY difference between the control arm and a candidate arm is
 * $settings->strategyPrompt. That is what makes the delta attributable: same
 * quote, same interpretation, same bands, same model, same night.
 *
 * Buyer history is deliberately absent -- $proposer is constructed with
 * NoCustomerHistory. Both arms are equal, so the delta holds, and a buyer's
 * order history stays out of a nightly batch job.
 *
 * $recorder is the same DecisionRecorder instance $proposer was built with.
 * OfferProposer's own recordDecision/recordProposal/recordModelCall calls are
 * no-ops until something opens a draft (DecisionRecorder::begin()) -- that is
 * how NegotiationPipeline::service() keeps a pass and its cost together, and
 * nothing else plays that role for a replay. begin()/finish() here is that
 * same lifecycle, run once per replayed decision, so the token counts a model
 * call produces still reach the writer -- the one number worth keeping from a
 * pass that otherwise leaves no trace.
 */
final readonly class ReplayEvaluator
{
    public function __construct(
        private NegotiationDecider $decider,
        private OfferProposer $proposer,
        private DecisionRecorder $recorder,
    ) {}

    public function replay(QuoteAgentSettings $settings, QuoteSnapshot $snapshot, ?InterpretedAsk $ask): ReplayArm
    {
        // Attempt 0, CommentWritten: a replay is not a message Messenger ever
        // saw, so neither value carries meaning here. ServicingTriggerReason's
        // own docblock says nothing downstream branches on it, and
        // TallyingDecisionWriter reads only the token counts, so a nominal
        // context is honest rather than a guess dressed up as data.
        $this->recorder->begin($snapshot, new PassContext(ServicingTriggerReason::CommentWritten, 0));

        try {
            return $this->run($settings, $snapshot, $ask);
        } finally {
            // No NegotiationPass ever existed for this arm -- finish(null)
            // still hands whatever tokens were recorded to the writer, which
            // is the point: see the class docblock.
            $this->recorder->finish(null);
        }
    }

    private function run(QuoteAgentSettings $settings, QuoteSnapshot $snapshot, ?InterpretedAsk $ask): ReplayArm
    {
        // Quote 1101: measured against the ORIGINAL prices, the same anchor
        // the live pass used. QuoteBaseline persists on the quote, so this
        // holds even though the quote has moved on since.
        $policySnapshot = SnapshotAdapter::anchored($snapshot);
        $decision = $this->decider->decide(
            $policySnapshot,
            $settings->policy,
            new NegotiationProposal(price: $ask?->interpretation),
        );

        if ($decision->overall === Band::Escalate) {
            return ReplayArm::escalated();
        }

        $context = new NegotiationContext(
            $snapshot->identity->customerId,
            $snapshot->identity->quoteId,
            SnapshotAdapter::conversation($snapshot),
            QuoteBaseline::read($snapshot),
        );

        try {
            $answer = $this->proposer->propose(
                CappedAuthority::forRound($settings, $policySnapshot, $ask),
                SnapshotAdapter::toPolicy($snapshot),
                $decision->price,
                $context,
            );
        } catch (ModelUnavailable) {
            return ReplayArm::failed();
        }

        if ($answer->offer === null) {
            // Either the authorizer refused what the model proposed or the
            // model declined the ask. Both are the arm conceding nothing, and
            // both count against it.
            return ReplayArm::modelRefused();
        }

        // OfferedTotal, not $answer->offer->orderTotalNet: that field is the
        // BEFORE total the proposer was handed, carried through unchanged --
        // see OfferedTotal's docblock for why it cannot stand in for "after".
        $after = OfferedTotal::of($policySnapshot->totalNet, $answer->offer->price) ?? $policySnapshot->totalNet;

        return ReplayArm::offered(GrantedDiscount::of($policySnapshot->totalNet, $after));
    }
}
