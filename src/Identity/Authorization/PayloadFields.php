<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Ucp\Sdk\Exception\ValidationException;

/**
 * The payload-field primitives {@see AgentAuthorizationRegistrar} builds its
 * checks from, split out to keep the registrar within the project's
 * maintainability gates — the same reason src/Ucp/Quote/QuoteFieldAssertions.php
 * exists. Not reused directly: that class's assertions return void where the
 * registrar needs the value back, and it lives in `Ucp\Quote`, which would
 * couple identity linking to quotes — the design doc for this flow never
 * mentions quotes, so a sibling here keeps that seam clean.
 */
final class PayloadFields
{
    /**
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    public function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        $value = \is_string($value) ? trim($value) : $value;

        if (!\is_string($value) || $value === '') {
            throw new ValidationException(\sprintf('"%s" is required.', $key), [\sprintf(
                '$.%s must be a non-empty string',
                $key,
            )]);
        }

        return $value;
    }

    /**
     * A `redirect_uri` gets no signature-verification pass of its own: the
     * grant path is saved by AC re-checking it inside authorize(), but denial
     * never reaches AC, so a bad value stored here makes the shop redirect a
     * browser wherever a verified agent asked — this is that fail-fast check,
     * against exactly what OAuth requires of a redirect URI: an http(s) scheme
     * and no fragment (a fragment is never sent to a server, so a query string
     * appended after one — as the denial redirect does — never reaches the
     * agent, and the shop reports success against a URL nobody receives).
     *
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    public function requiredRedirectUri(array $payload, string $key): string
    {
        $value = $this->requiredString($payload, $key);
        $parts = parse_url($value);

        if (!\is_array($parts) || !\in_array($parts['scheme'] ?? null, ['http', 'https'], strict: true)) {
            throw new ValidationException(\sprintf('"%s" must use the http or https scheme.', $key), [\sprintf(
                '$.%s must be an http(s) URL',
                $key,
            )]);
        }

        if (\array_key_exists('fragment', $parts)) {
            throw new ValidationException(\sprintf('"%s" must not include a fragment.', $key), [\sprintf(
                '$.%s must not include a fragment',
                $key,
            )]);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    public function optionalString(array $payload, string $key): string
    {
        if (!\array_key_exists($key, $payload)) {
            return '';
        }

        $value = $payload[$key];

        if (!\is_string($value)) {
            throw new ValidationException(\sprintf('"%s" must be a string.', $key), [\sprintf(
                '$.%s must be a string',
                $key,
            )]);
        }

        return $value;
    }
}
