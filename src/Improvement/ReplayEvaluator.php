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
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\MoneyMath;
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
 * interpretation, same bands, same model, same night -- and the SAME
 * conversation text, but not necessarily the SAME conversation the original
 * pass saw. `run()` reads it from the LIVE quote (SnapshotAdapter::conversation()
 * over the snapshot ReplaySubjectResolver resolved with QuoteVersion::Live),
 * which can carry comments written after the replayed decision; the price
 * anchor is the one thing pinned to that decision's own moment (see
 * QuoteBaseline::read() above). Every arm still sees the identical text, so
 * the A/B delta holds regardless -- see ReplaySubjectResolver's own docblock
 * for why this is an accepted limitation rather than a bug.
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

        // Both snapshots, the same two the live round passes: the anchored one
        // measures the ask against the ORIGINAL prices, and the live one is
        // what the quote costs the buyer right now. CappedAuthority needs both
        // to floor the round's cap at the concession already granted -- without
        // the live arm a round-two ask would cap below what the buyer is
        // holding, and the replay would diverge from production on exactly the
        // rounds this feature exists to measure.
        $live = SnapshotAdapter::toPolicy($snapshot);

        try {
            $answer = $this->proposer->propose(
                CappedAuthority::forRound($settings, $policySnapshot, $live, $ask),
                $live,
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

        return ReplayArm::offered(self::grantedPercent($policySnapshot->totalNet, $answer->offer->price));
    }

    /**
     * Null for a per-line concession -- deliberately, not an omission.
     *
     * A per-line offer's own `discountPercent` is null (OfferLevelMirror
     * clears it on purpose, #47), and the only trustworthy "after" total for
     * one is what OfferApplier's write, `$gateway->recalculate()`, and a
     * database re-read produce -- GrantedDiscount::of()'s own docblock says
     * so ("measured on the same two database totals ... never on the offer
     * we asked for"), and ReplyTemplate::reduction() rests on the same rule.
     * A replay may never take that step, so reconstructing the total
     * client-side would show a merchant a figure production never verified.
     * "Granted, but not measurable offline" is the honest answer; see
     * ReplayArm::offered()'s own null case.
     *
     * A quote-wide offer needs none of that: `$totalNetBefore * (1 -
     * discount/100)` is the one multiplication OfferApplier itself performs
     * (via the DiscountType::Percentage write), so GrantedDiscount::of() can
     * be fed it honestly, without a database round-trip.
     */
    private static function grantedPercent(float $totalNetBefore, OfferedPrice $price): ?float
    {
        if ($price->discountPercent === null) {
            return null;
        }

        $after = MoneyMath::roundMoney($totalNetBefore * (1 - ($price->discountPercent / 100)));

        return GrantedDiscount::of($totalNetBefore, $after);
    }
}
