<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Framework\Uuid\Uuid;

final class DecisionRecordTest extends IntegrationTestCase
{
    public function testTheTableExistsAndTheEntityIsRegistered(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');

        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = Uuid::randomHex();
        $repository->create([[
            'id' => $id,
            'quoteId' => Uuid::randomHex(),
            'salesChannelId' => Uuid::randomHex(),
            'quoteNumber' => '10001',
            'currencyIso' => 'EUR',
            'triggerReason' => 'comment_written',
            'attempt' => 0,
            'outcome' => 'offered',
            'band' => 'grant',
            'durationMs' => 1234,
        ]], Context::createDefaultContext());

        $written = $repository->search(new Criteria([$id]), Context::createDefaultContext())->first();

        self::assertNotNull($written, 'The record was not written.');
        self::assertSame('offered', $written->outcome);
        self::assertSame(1234, $written->durationMs);
    }

    public function testAStringLongerThanItsColumnIsRejectedAtWriteTime(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');

        self::assertInstanceOf(EntityRepository::class, $repository);

        $this->expectException(WriteException::class);

        $repository->create([[
            'id' => Uuid::randomHex(),
            'quoteId' => Uuid::randomHex(),
            'band' => str_repeat('x', times: 33),
        ]], Context::createDefaultContext());
    }
}
