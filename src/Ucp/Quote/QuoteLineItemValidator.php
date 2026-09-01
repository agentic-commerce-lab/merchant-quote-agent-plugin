<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

use Ucp\Sdk\Exception\ValidationException;

/**
 * Per-line-item rules for {@see QuoteRequestValidator::lineItems()}, split out
 * so neither class trips the cyclomatic-complexity gate: a request line item
 * and a counter line item identify themselves differently (product_id and
 * quantity vs. an existing id and a requested price), but both share the same
 * price check once a requested price is present. The field-level primitives
 * live in {@see QuoteFieldAssertions} for the same reason.
 */
final class QuoteLineItemValidator
{
    private readonly QuoteFieldAssertions $assertions;

    public function __construct()
    {
        $this->assertions = new QuoteFieldAssertions();
    }

    /**
     * @param array<string, mixed> $lineItem
     */
    public function validate(array $lineItem, int $index, bool $required): void
    {
        if ($required) {
            $this->assertions->requiredNonEmptyString(
                $lineItem,
                'product_id',
                \sprintf('$.line_items[%d].product_id', $index),
            );
            $this->assertions->positiveInteger($lineItem, 'quantity', \sprintf('$.line_items[%d].quantity', $index));
        } else {
            $this->validateCounterIdentity($lineItem, $index);
        }

        $this->validatePrice($lineItem, $index);
    }

    /**
     * @param array<string, mixed> $lineItem
     */
    private function validateCounterIdentity(array $lineItem, int $index): void
    {
        $this->assertions->optionalNonEmptyString($lineItem, 'id', \sprintf('$.line_items[%d].id', $index));
        $this->assertions->optionalNonEmptyString(
            $lineItem,
            'product_id',
            \sprintf('$.line_items[%d].product_id', $index),
        );

        if (!\array_key_exists('id', $lineItem) && !\array_key_exists('product_id', $lineItem)) {
            throw new ValidationException('A counter line item must identify an existing quote line.', [
                \sprintf('$.line_items[%d].id or product_id is required', $index),
            ]);
        }

        if (!\array_key_exists('requested_unit_price', $lineItem)) {
            throw new ValidationException('A counter line item requires a requested unit price.', [
                \sprintf('$.line_items[%d].requested_unit_price is required', $index),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $lineItem
     */
    private function validatePrice(array $lineItem, int $index): void
    {
        if (!\array_key_exists('requested_unit_price', $lineItem)) {
            return;
        }

        $price = $lineItem['requested_unit_price'];

        if (!\is_int($price) && !\is_float($price)) {
            throw new ValidationException('Requested unit price must be a number.', [
                \sprintf('$.line_items[%d].requested_unit_price must be a number', $index),
            ]);
        }

        if (!is_finite((float) $price) || $price < 0) {
            throw new ValidationException('Requested unit price must be a finite non-negative number.', [
                \sprintf('$.line_items[%d].requested_unit_price must be >= 0', $index),
            ]);
        }
    }
}
