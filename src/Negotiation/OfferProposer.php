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
            return $this->recorded('', ProposedAnswer::escalate(
                QuoteEscalationReason::NeedsHumanReview,
                'No priced band decision.',
                null,
            ));
        }

        if ($settings->rulesOnly) {
            return $this->recorded('', $this->authorize(
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
            $response->toOffer($snapshot->totalNet),
            $response->message,
            $prompt->hash,
        ));
    }

    /** Records the raw model text (empty when no model was called) alongside the decision, then returns it unchanged. */
    private function recorded(string $raw, ProposedAnswer $answer): ProposedAnswer
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
