<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteLinePricing;
use MerchantQuoteAgentPlugin\Bridge\RequestedQuoteLines;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The split a quote request makes before it touches a cart: which lines get
 * ADDED, and which only carry a price ask for a line that is already there.
 *
 * The second kind is what the shopping assistant sends. Its buyer filled the
 * cart through the starter kit's `add_to_cart`, so re-sending those lines with
 * a quantity would double every one of them.
 */
final class RequestedQuoteLinesTest extends TestCase
{
    public function testALineWithAQuantityIsAnAddition(): void
    {
        $lines = RequestedQuoteLines::from([['product_id' => 'prod-1', 'quantity' => 3]], $this->pricing());

        self::assertSame([['product_id' => 'prod-1', 'quantity' => 3]], $lines->additions);
        self::assertSame([], $lines->requestedPrices);
    }

    public function testALineWithoutAQuantityIsAPriceAskOnly(): void
    {
        $lines = RequestedQuoteLines::from([[
            'product_id' => 'prod-1',
            'requested_unit_price' => '98.00',
        ]], $this->pricing());

        self::assertSame([], $lines->additions);
        self::assertSame(['prod-1' => 98.00], $lines->requestedPrices);
    }

    public function testALineCanBothAddAndAsk(): void
    {
        $lines = RequestedQuoteLines::from([[
            'product_id' => 'prod-1',
            'quantity' => 2,
            'requested_unit_price' => '98.00',
        ]], $this->pricing());

        self::assertSame([['product_id' => 'prod-1', 'quantity' => 2]], $lines->additions);
        self::assertSame(['prod-1' => 98.00], $lines->requestedPrices);
    }

    public function testAnEmptyRequestIsLegalAndAddsNothing(): void
    {
        $lines = RequestedQuoteLines::from([], $this->pricing());

        self::assertSame([], $lines->additions);
        self::assertSame([], $lines->requestedPrices);
    }

    /**
     * A quantity that is present must still be a real quantity. Absent means
     * "already in the cart"; zero or negative means the caller is confused,
     * and silently dropping it would quote something the buyer never asked
     * for.
     */
    public function testAZeroQuantityIsRejectedRatherThanTreatedAsAbsent(): void
    {
        $this->expectException(ValidationException::class);

        RequestedQuoteLines::from([['product_id' => 'prod-1', 'quantity' => 0]], $this->pricing());
    }

    /**
     * `quantity` is untrusted model-supplied JSON, so a numeric string is not
     * a quantity: `is_int()` guards against exactly this, and a stringly
     * "3" quietly widening the cart by a JSON quirk would be worse than
     * refusing it outright.
     */
    public function testANonIntQuantityIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        RequestedQuoteLines::from([['product_id' => 'prod-1', 'quantity' => '3']], $this->pricing());
    }

    public function testALineThatNeitherAddsNorAsksIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        RequestedQuoteLines::from([['product_id' => 'prod-1']], $this->pricing());
    }

    public function testAMissingProductIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        RequestedQuoteLines::from([['quantity' => 1]], $this->pricing());
    }

    private function pricing(): CommercialQuoteLinePricing
    {
        return new CommercialQuoteLinePricing(new \stdClass());
    }
}
