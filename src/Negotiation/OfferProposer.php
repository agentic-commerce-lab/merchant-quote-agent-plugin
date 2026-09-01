<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
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
        private ChatCompletionClient $client,
        private PromptComposer $prompts,
        private OfferAuthorizer $authorizer,
        private DecisionRecorder $recorder,
    ) {}

    /** @throws ModelUnavailable */
    public function propose(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        BuyerConversation $conversation,
    ): ProposedAnswer {
        $details = $decision->autoReply;

        if ($details === null) {
            return $this->recorded(null, ProposedAnswer::escalate(
                QuoteEscalationReason::NeedsHumanReview,
                'No priced band decision.',
                null,
            ));
        }

        if ($settings->rulesOnly) {
            return $this->recorded(null, $this->authorize(
                $settings,
                $snapshot,
                self::deterministicOffer($snapshot, $details),
                '',
                null,
            ));
        }

        $access = $settings->llm;

        if ($access === null) {
            throw new ModelUnavailable('No model access is configured for this sales channel.');
        }

        $prompt = $this->prompts->negotiate($settings);
        $raw = $this->client->complete(
            $access,
            $prompt->text,
            self::userPrompt($settings, $snapshot, $decision, $conversation),
            json: true,
        );
        $response = NegotiateResponse::read($raw);

        if ($response->escalate) {
            return $this->recorded($raw, ProposedAnswer::escalate(
                QuoteEscalationReason::NeedsHumanReview,
                $response->escalationReason ?? 'The agent declined to answer this ask.',
                $prompt->hash,
            ));
        }

        return $this->recorded($raw, $this->authorize(
            $settings,
            $snapshot,
            self::atTheBuyersLevel($response->toOffer($snapshot->totalNet), $snapshot, $conversation),
            $response->message,
            $prompt->hash,
        ));
    }

    /**
     * #47: a buyer who itemised the ask must not be answered with a quote-wide
     * percentage. The rules-only path picks the level from `perLineAsks`
     * already; the model is merely told to, so its answer is corrected here.
     *
     * FIRST ROUND ONLY, and that restriction is load-bearing rather than
     * cautious. OfferRound escalates ANY per-line offer once the agent has
     * already replied, because the reference lines a per-line offer is bounded
     * against are re-captured every round and round two would compound past
     * the cap — #2(a)'s open half. Converting a later round would therefore
     * turn a quote the agent can answer into a quote a human has to, which is
     * a worse outcome than the wrong-level answer this fixes. Measured, not
     * assumed: without this guard, NegotiationPipelineTest and
     * DecisionRecordTest both flip from offered to escalated, and every open
     * quote in the test shop carries a requested price, so the conversion
     * fires on essentially all of them.
     *
     * Round two keeps answering quote-wide until #2(a) persists the reference
     * lines across passes.
     */
    private static function atTheBuyersLevel(
        ProposedOffer $offer,
        PolicySnapshot $snapshot,
        BuyerConversation $conversation,
    ): ProposedOffer {
        return $conversation->agent === [] ? OfferLevelMirror::mirror($offer, $snapshot->lines) : $offer;
    }

    /** Records the raw model text (null when no model was called) alongside the decision, then returns it unchanged. */
    private function recorded(?string $raw, ProposedAnswer $answer): ProposedAnswer
    {
        $this->recorder->recordProposal($raw, $answer);

        return $answer;
    }

    /**
     * The bands are checked against the snapshot this round was decided on:
     * a per-line offer is bounded line by line against those lines, and with
     * no reference LinePriceOfferCheck rejects every one of them.
     */
    private function authorize(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        ProposedOffer $offer,
        string $message,
        ?string $promptHash,
    ): ProposedAnswer {
        $offer = $offer->withReferenceLines($snapshot->lines);
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

    /** Rules-only: the band already priced this, so the band's number IS the offer. */
    private static function deterministicOffer(
        PolicySnapshot $snapshot,
        \MerchantQuoteAgentPlugin\Policy\Data\QuoteAutoReplyDetails $details,
    ): ProposedOffer {
        return new ProposedOffer(
            orderTotalNet: $snapshot->totalNet,
            price: new OfferedPrice(
                discountPercent: $details->perLineAsks ? null : $details->discountPercent,
                linePricesNet: $details->perLineAsks ? $details->lineUnitPricesNet : null,
            ),
        );
    }

    private static function userPrompt(
        QuoteAgentSettings $settings,
        PolicySnapshot $snapshot,
        QuoteDecision $decision,
        BuyerConversation $conversation,
    ): string {
        $limits = $settings->policy->price;
        $countered = $decision->autoReply?->counteredRequestPercent;

        // The prompt tells the model it is shown its own earlier offers, so it
        // is — and the buyer's LATEST comment is the ask this round answers;
        // the earlier ones were answered by the replies listed above it.
        return sprintf(
            "Quote total (net): %.2f %s\n\nYOUR AUTHORITY:\n- maximum discount you may grant: %.2f%%\n%s\n\n"
            . "Your earlier replies on this quote:\n%s\n\nBuyer's latest comment:\n%s",
            $snapshot->totalNet,
            $snapshot->currencyIso,
            $limits->maxDiscountPercent,
            $countered === null
                ? ''
                : sprintf(
                    '- the buyer asked for %.2f%%, which is above your cap: counter, do not grant it',
                    $countered,
                ),
            $conversation->agentText(),
            $conversation->newestBuyerText(),
        );
    }
}
