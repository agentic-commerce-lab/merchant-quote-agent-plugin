<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Exception\DriverException;
use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\AvgResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

final class DecisionRecordTest extends IntegrationTestCase
{
    use PipelineFixture;

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

    /**
     * The column's width is enforced by MySQL, and by nothing above it.
     *
     * The DAL cannot do this job on this entity, and that is not an oversight
     * waiting to be corrected. `QuoteDecisionRecord` is an attribute entity,
     * so the only place a field definition could carry a length is
     * `#[Field(..., maxLength: 32)]` — and `Attribute\Field::$maxLength` does
     * not exist at the 6.7.1 support floor (absent up to and including
     * 6.7.4.2, present by 6.7.13.1, where it defaults to 255 and so would not
     * reject 33 characters even there). A named argument for a parameter the
     * installed core does not declare is an `Error` at attribute
     * instantiation, which happens while core builds the container: it takes
     * the whole shop down, not just this plugin. That is what
     * `CoreFloorCompatibilityTest::testDalAttributeArgumentsExistAtTheSupportFloor`
     * exists to prevent, and the thirteen columns `maxLength` was once used on
     * are why it was written.
     *
     * So the migrations hold the whole of the constraint —
     * `Migration1787998662CreateQuoteAgentDecision` for every column here, and
     * `Migration1789000000AddEscalationResolution` for `resolved_state`. This
     * test writes to `band` because VARCHAR(32) is narrow enough that a
     * plausible value overruns it, not because it is the narrowest column —
     * `currency_iso` is VARCHAR(3). Every string column on this entity is in
     * exactly the same position, bounded by its migration and by nothing in
     * PHP.
     *
     * Losing the typed `WriteException` costs a servicing pass nothing.
     * `NegotiationPipeline::record()` wraps the audit write in
     * `catch (\Throwable)` — not `catch (WriteException)` — and
     * `DecisionRecorder::finish()` catches nothing at all, so a driver-level
     * failure is logged and dropped on exactly the path a DAL-level one would
     * take. `RecordedPassTest::testAnAuditWriteFailureDoesNotFailThePass` pins
     * that with a bare `RuntimeException`.
     *
     * Asserted on the SQLSTATE rather than on the message: `22001` is the
     * standard's "string data, right truncated" and survives MySQL rewording
     * its 1406, while the message is read only for the column name, so another
     * column overflowing cannot satisfy this test. Bounding these values before
     * they reach the database at all is #60's scope.
     */
    public function testAStringLongerThanItsColumnIsRejectedByTheDatabaseNotTheDal(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');

        self::assertInstanceOf(EntityRepository::class, $repository);

        try {
            $repository->create([[
                'id' => Uuid::randomHex(),
                'quoteId' => Uuid::randomHex(),
                'band' => str_repeat('x', times: 33),
            ]], Context::createDefaultContext());

            self::fail('The database accepted 33 characters into the VARCHAR(32) band column.');
        } catch (DriverException $e) {
            self::assertSame('22001', $e->getSQLState(), 'Expected SQLSTATE 22001, string data right truncated.');
            self::assertStringContainsString('band', $e->getMessage(), 'The rejection must name the column.');
        }
    }

    /**
     * Every property is set to a non-null value of the right type: a null
     * would paper over a draft property whose name does not match its entity
     * field. That mismatch would NOT throw — the DAL silently drops an
     * unknown payload key rather than rejecting the write (see
     * DecisionRecordWriter's docblock and DraftMirrorsEntityTest, which is
     * the actual structural guard against it) — so a null field here would
     * stay silently null in the DB with nothing to say why. Every populated
     * property is asserted on the round trip below, not just a handful.
     *
     * Built around the container-resolved repository rather than resolved as
     * `DecisionRecordWriter::class` directly: the compiled container inlines
     * that service into its only consumer (`DecisionRecorder`, which depends
     * on the interface, not the class), so fetching the concrete class by
     * name throws `ServiceNotFoundException` ("removed or inlined when the
     * container was compiled") even under `test.service_container`, which
     * exposes private services but not ones the optimizer inlined away. The
     * repository id itself IS a real, addressable service (Task 1 and the
     * two tests above already prove that), so building the writer around it
     * still exercises the real `merchant_quote_agent_decision.repository`
     * service and the writer's own mapping — it just can't also prove the
     * container resolves `DecisionRecordWriter::class` by name.
     */
    public function testAFullyPopulatedDraftSurvivesTheRealDal(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $writer = new DecisionRecordWriter($repository);

        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        $draft->quoteNumber = '10042';
        $draft->salesChannelId = Uuid::randomHex();
        $draft->customerId = Uuid::randomHex();
        $draft->currencyIso = 'EUR';
        $draft->triggerReason = 'comment_written';
        $draft->attempt = 1;
        $draft->revisionVersionId = Uuid::randomHex();
        $draft->revisionUpdatedAt = new \DateTimeImmutable('2026-08-01T12:00:00+00:00');
        $draft->band = 'grant';
        $draft->outcome = 'offered';
        $draft->escalationReason = 'below_floor';
        $draft->discountPercentGranted = 12.5;
        $draft->maxDiscountPercent = 15.0;
        $draft->totalNetBefore = 1000.0;
        $draft->totalNetAfter = 875.0;
        $draft->model = 'gpt-4o-test-model';
        $draft->modelHost = 'api.openai.com';
        $draft->extractPromptHash = hash('sha256', 'extract');
        $draft->negotiatePromptHash = hash('sha256', 'negotiate');
        $draft->replyPromptHash = hash('sha256', 'reply');
        $draft->promptTokens = 120;
        $draft->completionTokens = 80;
        $draft->modelLatencyMs = 450;
        $draft->durationMs = 900;
        $draft->authorized = true;
        $draft->verified = true;
        $draft->errorClass = 'RuntimeException';
        $draft->interpretedAsks = ['discount' => '10%'];
        $draft->historyReads = [
            'available' => true,
            'reason' => null,
            'quotesSeen' => 2,
            'rounds' => [['kind' => 'orders', 'productId' => null, 'result' => "Orders:\nPaid 12.50"]],
        ];
        $draft->rawProposal = 'raw model output text';
        $draft->violations = ['discount_over_cap'];
        $draft->writes = ['line_item_price_updated'];
        $draft->errorChain = [['class' => 'RuntimeException', 'message' => 'test']];
        $draft->replyToBuyer = 'Thanks for the update!';
        $draft->startedAt = microtime(true);

        $writer->write($draft);

        $written = $repository
            ->search(
                (new Criteria())->addFilter(new EqualsFilter('quoteId', $draft->quoteId)),
                Context::createDefaultContext(),
            )
            ->first();

        self::assertNotNull($written, 'The fully populated draft was not written.');

        // Every populated property, not a hand-picked few: a hand-written
        // list of assertions drifts the same way the mapping it is meant to
        // catch drifts. assertEquals rather than assertSame because it
        // compares DateTimeImmutable and arrays (the JSON columns) by value,
        // which is exactly the round trip being proven for those fields.
        $payload = get_object_vars($draft);
        unset($payload['startedAt']);

        foreach ($payload as $field => $value) {
            self::assertEquals($value, $written->$field, sprintf('Field "%s" did not round-trip.', $field));
        }
    }

    /**
     * The first end-to-end proof that DI wiring resolves a real pass to a
     * real row: every earlier test built the recorder or writer by hand.
     * `DatabaseTransactionBehaviour` rolls back everything a test writes
     * through the shop's connection, pipeline writes included, so this
     * leaves nothing behind — see NegotiationPipelineTest, whose four tests
     * already write real quote/comment rows the same way.
     */
    public function testARealPassWritesARealRow(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        self::writeBuyerComment($quoteId, 'Could you do 5% off?');

        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);
        $criteria = (new Criteria())->addFilter(new EqualsFilter('quoteId', $quoteId));
        $existingIds = $repository->searchIds($criteria, Context::createDefaultContext())->getIds();

        $before = $gateway->fetchSnapshot($quoteId);

        self::pipelineWith([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            self::reworded(...),
        ])->service($before, $gateway, self::enabledSettings(), NegotiationFixture::context());

        $after = $gateway->fetchSnapshot($quoteId);

        $allIds = $repository->searchIds($criteria, Context::createDefaultContext())->getIds();
        $newIds = array_values(array_diff($allIds, $existingIds));
        self::assertCount(1, $newIds, 'The pass must write exactly one new decision for this quote.');
        $record = $repository->search(new Criteria($newIds), Context::createDefaultContext())->first();

        self::assertNotNull($record, 'A real pass wrote no audit record.');
        self::assertSame('offered', $record->outcome);
        self::assertSame('grant', $record->band);
        self::assertSame('comment_written', $record->triggerReason);
        self::assertSame(
            self::reworded(self::replyTemplateFor($before, $after)),
            $record->replyToBuyer,
            'The audit trail must record the reworded reply that reached the buyer, not the template.',
        );
        self::assertNotNull($record->interpretedAsks);
        self::assertIsInt($record->durationMs);
        // #21's average-granted-discount readout has no numerator unless a
        // real granting pass lands a real percentage here, not the hand-
        // written value every other test in this file uses.
        self::assertNotNull($record->discountPercentGranted, 'A granting pass wrote no discount percentage.');
        self::assertEqualsWithDelta(5.0, $record->discountPercentGranted, 0.5);
    }

    /**
     * #21 reads outcome shares and average granted discount off this table.
     * Proving the column types aggregate is cheap now and expensive later.
     * Filtered on a fresh quoteId, not across the table, since other tests
     * in this run leave their own (soon-to-be-rolled-back) rows behind.
     */
    public function testTheAggregationsTheTestRunNeedsActuallyRun(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $quoteId = Uuid::randomHex();
        $repository->create([
            self::row($quoteId, 'offered', 5.0),
            self::row($quoteId, 'offered', 7.0),
            self::row($quoteId, 'escalated', null),
        ], Context::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addAggregation(new TermsAggregation('by-outcome', 'outcome'));
        $criteria->addAggregation(new AvgAggregation('avg-discount', 'discountPercentGranted'));

        $result = $repository->aggregate($criteria, Context::createDefaultContext());

        $byOutcome = $result->get('by-outcome');
        self::assertInstanceOf(TermsResult::class, $byOutcome);
        self::assertSame(2, $byOutcome->get('offered')?->getCount());

        $average = $result->get('avg-discount');
        self::assertInstanceOf(AvgResult::class, $average);
        self::assertSame(6.0, $average->getAvg());
    }

    /** @return array<string, mixed> */
    private static function row(string $quoteId, string $outcome, ?float $discount): array
    {
        return [
            'id' => Uuid::randomHex(),
            'quoteId' => $quoteId,
            'outcome' => $outcome,
            'discountPercentGranted' => $discount,
            'durationMs' => 100,
        ];
    }
}
