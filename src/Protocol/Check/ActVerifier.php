<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\AcceptanceView;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;

/**
 * Why one act does not verify against its own did:web key, or null when it
 * does.
 *
 * Split out of BuyerSignatureCheck so the inbound act route can ask the same
 * question about a single act, before there is a chain to walk or a violation
 * to record. The check wraps the answer in a ProtocolViolation; the route
 * turns it into a 403. One implementation, because two would drift and the
 * drift would be a hole.
 *
 * Four steps, all load-bearing:
 *   0. require `sender_verification_method` to be controlled by `sender_did`
 *      (equal to it, or `sender_did . '#...'`) — a free local comparison, run
 *      before the network hop it would otherwise waste. Without it, an act
 *      could claim `sender_did: did:web:buyer.example` while naming a
 *      verification method under a DIFFERENT did:web authority, and steps
 *      1-3 would happily verify the signature against that other party's
 *      key: the act would read as buyer-signed while being signed by
 *      whoever controls the named method.
 *   1. resolve the key the act names,
 *   2. recompute the hash from the act's own signed view and compare — without
 *      this, a signature that is valid over some OTHER object would pass,
 *   3. verify the JWS and require its payload to be exactly that hash.
 *
 * Not `final`: the tests substitute it.
 */
class ActVerifier
{
    public function __construct(
        private readonly DidWebResolver $resolver,
        private readonly ProtocolHash $hash,
    ) {}

    public function reasonItDoesNotVerify(Act $act): ?string
    {
        $mismatch = self::verificationMethodMismatch($act);
        if ($mismatch !== null) {
            return $mismatch;
        }

        // Which object this act signed, and therefore which digest its
        // signature must carry. An A2CN acceptance signs AcceptanceView's five
        // fields into `acceptance_signature`; everything else signs
        // SignedView's nine into `protocol_act_signature`.
        if ($act->isAcceptanceEnvelope()) {
            $expected = $this->hash->of(AcceptanceView::of($act));
        } else {
            $expected = $this->hash->of(SignedView::of($act));

            // Only the protocol act object is bound to a hash ON the act. An
            // acceptance envelope carries no digest of itself, so there is
            // nothing here to cross-check — the signature below is the whole
            // binding.
            if ($expected !== $act->hash()) {
                return \sprintf('carries a protocol_act_hash that does not cover it (expected %s)', $expected);
            }
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

    /**
     * A conformant `sender_verification_method` is the sender's own DID, or
     * that DID plus a `#fragment` — never a method under a different DID.
     * Without this, an act naming a foreign verification method would still
     * reach `DidWebResolver`, which resolves whatever DID a verification method
     * NAMES, not whatever DID the act CLAIMS as its sender.
     */
    private static function verificationMethodMismatch(Act $act): ?string
    {
        $senderDid = $act->senderDid();
        $method = $act->verificationMethod();
        if ($method === $senderDid || str_starts_with($method, $senderDid . '#')) {
            return null;
        }

        return \sprintf('names a verification method (%s) not controlled by its sender_did (%s)', $method, $senderDid);
    }
}
