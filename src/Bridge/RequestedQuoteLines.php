<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Ucp\Sdk\Exception\ValidationException;

/**
 * One quote request's line items, split into what to ADD to the cart and what
 * price to ASK for.
 *
 * The split exists because two callers mean two different things by a line
 * item. A buyer agent over UCP arrives with no cart, so every line is an
 * addition. The shopping assistant's buyer already filled the cart through the
 * starter kit's `add_to_cart`, so its lines carry a price ask and nothing to
 * add — re-sending them with a quantity would double every line.
 *
 * A line with no `quantity` is therefore price-only, and a request with no
 * lines at all is legal and means "quote what is already in the cart". What
 * neither may do is be empty of both: the cart emptiness check lives in the
 * gateway, after the additions land, because only there is the answer known.
 */
final readonly class RequestedQuoteLines
{
    /**
     * @param list<array{product_id: string, quantity: int}> $additions
     * @param array<string, float> $requestedPrices
     */
    private function __construct(
        public array $additions,
        public array $requestedPrices,
    ) {}

    /**
     * @param list<array{product_id?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems
     *
     * @throws ValidationException
     */
    public static function from(array $lineItems, CommercialQuoteLinePricing $pricing): self
    {
        $additions = [];
        $requestedPrices = [];

        foreach ($lineItems as $index => $lineItem) {
            /** @mago-expect analysis:possibly-invalid-argument */
            $productId = $pricing->requireProductId($lineItem, $index);

            /** @mago-expect analysis:possibly-invalid-argument */
            $requestedPrice = $pricing->requestedPrice($lineItem, \sprintf(
                '$.line_items[%d].requested_unit_price',
                $index,
            ));

            if (null !== $requestedPrice) {
                $pricing->assertCanPriceLines();
                $requestedPrices[$productId] = $requestedPrice;
            }

            $quantity = $lineItem['quantity'] ?? null;

            // Absent is the assistant's "already in the cart". Present but not
            // a positive integer is a caller that meant something and got it
            // wrong, which is worth an error rather than a silent drop.
            if (null === $quantity) {
                if (null === $requestedPrice) {
                    throw new ValidationException('A quote line item must add a quantity, ask a price, or both.', [\sprintf(
                        '$.line_items[%d] needs quantity or requested_unit_price',
                        $index,
                    )]);
                }

                continue;
            }

            if (!\is_int($quantity) || $quantity < 1) {
                throw new ValidationException('Line item quantity must be a positive integer.', [\sprintf(
                    '$.line_items[%d].quantity must be >= 1',
                    $index,
                )]);
            }

            $additions[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return new self($additions, $requestedPrices);
    }
}
