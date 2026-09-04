<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Fetches and JSON-decodes one did:web document over HTTP.
 *
 * Split out of DidWebResolver: this is transport (GET, size cap, decode), a
 * different concern from resolving a verification method to a PEM. Every
 * failure path returns null rather than throwing, for the same reason as the
 * resolver — an unreachable document is evidence, not a control-flow error.
 */
final readonly class DidWebDocumentFetcher
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_DOCUMENT_BYTES = 262_144;

    public function __construct(
        private ClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /** @return array<array-key, mixed>|null */
    public function fetch(string $url): ?array
    {
        $body = $this->body($url);

        return $body === null ? null : $this->decode($url, $body);
    }

    private function body(string $url): ?string
    {
        try {
            $response = $this->client->request('GET', $url, [
                RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);
        } catch (\Throwable $error) {
            $this->logger->info('A2CN could not fetch a did:web document.', ['url' => $url, 'exception' => $error]);

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = $response->getBody()->read(self::MAX_DOCUMENT_BYTES + 1);
        if (\strlen($body) > self::MAX_DOCUMENT_BYTES) {
            $this->logger->info('A2CN refused an oversized did:web document.', ['url' => $url]);

            return null;
        }

        return $body;
    }

    /** @return array<array-key, mixed>|null */
    private function decode(string $url, string $body): ?array
    {
        $decoded = json_decode($body, associative: true);
        if (!\is_array($decoded)) {
            $this->logger->info('A2CN could not decode a did:web document.', ['url' => $url]);

            return null;
        }

        return $decoded;
    }
}
