<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
        $draft->rawProposal = 'raw model output text';
        $draft->violations = ['discount_over_cap'];
        $draft->writes = ['line_item_price_updated'];
        $draft->errorChain = [['class' => 'RuntimeException', 'message' => 'test']];
        $draft->buyerComment = 'Thanks for the update!';
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
}
