<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use Override;

/**
 * The first counterparty act that does not verify against its own did:web key.
 *
 * Runs last of all the checks: it is the only one that touches the network.
 *
 * See ActVerifier for the four load-bearing steps behind that question.
 */
final readonly class BuyerSignatureCheck implements EvidenceCheckInterface
{
    public function __construct(
        private ActVerifier $verifier,
    ) {}

    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        foreach ($chain->buyerActs($sellerDid) as $act) {
            $reason = $this->verifier->reasonItDoesNotVerify($act);
            if ($reason !== null) {
                return new ProtocolViolation(
                    timestamp: ProtocolTimestamp::of($at),
                    violationType: 'buyer_act_unverified',
                    messageId: $act->messageId(),
                    description: \sprintf('act %s %s', $act->messageId(), $reason),
                );
            }
        }

        return null;
    }
}
