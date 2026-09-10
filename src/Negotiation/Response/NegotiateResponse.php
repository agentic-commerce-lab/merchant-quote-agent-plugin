<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;

/**
 * The negotiate prompt's answer — the AGENT's offer, which OfferAuthorizer
 * then checks against the merchant's authority. Not to be confused with
 * NegotiationProposal, which is the BUYER's interpreted ask.
 *
 * There is no parsing left in here: this class IS the JSON schema the model
 * answers under, and Symfony AI maps the answer onto it. `action` carries no
 * default on purpose — a response without one fails to map, which is what the
 * hand-written "carried no usable action" gate used to do.
 */
final readonly class NegotiateResponse
{
    public function __construct(
        public NegotiationAction $action,
        public string $message = '',
        public ?string $escalationReason = null,
        public OfferTerms $terms = new OfferTerms(),
        public HistoryRequest $historyRequest = new HistoryRequest(),
    ) {}

    /**
     * Checked BEFORE `action` in the loop, so a request beats both an offer and
     * an escalation. A model that is still asking for data has not finished
     * deciding, and acting on half-formed terms is how a buyer gets told about a
     * concession the model would not have made with the account in front of it.
     * The two-round cap is what keeps that from being unbounded.
     */
    public function wantsHistory(): bool
    {
        return $this->historyRequest->isSet();
    }

    public function escalates(): bool
    {
        return $this->action === NegotiationAction::Escalate;
    }

    /** @throws ModelUnavailable */
    public function toOffer(float $orderTotalNet): ProposedOffer
    {
        if ($this->terms->contradictory()) {
            throw new ModelUnavailable('The negotiate response mixed a quote-wide discount with line prices.');
        }

        return $this->terms->toOffer($orderTotalNet);
    }
}
