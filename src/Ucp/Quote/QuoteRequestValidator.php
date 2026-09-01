<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

use Ucp\Sdk\Exception\ValidationException;

/**
 * Translates the public quote payload into the narrow values accepted by the
 * application layer. JSON types are checked here, at the HTTP boundary, before
 * PHP coercion can turn malformed input into a different quote.
 *
 * The per-line-item rules live in {@see QuoteLineItemValidator}: this class
 * only owns the payload's shape (an object list) and delegates each item.
 */
final class QuoteRequestValidator
{
    public function __construct(
        private readonly QuoteLineItemValidator $lineItemValidator,
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public function lineItems(array $payload, bool $required): array
    {
        $lineItems = $payload['line_items'] ?? [];

        if (!\is_array($lineItems) || !array_is_list($lineItems)) {
            throw new ValidationException('Line items must be an array.', ['$.line_items must be an array']);
        }

        foreach ($lineItems as $index => $lineItem) {
            if (!\is_array($lineItem) || array_is_list($lineItem)) {
                throw new ValidationException('Each line item must be an object.', [
                    \sprintf('$.line_items[%d] must be an object', $index),
                ]);
            }

            /** @var array<string, mixed> $lineItem */
            $this->lineItemValidator->validate($lineItem, $index, $required);
        }

        /** @var list<array<string, mixed>> $lineItems */
        return $lineItems;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function comment(array $payload): ?string
    {
        if (!\array_key_exists('comment', $payload)) {
            return null;
        }

        if (!\is_string($payload['comment'])) {
            throw new ValidationException('Comment must be a string.', ['$.comment must be a string']);
        }

        return $payload['comment'];
    }
}
