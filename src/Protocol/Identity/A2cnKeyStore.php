<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/**
 * The installation's A2CN signing key, in `system_config`.
 *
 * Deliberately NOT under the `MerchantQuoteAgentPlugin.config.*` prefix the
 * admin renders: a private key must never appear in a config form.
 *
 * `current()` throws when the key is absent instead of generating one. Lazy
 * generation would let two concurrent requests create two keys, and every act
 * signed with the loser would stop verifying — silent worthless evidence is
 * worse than a loud refusal. Generation happens in the plugin's install() and
 * activate() hooks, where only one process runs.
 *
 * ES256 keygen and the JWK projection are the UCP SDK's; the key material never
 * leaves this class except as a PEM to sign with or a PUBLIC jwk to publish.
 *
 * Not `final`: the tests substitute it.
 */
class A2cnKeyStore
{
    public const CONFIG_KEY = 'MerchantQuoteAgentPlugin.a2cn.signingKeyJwk';

    private const KID_PREFIX = 'a2cn-';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly SigningKeyManagerInterface $keys,
    ) {}

    /** @throws MissingSigningKey */
    public function current(): A2cnSigningKey
    {
        $stored = $this->systemConfig->get(self::CONFIG_KEY);
        $key = \is_array($stored) ? A2cnSigningKey::fromArray($stored) : null;

        if ($key === null) {
            throw new MissingSigningKey(
                'No A2CN signing key is configured for this installation; reinstall or reactivate the plugin to generate one.',
            );
        }

        return $key;
    }

    /** @throws \Random\RandomException */
    public function generateIfAbsent(): A2cnSigningKey
    {
        try {
            return $this->current();
        } catch (MissingSigningKey) {
            $generated = $this->keys->generate(self::KID_PREFIX . bin2hex(random_bytes(8)));
            $key = new A2cnSigningKey(
                kid: $generated->kid,
                privateKeyPem: $generated->privateKeyPem,
                publicKeyPem: $generated->publicKeyPem,
                createdAt: $generated->createdAt ?? ProtocolTimestamp::now(),
            );

            $this->systemConfig->set(self::CONFIG_KEY, $key->toArray());

            return $key;
        }
    }

    /**
     * The public half, as the did:web document publishes it.
     *
     * @return array<string, string>
     */
    public function publicJwk(A2cnSigningKey $key): array
    {
        $managed = new ManagedSigningKey($key->kid, $key->publicKeyPem, $key->privateKeyPem);

        return $this->keys->toPublicKey($managed)->toJwk();
    }
}
