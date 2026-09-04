<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;
use Override;

/**
 * The first counterparty act that does not verify against its own did:web key.
 *
 * Runs last of all the checks: it is the only one that touches the network.
 *
 * Three steps, all load-bearing:
 *   1. resolve the key the act names,
 *   2. recompute the hash from the act's own signed view and compare — without
 *      this, a signature that is valid over some OTHER object would pass,
 *   3. verify the JWS and require its payload to be exactly that hash.
 */
final readonly class BuyerSignatureCheck implements EvidenceCheckInterface
{
    public function __construct(
        private DidWebResolver $resolver,
        private ProtocolHash $hash,
    ) {}

    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        foreach ($chain->buyerActs($sellerDid) as $act) {
            $reason = $this->reasonItDoesNotVerify($act);
            if ($reason !== null) {
                return new ProtocolViolation(
                    timestamp: $at->format(\DATE_ATOM),
                    violationType: 'buyer_act_unverified',
                    messageId: $act->messageId(),
                    description: \sprintf('act %s %s', $act->messageId(), $reason),
                );
            }
        }

        return null;
    }

    private function reasonItDoesNotVerify(Act $act): ?string
    {
        $expected = $this->hash->of(SignedView::of($act));
        if ($expected !== $act->hash()) {
            return \sprintf('carries a protocol_act_hash that does not cover it (expected %s)', $expected);
        }

        $pem = $this->resolver->publicKeyPemFor($act->verificationMethod());
        if ($pem === null) {
            return \sprintf('names a verification method that does not resolve (%s)', $act->verificationMethod());
        }

        if (CompactJws::verify($act->signature(), $pem) !== $expected) {
            return 'did not verify against its did:web key';
        }

        return null;
    }
}
