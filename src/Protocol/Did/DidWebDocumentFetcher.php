<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
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
        private HttpClientInterface $client,
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
            return $this->readBody($url);
        } catch (\Throwable $error) {
            $this->logger->info('A2CN could not fetch a did:web document.', ['url' => $url, 'exception' => $error]);

            return null;
        }
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     *   Left undocumented at request() and getStatusCode() themselves, so it
     *   propagates here — caught by body(), which wraps this call.
     */
    private function readBody(string $url): ?string
    {
        $response = $this->client->request('GET', $url, [
            'timeout' => self::TIMEOUT_SECONDS,
            // Total wall-clock budget, not just inactivity: without this, a
            // slow-drip response (bytes trickling in just fast enough to
            // reset the inactivity timer) could hold the request open
            // indefinitely.
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
        ]);

        // getStatusCode() does not throw on 4xx/5xx — only getHeaders() and
        // getContent() do, when called without `false`. Treating a non-200 as
        // a plain null result (rather than an exception) falls out of simply
        // never calling those.
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        return $this->readWithinCap($url, $response);
    }

    /**
     * Reads the body incrementally via the client's streaming API rather than
     * $response->getContent(), which buffers the whole response first: without
     * this, MAX_DOCUMENT_BYTES only trims what is read back out of an
     * already-fully-downloaded body, so an attacker-controlled host could
     * still push an unbounded body into memory.
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     *   Propagates from stream()/chunk::getContent(); caught by body(), which
     *   wraps the call chain that reaches here via readBody().
     */
    private function readWithinCap(string $url, ResponseInterface $response): ?string
    {
        $body = '';
        foreach ($this->client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
            if (\strlen($body) > self::MAX_DOCUMENT_BYTES) {
                $this->logger->info('A2CN refused an oversized did:web document.', ['url' => $url]);

                return null;
            }
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
