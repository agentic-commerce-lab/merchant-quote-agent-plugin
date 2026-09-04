<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use Override;

/**
 * A sequence number claimed more than once.
 *
 * Both acts survived — role-suffixed keys cannot collide — so nothing is lost.
 * But the sequence is ambiguous, and signing into it would attest an order we
 * cannot defend. No single act is at fault, hence no message id.
 */
final readonly class DuplicateSequenceCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        $duplicates = $chain->duplicateSequences();
        if ($duplicates === []) {
            return null;
        }

        return new ProtocolViolation(
            timestamp: $at->format(\DATE_ATOM),
            violationType: 'duplicate_sequence',
            messageId: null,
            description: \sprintf('sequence number(s) %s claimed more than once', implode(', ', $duplicates)),
        );
    }
}
