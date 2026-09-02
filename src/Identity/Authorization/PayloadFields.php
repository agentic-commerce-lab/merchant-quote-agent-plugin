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
 *
 * `redirect_uri` is NOT here: its rules mirror Agentic Commerce's client
 * binding and are a security check in their own right, so they live in
 * {@see RedirectUriRule} — together the two exceed the class-level
 * cyclomatic-complexity gate anyway.
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
     * An S256 PKCE challenge is the base64url encoding of a SHA-256 digest, so
     * it is always exactly 43 unpadded base64url characters (RFC 7636 §4.2).
     * Anything else cannot be a challenge the agent will be able to answer, and
     * the mismatch would surface at the token endpoint minutes later, after a
     * human has already consented. AC's own consent path checks the shape for
     * the same reason; the registrar is the earliest place we can.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    public function requiredCodeChallenge(array $payload, string $key): string
    {
        $value = $this->requiredString($payload, $key);

        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $value) !== 1) {
            throw new ValidationException(
                \sprintf('"%s" must be a 43-character base64url-encoded S256 challenge.', $key),
                [\sprintf('$.%s must be 43 base64url characters', $key)],
            );
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
