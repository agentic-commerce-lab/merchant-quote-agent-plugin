<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Mandate;

use MerchantQuoteAgentPlugin\Protocol\Crypto\CompactJws;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;

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
        $jws = CompactJws::sign($digest, $this->keys->current()->privateKeyPem);

        return $mandate
        + ['proof' => [
            'type' => self::PROOF_TYPE,
            'verification_method' => $identity->verificationMethod,
            'created' => $now->format(\DATE_ATOM),
            'jws' => $jws,
        ]];
    }
}
