<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
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
 * normalized (DidWebJwk::normalize) before it reaches the SDK, and only P-256
 * is accepted.
 *
 * The HTTP fetch (DidWebDocumentFetcher) and the document parsing (DidWebJwk)
 * are split out: different concerns from what is left here — orchestration,
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
        ClientInterface $client,
        private readonly SigningKeyManagerInterface $keys,
        private readonly LoggerInterface $logger,
    ) {
        $this->fetcher = new DidWebDocumentFetcher($client, $logger);
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
        $jwk = $document === null ? null : DidWebJwk::pick($document, $verificationMethod);

        return $jwk === null ? null : $this->toPem($jwk, $verificationMethod);
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function toPem(array $jwk, string $verificationMethod): ?string
    {
        $curve = $jwk['crv'] ?? null;
        if ($curve !== 'P-256') {
            $this->logger->info('A2CN found a did:web key on an unsupported curve.', [
                'verificationMethod' => $verificationMethod,
            ]);

            return null;
        }

        try {
            return $this->keys->publicKeyFromJwk(DidWebJwk::normalize($jwk, $verificationMethod))->publicKeyPem;
        } catch (\Throwable $error) {
            $this->logger->info('A2CN could not read a did:web public key.', [
                'verificationMethod' => $verificationMethod,
                'exception' => $error,
            ]);

            return null;
        }
    }
}
