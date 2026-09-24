<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionEraser;
use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;

/**
 * One buyer out of the decision records, with the decisions left standing.
 *
 * The table has no retention policy and nothing reacts to a customer being
 * deleted, so until this existed a buyer's words stayed until the plugin was
 * uninstalled. #177 raised the stakes by storing the comment verbatim.
 */
final class DecisionEraserTest extends TestCase
{
    public function testEveryFieldThatCanCarryTheBuyersWordsIsCleared(): void
    {
        $record = self::record();
        $repository = self::repository($record);

        $changed = (new DecisionEraser($repository, self::traces()))->forget('cust-1');

        self::assertSame(1, $changed->decisions);
        $payload = $repository->updates[0][0] ?? [];

        self::assertSame($record->id, $payload['id'], 'The row is rewritten, never deleted.');

        // AnonymizedDecision::FREE_TEXT is the shop's own list of what can
        // carry the buyer's words. Every entry on it has to be cleared here,
        // read off that constant rather than typed out again, so a fifth free
        // text column cannot be added to the export and forgotten here.
        foreach (AnonymizedDecision::FREE_TEXT as $field) {
            self::assertArrayHasKey($field, $payload, $field . ' can carry the buyer\'s words and was left standing.');
            self::assertNull($payload[$field], $field . ' still holds free text after an erasure.');
        }

        self::assertNull($payload['customerId'], 'A row that still names the customer has not forgotten them.');
    }

    public function testTheMerchantsOwnRecordOfTheDecisionSurvives(): void
    {
        $repository = self::repository(self::record());

        (new DecisionEraser($repository, self::traces()))->forget('cust-1');
        $payload = $repository->updates[0][0] ?? [];

        // Erasure, not deletion: what the agent decided and under which policy
        // is the merchant's record of their own business, and none of it needs
        // a person in it. Nothing here may clear band, outcome or totals.
        self::assertArrayNotHasKey('band', $payload);
        self::assertArrayNotHasKey('outcome', $payload);
        self::assertArrayNotHasKey('totalNetAfter', $payload);
        self::assertArrayNotHasKey('discountPercentGranted', $payload);

        // The structured ask survives too -- a price and a quantity are the
        // decision -- but not the sentences the extract model wrote out of the
        // buyer's message.
        self::assertSame(['price' => ['additionalDiscountPercent' => 5.0]], $payload['interpretedAsks']);
    }

    public function testTheJsonColumnsKeepTheirShapeAndLoseTheirProse(): void
    {
        $repository = self::repository(self::record());

        (new DecisionEraser($repository, self::traces()))->forget('cust-1');
        $payload = $repository->updates[0][0] ?? [];

        // A history round's result quotes that account's past quotes and
        // orders; which KIND of lookup was made is a fact about the agent.
        self::assertSame(['quotesSeen' => 7, 'rounds' => ['orders']], $payload['historyReads']);

        // A provider error body can quote the prompt, and the prompt carries
        // the buyer's comment. The class and the file:line are ours.
        self::assertSame([['class' => 'RuntimeException', 'at' => '/srv/Foo.php:12']], $payload['errorChain']);
    }

    public function testACustomerTheAgentNeverNegotiatedWithChangesNothing(): void
    {
        // Zero is an answer a merchant can give, not a failure: the command
        // reports it, and no empty update may reach the DAL.
        $repository = self::repository();

        self::assertSame(0, (new DecisionEraser($repository, self::traces()))->forget('cust-unknown')->decisions);
        self::assertSame([], $repository->updates);
    }

    public function testTheCustomersTraceContentAndIdAreClearedAndItsMetaKept(): void
    {
        $traces = self::traces(['t1', 't2']);

        $erased = (new DecisionEraser(self::repository(self::record()), $traces))->forget('cust-1');

        self::assertSame(2, $erased->traces);
        self::assertSame(
            [
                ['id' => 't1', 'content' => null, 'customerId' => null],
                ['id' => 't2', 'content' => null, 'customerId' => null],
            ],
            $traces->updates[0],
            'Meta stays because it records the merchant\'s figures, not the person.',
        );
    }

    public function testTracesAreFoundByCustomerAndByTheCustomersQuotes(): void
    {
        $record = self::record();
        $traces = self::traces(['t1']);

        (new DecisionEraser(self::repository($record), $traces))->forget('cust-1');

        $filter = $traces->criteria[0]->getFilters()[0] ?? null;
        self::assertInstanceOf(OrFilter::class, $filter);
        self::assertStringContainsString($record->quoteId, json_encode($filter, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('cust-1', json_encode($filter, JSON_THROW_ON_ERROR));
    }

    public function testNoTracesMeansNoTraceUpdate(): void
    {
        $traces = self::traces([]);

        $erased = (new DecisionEraser(self::repository(self::record()), $traces))->forget('cust-1');

        self::assertSame(0, $erased->traces);
        self::assertSame([], $traces->updates);
    }

    private static function record(): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec-1';
        $record->setUniqueIdentifier('rec-1');
        $record->customerId = 'cust-1';
        $record->quoteId = 'quote-1';
        $record->band = 'grant';
        $record->outcome = 'nothing_to_do';
        $record->totalNetAfter = 950.0;
        $record->discountPercentGranted = 5.0;
        $record->buyerAsk = 'Nice, thanks! Call me on 0170 1234567.';
        $record->replyToBuyer = 'Hello Anna, here is 5%.';
        $record->rawProposal = '{"message":"Anna Mueller, anna@example.com"}';
        $record->violations = ['Anna Mueller insists on 30%.'];
        $record->interpretedAsks = [
            'price' => ['additionalDiscountPercent' => 5.0],
            'clarificationQuestions' => ['Which line did you mean, Anna?'],
            'humanReviewRequests' => ['Customer KD-10042-X wants to speak to a person.'],
        ];
        $record->historyReads = [
            'quotesSeen' => 7,
            'rounds' => [['kind' => 'orders', 'result' => 'order 10009 for Anna Mueller']],
        ];
        $record->errorChain = [[
            'class' => 'RuntimeException',
            'message' => 'The provider rejected the prompt quoting anna@example.com',
            'at' => '/srv/Foo.php:12',
        ]];

        return $record;
    }

    /** A repository serving one record and keeping whatever is written back. */
    private static function repository(?QuoteDecisionRecord $record = null): EntityRepository
    {
        return new class($record) extends EntityRepository {
            /** @var list<list<array<string, mixed>>> */
            public array $updates = [];

            public function __construct(
                private readonly ?QuoteDecisionRecord $record,
            ) {}

            #[\Override]
            public function search(Criteria $criteria, Context $context): EntitySearchResult
            {
                $entities = new EntityCollection($this->record === null ? [] : [$this->record]);

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

    /** @param list<string> $ids */
    private static function traces(array $ids = []): EntityRepository
    {
        return new class($ids) extends EntityRepository {
            /** @var list<list<array<string, mixed>>> */
            public array $updates = [];

            /** @var list<Criteria> */
            public array $criteria = [];

            /** @param list<string> $ids */
            public function __construct(
                private readonly array $ids,
            ) {}

            #[\Override]
            public function searchIds(Criteria $criteria, Context $context): IdSearchResult
            {
                $this->criteria[] = $criteria;

                return new IdSearchResult(
                    \count($this->ids),
                    array_map(static fn(string $id): array => ['primaryKey' => $id, 'data' => []], $this->ids),
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
