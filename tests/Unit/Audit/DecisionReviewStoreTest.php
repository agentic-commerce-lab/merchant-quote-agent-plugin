<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStore;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

final class DecisionReviewStoreTest extends TestCase
{
    public function testSupersedingMarksPendingRowsAndHandsBackTheirVersions(): void
    {
        $pending = self::record('pending', '0190aaaa0000700080000000000000aa');
        $pending->outcome = 'offered';
        $repository = self::repository([$pending]);

        $superseded = (new DecisionReviewStore($repository))->supersedePending('quote-1');

        self::assertSame([['versionId' => '0190aaaa0000700080000000000000aa', 'clarified' => false]], $superseded);
        self::assertSame(
            [['id' => $pending->id, 'reviewStatus' => 'superseded', 'draftVersionId' => null, 'sentChanges' => null]],
            $repository->updates[0],
        );
        self::assertEquals(
            [
                new EqualsFilter('quoteId', 'quote-1'),
                new EqualsFilter('reviewStatus', 'pending'),
            ],
            $repository->searched?->getFilters(),
        );
    }

    /** The caller releases the clarification marker such a draft set live, so it has to know which one it was. */
    public function testSupersedingAClarificationSaysSo(): void
    {
        $pending = self::record('pending', null);
        $pending->outcome = 'clarified';

        self::assertSame(
            [['versionId' => null, 'clarified' => true]],
            (new DecisionReviewStore(self::repository([$pending])))->supersedePending('quote-1'),
        );
    }

    public function testSupersedingNothingWritesNothing(): void
    {
        $repository = self::repository([]);

        self::assertSame([], (new DecisionReviewStore($repository))->supersedePending('quote-1'));
        self::assertSame([], $repository->updates);
    }

    public function testSentStoresWhatWasSentAndDropsTheVersion(): void
    {
        $repository = self::repository([]);

        (new DecisionReviewStore($repository))->markSent('rec-1', 'Hello', ['totalNet' => 90.0]);

        $row = $repository->updates[0][0];
        self::assertSame('sent', $row['reviewStatus']);
        self::assertSame('Hello', $row['sentReply']);
        self::assertSame(['totalNet' => 90.0], $row['sentChanges']);
        self::assertNull($row['draftVersionId']);
        self::assertInstanceOf(\DateTimeImmutable::class, $row['reviewedAt']);
    }

    public function testPreviewPersistsAnInternalEditedMarker(): void
    {
        $repository = self::repository([]);

        (new DecisionReviewStore($repository))->markPreviewEdited('rec-1');

        self::assertSame([['id' => 'rec-1', 'sentChanges' => ['editedByMerchant' => true]]], $repository->updates[0]);
    }

    public function testFeedbackStoresNullForAnEmptyHalf(): void
    {
        $repository = self::repository([]);

        (new DecisionReviewStore($repository))->saveFeedback('rec-1', ['wrong_price'], '');

        $row = $repository->updates[0][0];
        self::assertSame(['wrong_price'], $row['feedbackReasons']);
        self::assertNull($row['feedbackComment']);
        self::assertInstanceOf(\DateTimeImmutable::class, $row['feedbackAt']);
    }

    public function testAMalformedIdFindsNothing(): void
    {
        self::assertNull((new DecisionReviewStore(self::repository([])))->find('not-a-uuid'));
    }

    private static function record(string $status, ?string $versionId): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = '0190bbbb0000700080000000000000bb';
        $record->setUniqueIdentifier($record->id);
        $record->quoteId = 'quote-1';
        $record->reviewStatus = $status;
        $record->draftVersionId = $versionId;

        return $record;
    }

    /** @param list<QuoteDecisionRecord> $records */
    private static function repository(array $records): EntityRepository
    {
        return new class($records) extends EntityRepository {
            /** @var list<list<array<string, mixed>>> */
            public array $updates = [];

            public ?Criteria $searched = null;

            /** @param list<QuoteDecisionRecord> $records */
            public function __construct(
                private readonly array $records,
            ) {}

            #[\Override]
            public function search(Criteria $criteria, Context $context): EntitySearchResult
            {
                $this->searched = $criteria;
                $entities = new EntityCollection($this->records);

                return new EntitySearchResult(
                    QuoteDecisionRecord::class,
                    $entities->count(),
                    $entities,
                    null,
                    $criteria,
                    $context,
                );
            }

            /** @param array<int, array<string, mixed>> $data */
            #[\Override]
            public function update(array $data, Context $context): EntityWrittenContainerEvent
            {
                $this->updates[] = array_values($data);

                return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
            }
        };
    }
}
