<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineItemWriter;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * The DAL rejects an unknown field outright, so a `deletedAt` write against a
 * released SwagCommercial is not a silent no-op — it throws. The two profiles
 * therefore issue genuinely different operations, which is what these assert.
 *
 * The repository is a mock rather than a live one because the assertion is
 * about which operation is issued with what payload; QuoteLineItemWriteTest in
 * the integration suite covers that the operation actually lands.
 */
final class QuoteLineItemWriterTest extends TestCase
{
    public function testAModernShopSoftDeletesThroughUpdate(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('update')
            ->with(self::callback(static function (array $payload): bool {
                self::assertCount(1, $payload);
                self::assertSame('line-1', $payload[0]['id']);
                self::assertArrayHasKey('deletedAt', $payload[0]);

                return true;
            }));
        $repository->expects(self::never())->method('delete');

        (new QuoteLineItemWriter($repository, CommercialCapabilities::modern()))->write([new QuoteLineItemChange(
            lineItemId: 'line-1',
            remove: true,
        )], Context::createDefaultContext());
    }

    public function testALegacyShopDeletesTheRow(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');
        $repository
            ->expects(self::once())
            ->method('delete')
            ->with([['id' => 'line-1']]);

        (new QuoteLineItemWriter($repository, CommercialCapabilities::legacy()))->write([new QuoteLineItemChange(
            lineItemId: 'line-1',
            remove: true,
        )], Context::createDefaultContext());
    }

    public function testALegacyShopStillBatchesQuantityChangesIntoUpdate(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::once())
            ->method('update')
            ->with([['id' => 'line-2', 'quantity' => 5]]);
        $repository
            ->expects(self::once())
            ->method('delete')
            ->with([['id' => 'line-1']]);

        (new QuoteLineItemWriter($repository, CommercialCapabilities::legacy()))->write([
            new QuoteLineItemChange(lineItemId: 'line-1', remove: true),
            new QuoteLineItemChange(lineItemId: 'line-2', quantity: 5),
        ], Context::createDefaultContext());
    }

    public function testNothingIsIssuedForAnEmptyChangeSet(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('delete');

        (new QuoteLineItemWriter($repository, CommercialCapabilities::legacy()))->write(
            [],
            Context::createDefaultContext(),
        );
    }
}
