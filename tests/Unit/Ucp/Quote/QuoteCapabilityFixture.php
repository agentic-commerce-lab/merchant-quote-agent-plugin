<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp\Quote;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;

/**
 * The snapshot QuoteCapabilityTest's delegation tests pass through the
 * gateway. Separate from the test class so that class stays under mago's
 * too-many-methods ceiling.
 */
final class QuoteCapabilityFixture
{
    private function __construct() {}

    public static function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            'quote-id',
            '1030',
            'replied',
            '2026-08-15T00:00:00+00:00',
            'EUR',
            119.0,
            100.0,
            'gross',
            [[
                'id' => 'line-item-id',
                'product_id' => 'product-id',
                'label' => 'Main product',
                'quantity' => 10,
                'unit_price' => 11.9,
                'total_price' => 119.0,
                'requested_unit_price' => 9.5,
            ]],
            [[
                'comment' => 'volume pricing please',
                'author' => 'buyer',
                'created_at' => '2026-07-27T10:00:00+00:00',
            ]],
        );
    }
}
