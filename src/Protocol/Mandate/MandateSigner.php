<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;

/**
 * Attaches a DETACHED proof to a mandate: the signature covers the mandate
 * body with no `proof` member, which is exactly what a verifier reconstructs
 * by removing it before recomputing the digest.
 *
 * `base64url(SHA-256(JCS(body)))`, signed as an ES256 compact JWS — the same
 * digest-then-sign shape A2CN acts use (ProtocolHash, CompactJws), applied
 * here to the mandate instead of an act.
 */
final readonly class MandateSigner
{
    private const PROOF_TYPE = 'JsonWebSignature2020';

    public function __construct(
        private ProtocolHash $hash,
        private A2cnKeyStore $keys,
    ) {}

    /**
     * @param array<string, mixed> $mandate
     *
     * @return array<string, mixed>
     *
     * @throws MissingSigningKey
     */
    public function sign(array $mandate, A2cnIdentity $identity, \DateTimeImmutable $now): array
    {
        $digest = $this->hash->of($mandate);
        // The kid in the header names the same verification method the proof
        // states below, so a verifier reading only the JWS resolves the same
        // key as one reading the proof.
        $jws = CompactJws::sign($digest, $this->keys->current()->privateKeyPem, $identity->verificationMethod);

        return $mandate
        + ['proof' => [
            'type' => self::PROOF_TYPE,
            'verification_method' => $identity->verificationMethod,
            'created' => ProtocolTimestamp::of($now),
            'jws' => $jws,
        ]];
    }
}
