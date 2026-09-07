<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

/**
 * Resolves a counterparty's `did:web` verification key to a PEM.
 *
 * Every failure path returns null rather than throwing: an unresolvable buyer
 * DID means we cannot verify their act, which is an evidence problem handled by
 * BuyerSignatureCheck, not a reason to stop servicing the quote.
 *
 * JWK → PEM is the SDK's (`PublicSigningKey::fromJwk`), but that method demands
 * `kid`, `alg` and `use`, which real DID documents omit — so the JWK is
 * normalized (DidWebJwk::normalize) before it reaches the SDK. Curve trust
 * (P-256 only) is decided inside that same normalization step, alongside the
 * fields it fills — see DidWebJwk's docblock.
 *
 * The HTTP fetch (DidWebDocumentFetcher, which also gates the URL through the
 * SDK's host-safety validator) and the document parsing (DidWebJwk) are split
 * out: different concerns from what is left here — orchestration,
 * memoization, and the one step that needs the SDK's key manager.
 *
 * ponytail: process-lifetime memo, no TTL. Add one if a counterparty starts
 * rotating keys mid-session.
 *
 * Not `final`: the tests substitute it.
 */
class DidWebResolver
{
    /** @var array<string, string|null> */
    private array $memo = [];

    private readonly DidWebDocumentFetcher $fetcher;

    public function __construct(
        HttpClientInterface $client,
        private readonly SigningKeyManagerInterface $keys,
        private readonly LoggerInterface $logger,
        ?\Closure $dnsResolver = null,
    ) {
        $this->fetcher = new DidWebDocumentFetcher($client, $logger, $dnsResolver);
    }

    public function publicKeyPemFor(string $verificationMethod): ?string
    {
        if (\array_key_exists($verificationMethod, $this->memo)) {
            return $this->memo[$verificationMethod];
        }

        return $this->memo[$verificationMethod] = $this->resolve($verificationMethod);
    }

    private function resolve(string $verificationMethod): ?string
    {
        $did = DidWebUrl::didOf($verificationMethod);
        $url = $did === null ? null : DidWebUrl::forDid($did);
        if ($url === null) {
            return null;
        }

        $document = $this->fetcher->fetch($url);
        $normalized = $document === null ? null : self::normalizedJwk($document, $verificationMethod);

        return $normalized === null ? null : $this->toPem($normalized, $verificationMethod);
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<string, string>|null
     */
    private static function normalizedJwk(array $document, string $verificationMethod): ?array
    {
        $jwk = DidWebJwk::pick($document, $verificationMethod);

        return $jwk === null ? null : DidWebJwk::normalize($jwk, $verificationMethod);
    }

    /**
     * @param array<string, string> $normalized
     */
    private function toPem(array $normalized, string $verificationMethod): ?string
    {
        try {
            return $this->keys->publicKeyFromJwk($normalized)->publicKeyPem;
        } catch (\Throwable $error) {
            $this->logger->info('A2CN could not read a did:web public key.', [
                'verificationMethod' => $verificationMethod,
                'exception' => $error,
            ]);

            return null;
        }
    }
}
