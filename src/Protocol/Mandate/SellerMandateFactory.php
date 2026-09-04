<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Terms\MinorUnits;
use MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount;

/**
 * The unsigned A2CN declared seller mandate (spec's mandate section):
 * self-declared authority, not a third-party attestation — `mandate_type` is
 * always `'declared'`, the one A2CN method this plugin implements.
 *
 * `negotiation_bands` is a DOCUMENTED EXTENSION of this plugin's own, not
 * normative A2CN authority: the spec defines the mandate envelope
 * (`max_commitment_value`, `authorized_deal_types`, validity) but says
 * nothing about how a shop's discount policy is expressed. Publishing the
 * bands here — and the delivery/payment/bundle blocks inside them — makes
 * public exactly the same non-price authority QuoteBandDecider and the
 * sibling deciders already enforce server-side; a buyer agent that ignores
 * this extension still gets a spec-conformant declared mandate.
 */
final readonly class SellerMandateFactory
{
    public const MANDATE_TYPE = 'declared';

    /**
     * @return array<string, mixed>
     *
     * @throws NonFiniteAmount
     */
    public function build(NegotiationPolicy $policy, A2cnIdentity $identity, \DateTimeImmutable $validFrom): array
    {
        $mandate = [
            'mandate_type' => self::MANDATE_TYPE,
            'agent_id' => $identity->agentId,
            'principal_organization' => $identity->organizationName,
            'principal_did' => $identity->did,
            'authorized_deal_types' => A2cnIdentity::DEAL_TYPES,
            'valid_from' => $validFrom->format(\DATE_ATOM),
            'valid_until' => $validFrom->modify('+1 year')->format(\DATE_ATOM),
            'negotiation_bands' => NegotiationBands::fromPolicy($policy),
        ];

        $ceiling = $policy->price->valueCeiling;
        if ($ceiling !== null) {
            $mandate['max_commitment_value'] = MinorUnits::from($ceiling->net);
            $mandate['max_commitment_currency'] = $ceiling->currencyIso ?? '';
        }

        return $mandate;
    }
}
