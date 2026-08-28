<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;

/**
 * The negotiate prompt's JSON to a ProposedOffer — the AGENT's offer, which
 * OfferAuthorizer then checks against the merchant's authority. Not to be
 * confused with NegotiationProposal, which is the BUYER's interpreted ask.
 *
 * `action: escalate` is a first-class answer, not a failure: the model is
 * allowed to say it cannot serve this buyer, and its reason travels to the log.
 * The concession terms themselves are parsed by OfferTerms, kept separate so
 * this class's constructor stays within the parameter-count gate.
 */
final readonly class NegotiateResponse
{
    private function __construct(
        public bool $escalate,
        public ?string $escalationReason,
        public string $message,
        private OfferTerms $terms,
    ) {}

    /** @throws ModelUnavailable */
    public static function read(string $json): self
    {
        $raw = Json::object($json);
        $action = $raw['action'] ?? null;

        if ($action !== 'offer' && $action !== 'escalate') {
            throw new ModelUnavailable('The negotiate response carried no usable action.');
        }

        return new self(
            escalate: $action === 'escalate',
            escalationReason: Scalar::string($raw, 'escalation_reason'),
            message: Scalar::string($raw, 'message') ?? '',
            terms: OfferTerms::read($raw),
        );
    }

    public function toOffer(float $orderTotalNet): ProposedOffer
    {
        return $this->terms->toOffer($orderTotalNet);
    }
}
