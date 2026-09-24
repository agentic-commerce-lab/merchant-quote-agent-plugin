<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\Export\DecisionExportStream;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\ServicingJournal;
use MerchantQuoteAgentPlugin\Servicing\SkipContext;
use MerchantQuoteAgentPlugin\Servicing\SkipReason;
use MerchantQuoteAgentPlugin\Servicing\SkipSource;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class OutsidePassExportTest extends IntegrationTestCase
{
    private const DAY = '2031-06-07';

    public function testStandaloneEventsFollowDecisionsAndRespectTheFreeTextGate(): void
    {
        $decision = $this->seedDecision();
        [$id, $quote, $customer] = $this->seedEvent(self::DAY . 'T12:00:00+00:00');
        $pseudonym = $this->pseudonym();

        $rows = $this->export()[0];
        self::assertSame('decision', $rows[0]['record']);
        self::assertSame($pseudonym->of($decision), $rows[0]['id']);
        $event = $this->eventRow($rows, $pseudonym->of($id));
        self::assertSame('event', $event['record']);
        self::assertSame($pseudonym->of($quote), $event['quote']);
        self::assertSame($pseudonym->of($customer), $event['customer']);
        self::assertSame('skip', $event['kind']);
        self::assertSame('2031-06-07T12:00:00.000+00:00', $event['occurredAt']);
        self::assertEqualsCanonicalizing(
            ['source' => 'handler', 'reason' => 'lock_busy', 'trigger' => null, 'attempt' => null],
            $event['meta'],
        );
        self::assertArrayNotHasKey('content', $event);

        $full = $this->eventRow($this->export(freeText: true)[0], $pseudonym->of($id));
        self::assertSame(['note' => 'buyer said hello', 'quote' => $pseudonym->of($quote)], $full['content']);
    }

    public function testOutcomeFilterOmitsStandaloneEventsAndReportsTheOmission(): void
    {
        $this->seedDecision();
        $this->seedEvent(self::DAY . 'T12:00:00+00:00');

        [$rows, $notices] = $this->export(outcome: 'offered');
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertSame('decision', $row['record']);
        }
        self::assertStringContainsString('event', implode(' ', $notices));
        self::assertStringContainsString('omitted', implode(' ', $notices));
    }

    public function testStandaloneEventRangeIsHalfOpenOnOccurredAt(): void
    {
        [$inId] = $this->seedEvent(self::DAY . 'T00:00:00+00:00');
        [$outId] = $this->seedEvent('2031-06-08T00:00:00+00:00');
        $pseudonym = $this->pseudonym();
        $ids = array_column($this->export()[0], 'id');

        self::assertContains($pseudonym->of($inId), $ids);
        self::assertNotContains($pseudonym->of($outId), $ids);
    }

    public function testAViewerWithTraceReadPermissionCanExportStandaloneEvents(): void
    {
        [$id] = $this->seedEvent(self::DAY . 'T12:00:00+00:00');
        $source = new AdminApiSource(Uuid::randomHex());
        $source->setIsAdmin(false);
        $source->setPermissions(['merchant_quote_agent_decision:read', 'merchant_quote_agent_trace:read']);
        $stream = static::getContainer()->get(DecisionExportStream::class);
        self::assertInstanceOf(DecisionExportStream::class, $stream);

        $lines = iterator_to_array(
            $stream->lines(
                new \DateTimeImmutable(self::DAY),
                new \DateTimeImmutable('2031-06-08'),
                false,
                new Context($source),
            ),
            false,
        );
        $rows = array_map(static fn(string $line): array => json_decode(
            $line,
            true,
            flags: JSON_THROW_ON_ERROR,
        ), $lines);

        self::assertSame('event', $this->eventRow($rows, $this->pseudonym()->of($id))['record']);
    }

    public function testContainerJournalPersistsASkipWithNoDecisionLink(): void
    {
        $quoteId = Uuid::randomHex();
        $journal = static::getContainer()->get(ServicingJournal::class);
        self::assertInstanceOf(ServicingJournal::class, $journal);
        $journal->skip(
            SkipSource::Handler,
            SkipReason::LockBusy,
            SkipContext::forMessage(new ServiceQuoteMessage($quoteId, 'comment_written')),
        );

        $repository = static::getContainer()->get('merchant_quote_agent_trace.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);
        $criteria = (new Criteria())->addFilter(new EqualsFilter('quoteId', $quoteId));
        $events = $repository->search($criteria, Context::createDefaultContext())->getEntities();
        self::assertCount(1, $events);
        $event = $events->first();
        self::assertSame('skip', $event?->kind);
        self::assertNull($event?->decisionId);
        self::assertNull($event?->position);
        self::assertNull($event?->content);
    }

    /** @return array{string, string, string} */
    private function seedEvent(string $occurredAt): array
    {
        $id = Uuid::randomHex();
        $quote = Uuid::randomHex();
        $customer = Uuid::randomHex();
        $repository = static::getContainer()->get('merchant_quote_agent_trace.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);
        $repository->create([[
            'id' => $id,
            'decisionId' => null,
            'quoteId' => $quote,
            'customerId' => $customer,
            'kind' => 'skip',
            'position' => null,
            'occurredAt' => $occurredAt,
            'meta' => [
                'source' => 'handler',
                'reason' => 'lock_busy',
                'trigger' => null,
                'attempt' => null,
                'private' => 'hidden',
            ],
            'content' => ['note' => 'buyer said hello', 'quote' => $quote],
        ]], Context::createDefaultContext());

        return [$id, $quote, $customer];
    }

    private function seedDecision(): string
    {
        $id = Uuid::randomHex();
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);
        $repository->create([[
            'id' => $id,
            'quoteId' => Uuid::randomHex(),
            'customerId' => Uuid::randomHex(),
            'salesChannelId' => Uuid::randomHex(),
            'currencyIso' => 'EUR',
            'triggerReason' => 'comment_written',
            'attempt' => 0,
            'outcome' => 'offered',
            'createdAt' => self::DAY . 'T10:00:00+00:00',
        ]], Context::createDefaultContext());

        return $id;
    }

    private function pseudonym(): ExportPseudonym
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        return ExportPseudonym::forShop($config);
    }

    /** @return array{list<array<string, mixed>>, list<string>} */
    private function export(bool $freeText = false, string $outcome = ''): array
    {
        $stream = static::getContainer()->get(DecisionExportStream::class);
        self::assertInstanceOf(DecisionExportStream::class, $stream);
        $lines = $stream->lines(
            new \DateTimeImmutable(self::DAY),
            new \DateTimeImmutable('2031-06-08'),
            $freeText,
            outcome: $outcome,
        );
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        return [$rows, $lines->getReturn()];
    }

    /** @param list<array<string, mixed>> $rows
     *  @return array<string, mixed>
     */
    private function eventRow(array $rows, ?string $id): array
    {
        foreach ($rows as $row) {
            if ($row['record'] === 'event' && $row['id'] === $id) {
                return $row;
            }
        }

        self::fail('The seeded standalone event was absent from the export.');
    }
}
