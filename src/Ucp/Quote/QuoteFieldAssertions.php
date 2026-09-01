<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

use Ucp\Sdk\Exception\ValidationException;

/**
 * The field-level primitives {@see QuoteLineItemValidator} builds its rules
 * from, split out so that class stays under the cyclomatic-complexity gate.
 */
final class QuoteFieldAssertions
{
    /**
     * @param array<string, mixed> $values
     */
    public function requiredNonEmptyString(array $values, string $key, string $path): void
    {
        if (!\array_key_exists($key, $values) || !\is_string($values[$key]) || '' === trim($values[$key])) {
            throw new ValidationException(
                \sprintf('%s is required.', ucfirst(str_replace(search: '_', replace: ' ', subject: $key))),
                [$path . ' must be a non-empty string'],
            );
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public function optionalNonEmptyString(array $values, string $key, string $path): void
    {
        if (\array_key_exists($key, $values) && (!\is_string($values[$key]) || '' === trim($values[$key]))) {
            throw new ValidationException(
                \sprintf(
                    '%s must be a non-empty string.',
                    ucfirst(str_replace(search: '_', replace: ' ', subject: $key)),
                ),
                [$path . ' must be a non-empty string'],
            );
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public function positiveInteger(array $values, string $key, string $path): void
    {
        if (!\array_key_exists($key, $values) || !\is_int($values[$key]) || $values[$key] < 1) {
            throw new ValidationException(
                \sprintf('%s must be a positive integer.', ucfirst($key)),
                [$path . ' must be an integer >= 1'],
            );
        }
    }
}
