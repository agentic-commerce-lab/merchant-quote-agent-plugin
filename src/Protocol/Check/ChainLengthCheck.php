<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use Override;

/**
 * The chain is longer than ActChain will read.
 *
 * Not one of the four ported checks: it reports OUR read cap. Signing into a
 * chain we could only read part of would attest a position we cannot compute,
 * so this refuses instead — and the record says why.
 */
final readonly class ChainLengthCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        if (!$chain->exceedsLengthCap()) {
            return null;
        }

        return new ProtocolViolation(
            timestamp: ProtocolTimestamp::of($at),
            violationType: 'chain_length_exceeded',
            messageId: null,
            description: \sprintf(
                'chain carries more than %d acts, which is more than this agent reads',
                ActChain::MAX_ACTS,
            ),
        );
    }
}
