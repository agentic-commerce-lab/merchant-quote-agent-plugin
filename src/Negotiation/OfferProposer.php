<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyQuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
use MerchantQuoteAgentPlugin\Policy\DiscountRounding;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferLevelMirror;

/**
 * Model call 2, reached only when the deterministic band already said this ask
 * is inside the merchant's authority.
 *
 * The model chooses HOW MUCH within that authority and how to say it — never
 * WHETHER it may. Whatever it returns goes through OfferAuthorizer, which
 * rejects anything outside the bands no matter what the merchant's strategy
 * prompt asked for.
 */
final readonly class OfferProposer
{
    public function __construct(
        private ModelPlatform $platform,
        private PromptComposer $prompts,
        private OfferAuthorizer $authorizer,
        private DecisionRecorder $recorder,
        private CustomerHistoryFactoryInterface $historyFactory,
    ) {}

    /** @throws ModelUnavailable */
    public function propose(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        NegotiationContext $context,
        ?float $askedDiscountPercent = null,
    ): ProposedAnswer {
        // One anchor for the brief, the mirror and the checks —
        // the original prices, carrying the live asks. Round one has no
        // baseline yet, and there the live snapshot IS the original.
        $live = $snapshot;
        $snapshot = $context->baseline?->anchor($snapshot) ?? $snapshot;
        $details = $decision->autoReply;

        if ($details === null) {
            // Should be unreachable: OfferRound is only reached once
            // NegotiationPipeline's own gate has confirmed the band is Grant
            // or Counter, which is exactly when NegotiationDecider gives
            // QuoteDecision::autoReply() rather than ::escalate(). Kept as
            // NeedsHumanReview rather than a new case (issue #169) because,
            // being a violated invariant, its true cause is by definition not
            // one of the named ones below — the generic case's own honest
            // "cause not recorded" reading fits it exactly.
            return $this->recorded(null, ProposedAnswer::escalate(
                QuoteEscalationReason::NeedsHumanReview,
                'No priced band decision.',
                null,
            ));
        }

        $prompt = $this->prompts->negotiate($settings);
        $rounds = new HistoryRounds(
            $this->platform,
            $this->recorder,
            $this->historyFactory->for($context->customerId, $context->quoteId),
        );

        try {
            $response = $rounds->negotiate(
                $settings->llm,
                $prompt,
                self::userPrompt($settings, $snapshot, $decision, $context),
                $snapshot->lines,
            );
        } catch (HistoryBudgetExhausted $e) {
            // Issue #169: the model kept asking for account history and never
            // produced a usable answer within the round budget — the "or
            // answered unusably" half of ModelUnavailable's own remit, not an
            // unspecified human-review catch-all.
            return $this->recorded(
                (string) json_encode($e->response),
                ProposedAnswer::escalate(QuoteEscalationReason::ModelUnavailable, $e->getMessage(), $prompt->hash),
            );
        }

        // ponytail: the audit column holds what the model proposed, and under a
        // strict JSON schema the mapped object IS that answer — so re-encoding
        // it is lossless and keeps every trace of the provider's envelope out
        // of this class. Plain scalars and arrays only, so encoding cannot
        // fail. If the byte-exact provider payload is ever wanted instead, it
        // is on the result's RawHttpResult; ModelPlatform would have to return
        // it alongside the object.
        $raw = (string) json_encode($response);

        if ($response->escalates()) {
            // #222: the model itself declined to offer anything. Recorded as
            // its own reason: the model was reachable and answered, so this is
            // a judgement to review, not an outage (it was ModelUnavailable
            // under #169).
            return $this->recorded($raw, ProposedAnswer::escalate(
                QuoteEscalationReason::ModelDeclined,
                $response->escalationReason ?? 'The agent declined to answer this ask.',
                $prompt->hash,
            ));
        }

        // Rounding control (spec 2026-09-28), discount_percent mode: before
        // the mirror, so a per-line conversion inherits the rounded rate, and
        // before authorize(), so the checks and the reply see the rate that
        // is written. Null (and nothing traced) in every other case.
        [$proposed, $rounding] = DiscountRounding::offer(
            $settings->policy->price,
            $response->toOffer($snapshot->totalNet),
            $askedDiscountPercent,
            CappedAuthority::standing($snapshot, $live),
        );
        $this->recorder->recordRounding($rounding);

        $offer = LinePriceNormalizer::normalize(self::atTheBuyersLevel($proposed, $snapshot), $snapshot->lines);

        return $this->recorded($raw, $this->authorize(
            $settings,
            $snapshot->lines,
            $offer,
            $response->message,
            $prompt->hash,
        ));
    }

    /**
     * #47: a buyer who itemised the ask must not be answered with a quote-wide
     * percentage. The rules-only path picks the level from `perLineAsks`
     * already; the model is merely told to, so its answer is corrected here.
     *
     * This ran on the first round only until #49, because OfferRound escalated
     * any per-line offer on a later round and converting one would have turned
     * an answerable quote into a human's. The stored baseline removed that
     * guard, so the correction now applies to every round.
     */
    private static function atTheBuyersLevel(ProposedOffer $offer, PolicySnapshot $snapshot): ProposedOffer
    {
        return OfferLevelMirror::mirror($offer, $snapshot->lines);
    }

    /** Records the raw model text (null when no model was called) alongside the decision, then returns it unchanged. */
    private function recorded(?string $raw, ProposedAnswer $answer): ProposedAnswer
    {
        $this->recorder->recordProposal($raw, $answer);

        return $answer;
    }

    /**
     * The bands are checked against the quote's pre-negotiation lines (#49):
     * a per-line offer is bounded line by line against them, and with no
     * reference LinePriceOfferCheck rejects every one of them.
     *
     * @param list<PolicyQuoteLineSnapshot> $referenceLines
     */
    private function authorize(
        QuoteAgentSettings $settings,
        array $referenceLines,
        ProposedOffer $offer,
        string $message,
        ?string $promptHash,
    ): ProposedAnswer {
        $offer = $offer->withReferenceLines($referenceLines);
        $authorization = $this->authorizer->authorize($offer, $settings->policy);

        if (!$authorization->approved) {
            return ProposedAnswer::escalate(
                QuoteEscalationReason::ProposalRejected,
                implode('; ', $authorization->violations),
                $promptHash,
            );
        }

        return ProposedAnswer::offer($offer, $message, $promptHash);
    }

    private static function userPrompt(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        NegotiationContext $context,
    ): string {
        $conversation = $context->conversation;
        // Everything above and below prices in net, and the buyer's comment
        // does not: on a gross quote the figure in their sentence carries the
        // tax. Naming their target in the prompt's own space is what stops the
        // model reading their number against the net total and pricing to it —
        // a buyer who asked for 3000 gross and was offered 3209.73,
        // the model's own message claiming it had met a "3,000 EUR" budget.
        // Stated as an ask, not an instruction: the authority above still
        // decides how much of it may be met.
        $buyerTarget = $context->buyerTargetNet === null
            ? ''
            : sprintf(
                "Buyer's target total for the whole quote (net, converted from the figure in their comment): %.2f %s\n",
                $context->buyerTargetNet,
                $snapshot->currencyIso,
            );

        // #166: the prompt used to show the agent's own earlier replies as
        // prose, which drops its figures the moment a reword changes the
        // wording around them. NegotiationTranscript reads the same thread
        // and keeps only what round-over-round memory actually needs: the
        // buyer's ask and the offer made for it, per round. Empty on round
        // one, when there is nothing yet to remember.
        //
        // The buyer's LATEST comment is the ask this round answers; the
        // earlier ones were already answered by the rounds in the transcript.
        //
        // The authority covers every dimension the response schema allows, not
        // just the discount cap: see AuthorityBrief for what silence cost.
        $transcript = NegotiationTranscript::of($conversation);
        $earlierRounds = $transcript === ''
            ? ''
            : sprintf(
                "Earlier rounds of this negotiation (buyer's ask -> your offer, oldest first):\n%s\n\n",
                $transcript,
            );

        // #222: the table is net, the buyer's sentence is not, and neither are
        // the earlier rounds: their "you offered" figures are gross too
        // (ReplyComposer composes from buyerFacingTotal()). Said once, above
        // both, so no raw figure in either is read against a net price.
        $buyerSpace = $context->buyerWritesGross
            ? "The figures in the earlier rounds and in the buyer's comment below include tax (gross); the total and the table above are net.\n"
            : '';

        return sprintf(
            "Quote total (net): %.2f %s\n%s\n%s\n\nYOUR AUTHORITY:\n%s\n\n" . "%s%sBuyer's latest comment:\n%s",
            $snapshot->totalNet,
            $snapshot->currencyIso,
            $buyerTarget,
            NegotiateLineBlock::of($snapshot->lines, $context->lineAsksNet),
            AuthorityBrief::of($settings->policy, $decision->autoReply?->counteredRequestPercent),
            $buyerSpace,
            $earlierRounds,
            $conversation->newestBuyerText(),
        );
    }
}
