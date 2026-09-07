<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use Override;

/**
 * The session id must be DERIVED from the quote, never accepted from the
 * counterparty: SessionId::forQuote is what makes emission idempotent, and
 * adopting a foreign id would file our signed evidence under a session we did
 * not open.
 */
final readonly class SessionIdCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        $expected = SessionId::forQuote($snapshot->identity->quoteId);
        $claimed = $chain->claimedSessionId();

        if ($claimed === $expected) {
            return null;
        }

        return new ProtocolViolation(
            timestamp: ProtocolTimestamp::of($at),
            violationType: 'session_id_mismatch',
            messageId: $chain->acts()[0]?->messageId(),
            description: \sprintf(
                'chain declares session %s, expected %s for this quote',
                $claimed ?? 'none',
                $expected,
            ),
        );
    }
}
