<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `verifyPayment`/`grantsPayment` in src/policy/negotiate-verify.ts.
 */
final class PaymentGrantVerifier
{
    public function __construct(
        private readonly GrantedPaymentTermCheck $term = new GrantedPaymentTermCheck(),
        private readonly GrantedNetDaysCheck $netDays = new GrantedNetDaysCheck(),
        private readonly GrantedDepositCheck $deposit = new GrantedDepositCheck(),
    ) {}

    /** @return list<string> */
    public function verify(?PaymentDecision $decision, ?PaymentPolicy $policy): array
    {
        if ($decision === null) {
            return [];
        }

        if ($policy === null) {
            return $this->grantsAnything($decision) ? ['payment terms granted with no payment policy'] : [];
        }

        return array_values(array_filter([
            $this->term->check($decision, $policy),
            $this->netDays->check($decision, $policy),
            $this->deposit->check($decision, $policy),
        ]));
    }

    private function grantsAnything(PaymentDecision $decision): bool
    {
        return (
            $decision->grantedTerm !== null
            || $decision->grantedNetDays !== null
            || $decision->grantedDepositPercent !== null
        );
    }
}
