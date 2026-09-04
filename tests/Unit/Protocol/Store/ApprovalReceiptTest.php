<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Store;

use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use PHPUnit\Framework\TestCase;

final class ApprovalReceiptTest extends TestCase
{
    public function testItRoundTripsThroughItsWireShape(): void
    {
        $receipt = new ApprovalReceipt('session:hash', 'hash', 'discount above 15%', '2026-09-04T10:00:00+00:00');

        self::assertSame(
            [
                'approval_receipt_id' => 'session:hash',
                'offer_hash' => 'hash',
                'threshold_crossed' => 'discount above 15%',
                'approved_at' => '2026-09-04T10:00:00+00:00',
            ],
            $receipt->toArray(),
        );

        self::assertEquals($receipt, ApprovalReceipt::fromArray($receipt->toArray()));
    }

    public function testItRefusesAnUnreadableRow(): void
    {
        self::assertNull(ApprovalReceipt::fromArray(['offer_hash' => 'hash']));
    }
}
