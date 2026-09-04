<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;

/**
 * Fetches and JSON-decodes one did:web document over HTTP.
 *
 * Split out of DidWebResolver: this is transport (safety gate, GET, size cap,
 * decode), a different concern from resolving a verification method to a PEM.
 * Every failure path returns null rather than throwing, for the same reason as
 * the resolver — an unreachable or unsafe document is evidence, not a
 * control-flow error.
 *
 * The URL comes from a counterparty-controlled DID, so it is checked against
 * the SDK's own `UrlSafetyValidator` before this fetches anything — the same
 * `@internal` class AgentProfileHostValidatorFactory already depends on for
 * agent-profile fetches, with the same trade-off (see that class's docblock).
 * It is built fresh per call with the URL's OWN host as its one-entry
 * allowlist: with an empty allowlist it refuses every host outright, and the
 * identity gate it otherwise enforces does not apply here — any counterparty
 * domain is legitimate in A2CN. Every other check still runs unchanged:
 * https-only, ports restricted to 443/8443, the blocked metadata hosts,
 * userinfo rejection, and DNS resolution with private/reserved-range
 * rejection.
 */
final readonly class DidWebDocumentFetcher
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_DOCUMENT_BYTES = 262_144;

    public function __construct(
        private ClientInterface $client,
        private LoggerInterface $logger,
        /**
         * Test-only DNS override: `buyer.example`-style fixture hosts do not
         * resolve on a real network, so tests stub this the same way the UCP
         * SDK's own HttpAgentProfileFetcherTest does. Null in production, which
         * is the real system resolver.
         */
        private ?\Closure $dnsResolver = null,
    ) {}

    /** @return array<array-key, mixed>|null */
    public function fetch(string $url): ?array
    {
        if (!$this->isSafe($url)) {
            return null;
        }

        $body = $this->body($url);

        return $body === null ? null : $this->decode($url, $body);
    }

    private function isSafe(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host)) {
            return false;
        }

        try {
            (new UrlSafetyValidator([$host], $this->dnsResolver))->assertAllowed($url);

            return true;
        } catch (ValidationException $error) {
            $this->logger->info('A2CN refused an unsafe did:web URL.', ['url' => $url, 'exception' => $error]);

            return false;
        }
    }

    private function body(string $url): ?string
    {
        try {
            $response = $this->client->request('GET', $url, [
                RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::CONNECT_TIMEOUT => self::TIMEOUT_SECONDS,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                // Read incrementally rather than let Guzzle buffer the whole
                // response first: without this, MAX_DOCUMENT_BYTES only trims
                // what is read back out of an already-fully-downloaded body.
                RequestOptions::STREAM => true,
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
