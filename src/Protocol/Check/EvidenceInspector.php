<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;

/**
 * The first evidence problem that means we must not counter-sign into this
 * chain, or null when it is clean.
 *
 * Order comes from the service definition and is normative: every local
 * comparison before anything that resolves a did:web document over the network.
 */
final readonly class EvidenceInspector
{
    /** @param iterable<EvidenceCheckInterface> $checks */
    public function __construct(
        private iterable $checks,
    ) {}

    public function firstViolation(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        foreach ($this->checks as $check) {
            $violation = $check->check($chain, $snapshot, $sellerDid, $at);
            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }
}
