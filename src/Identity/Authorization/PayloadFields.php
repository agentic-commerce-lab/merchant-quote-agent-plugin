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

        if (!\is_string($value) || $value === '') {
            throw new ValidationException(\sprintf('"%s" is required.', $key), [\sprintf(
                '$.%s must be a non-empty string',
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
