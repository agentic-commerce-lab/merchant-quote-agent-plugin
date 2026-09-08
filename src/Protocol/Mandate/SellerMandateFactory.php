<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
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
 * bands here makes public exactly the price authority QuoteBandDecider
 * already enforces server-side; a buyer agent that ignores this extension
 * still gets a spec-conformant declared mandate.
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
            'valid_from' => ProtocolTimestamp::of($validFrom),
            'valid_until' => ProtocolTimestamp::of($validFrom->modify('+1 year')),
            'negotiation_bands' => NegotiationBands::fromPolicy($policy),
        ];

        $ceiling = $policy->price->valueCeiling;
        if ($ceiling !== null) {
            // No `max_commitment_currency`: the ceiling is read in the
            // quote's own currency, and publishing an empty string claims a
            // currency the shop never declared.
            $mandate['max_commitment_value'] = MinorUnits::from($ceiling->net);
        }

        return $mandate;
    }
}
