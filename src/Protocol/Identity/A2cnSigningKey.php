<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

/** The installation's A2CN signing key, as stored. */
final readonly class A2cnSigningKey
{
    public function __construct(
        public string $kid,
        public string $privateKeyPem,
        public string $publicKeyPem,
        public string $createdAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'kid' => $this->kid,
            'private_key_pem' => $this->privateKeyPem,
            'public_key_pem' => $this->publicKeyPem,
            'created_at' => $this->createdAt,
        ];
    }

    /** @param array<array-key, mixed> $stored */
    public static function fromArray(array $stored): ?self
    {
        $kid = $stored['kid'] ?? null;
        $private = $stored['private_key_pem'] ?? null;
        $public = $stored['public_key_pem'] ?? null;
        $createdAt = $stored['created_at'] ?? null;

        if (!\is_string($kid) || !\is_string($private) || !\is_string($public) || !\is_string($createdAt)) {
            return null;
        }

        return new self($kid, $private, $public, $createdAt);
    }
}
