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
     * The two shapes below are what {@see QuoteLineItemValidator} and
     * {@see QuoteFieldAssertions} actually enforce, one per `$required`
     * branch: `product_id` a non-empty string and `quantity` a positive int
     * for a quote request; `id`/`product_id` optionally non-empty strings,
     * at least one present, for a counter-offer. `requested_unit_price`, in
     * either case, is a non-negative number if present at all.
     *
     * @param array<string, mixed> $payload
     *
     * @return ($required is true
     *     ? list<array{product_id?: string, quantity?: int, requested_unit_price?: float|int|string}>
     *     : list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}>)
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

        /** @var list<array{id?: string, product_id?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems */
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
