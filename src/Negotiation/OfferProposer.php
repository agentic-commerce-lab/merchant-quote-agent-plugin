<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyQuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
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
    ) {}

    /**
     * @param ?QuoteBaselineLines $baseline the quote's prices as the agent
     *     first found them (#49). Null on the first pass, where the current
     *     lines ARE the original ones.
     *
     * @throws ModelUnavailable
     */
    public function propose(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        BuyerConversation $conversation,
        ?QuoteBaselineLines $baseline = null,
    ): ProposedAnswer {
        $referenceLines = $baseline === null ? $snapshot->lines : $baseline->linesMergedWith($snapshot->lines);
        $details = $decision->autoReply;

        if ($details === null) {
            return $this->recorded(null, ProposedAnswer::escalate(
                QuoteEscalationReason::NeedsHumanReview,
                'No priced band decision.',
                null,
            ));
        }

        $access = $settings->llm;

        $prompt = $this->prompts->negotiate($settings);
        $response = $this->platform->object(
            $access,
            $prompt->text,
            self::userPrompt($settings, $snapshot, $decision, $conversation),
            NegotiateResponse::class,
        );

        // ponytail: the audit column holds what the model proposed, and under a
        // strict JSON schema the mapped object IS that answer — so re-encoding
        // it is lossless and keeps every trace of the provider's envelope out
        // of this class. Plain scalars and arrays only, so encoding cannot
        // fail. If the byte-exact provider payload is ever wanted instead, it
        // is on the result's RawHttpResult; ModelPlatform would have to return
        // it alongside the object.
        $raw = (string) json_encode($response);

        if ($response->escalates()) {
            return $this->recorded($raw, ProposedAnswer::escalate(
                QuoteEscalationReason::NeedsHumanReview,
                $response->escalationReason ?? 'The agent declined to answer this ask.',
                $prompt->hash,
            ));
        }

        $offer = LinePriceNormalizer::normalize(
            self::atTheBuyersLevel($response->toOffer($snapshot->totalNet), $snapshot),
            $snapshot->lines,
        );

        return $this->recorded($raw, $this->authorize(
            $settings,
            $referenceLines,
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
        BuyerConversation $conversation,
    ): string {
        $lines = array_map(static fn(PolicyQuoteLineSnapshot $l): string => sprintf(
            '%s | %s | %d | %.2f',
            $l->lineItemId(),
            $l->label() ?? '',
            $l->quantity,
            $l->unitPriceNet,
        ), $snapshot->lines);

        // The prompt tells the model it is shown its own earlier offers, so it
        // is — and the buyer's LATEST comment is the ask this round answers;
        // the earlier ones were answered by the replies listed above it.
        //
        // The authority covers every dimension the response schema allows, not
        // just the discount cap: see AuthorityBrief for what silence cost.
        return sprintf(
            "Quote total (net): %.2f %s\n\nLine items (id | label | quantity | unit price net):\n%s\n\nYOUR AUTHORITY:\n%s\n\n"
            . "Your earlier replies on this quote:\n%s\n\nBuyer's latest comment:\n%s",
            $snapshot->totalNet,
            $snapshot->currencyIso,
            implode("\n", $lines),
            AuthorityBrief::of($settings->policy, $decision->autoReply?->counteredRequestPercent),
            $conversation->agentText(),
            $conversation->newestBuyerText(),
        );
    }
}
