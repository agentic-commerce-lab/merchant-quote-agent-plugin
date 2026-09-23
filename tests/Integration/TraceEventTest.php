<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The migration and the entity agree. CI never executes migration SQL, so
 * this is the first place the CREATE TABLE meets a real MySQL 8.
 */
final class TraceEventTest extends IntegrationTestCase
{
    public function testTheTableExistsAndTheEntityRoundTripsItsJson(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_trace.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = Uuid::randomHex();
        $repository->create([[
            'id' => $id,
            'decisionId' => Uuid::randomHex(),
            'quoteId' => Uuid::randomHex(),
            'customerId' => Uuid::randomHex(),
            'kind' => 'model_call',
            'position' => 3,
            'occurredAt' => new \DateTimeImmutable('2031-05-05 12:00:00.123'),
            'meta' => ['purpose' => 'extract', 'retries' => [['httpStatus' => 503, 'transportError' => false]]],
            'content' => ['request' => ['messages' => [['role' => 'user', 'content' => 'Grüße, 5% bitte']]]],
        ]], Context::createDefaultContext());

        $written = $repository->search(new Criteria([$id]), Context::createDefaultContext())->first();

        self::assertNotNull($written, 'The trace row was not written.');
        self::assertSame('model_call', $written->kind);
        self::assertSame(3, $written->position);
        self::assertSame('extract', $written->meta['purpose'] ?? null);
        self::assertSame(
            'Grüße, 5% bitte',
            $written->content['request']['messages'][0]['content'] ?? null,
            'Multibyte text must survive the JSON column unchanged.',
        );
        self::assertSame('123', $written->occurredAt?->format('v'), 'occurred_at must keep milliseconds.');
    }
}
