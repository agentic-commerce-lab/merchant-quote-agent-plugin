<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\NullQuoteServicingPipeline;
use PHPUnit\Framework\TestCase;

final class NullQuoteServicingPipelineTest extends TestCase
{
    public function testNullPipelineExecutesWithoutError(): void
    {
        $pipeline = new NullQuoteServicingPipeline();
        $snapshot = new QuoteSnapshot(
            identity: new QuoteIdentity('quote-1', 'quote-num-1', 'EUR', 'sales-channel-1'),
            totals: new QuoteTotals(100.0, null),
            lifecycle: new QuoteLifecycle('open', null, []),
            content: new QuoteContent([], [], null),
            revision: new QuoteRevision(
                '018b449b2ba170a4a589cf8cb59a35e4',
                new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
            ),
        );

        $gateway = $this->createMock(QuoteGatewayInterface::class);

        // Expect no calls on the mock gateway in null pipeline
        $gateway->expects(self::never())->method(self::anything());

        $pipeline->service($snapshot, $gateway);
        self::assertTrue(true);
    }
}
