<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Improvement\ImprovementCadence;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettings;
use MerchantQuoteAgentPlugin\Improvement\RunStatus;
use MerchantQuoteAgentPlugin\Improvement\TallyingDecisionWriter;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The three behaviours the design brief is explicit about (see Task 11's
 * brief). ImprovementGeneratorFixture builds the real object graph; none of
 * it past ImprovementRunner's window/harvest checks is ever reached by these
 * three tests, since each one short-circuits before the replay stage.
 */
final class ImprovementGeneratorTest extends TestCase
{
    public function testItWritesNothingWhenTheChannelHasNotEnabledIt(): void
    {
        $runs = new RunRepositorySpy();
        $generator = $this->fixture()->generator(null, null, [], $this->platformThatMustNotBeCalled(), $runs);

        $generator->generate($this->now());

        self::assertSame([], $runs->written);
    }

    public function testANotDueTickWritesNoRun(): void
    {
        $runs = new RunRepositorySpy();
        $generator = $this->fixture()->generator(
            $this->settings(ImprovementCadence::Weekly),
            $this->now()->modify('-1 day'),
            [],
            $this->platformThatMustNotBeCalled(),
            $runs,
        );

        $generator->generate($this->now());

        self::assertSame([], $runs->written);
    }

    public function testAnEmptyWindowWritesNoDataAndCallsNoModel(): void
    {
        $runs = new RunRepositorySpy();
        $generator = $this->fixture()->generator(
            $this->settings(ImprovementCadence::Daily),
            null,
            [],
            $this->platformThatMustNotBeCalled(),
            $runs,
        );

        $generator->generate($this->now());

        self::assertCount(1, $runs->written);
        self::assertSame(RunStatus::NoData->value, $runs->written[0]['status']);
    }

    /**
     * The failure contract steps 5-9 keep, mirroring NegotiationPipeline::service():
     * a \Throwable from the judge/replay/write stage finishes the run `failed`
     * with the class and message in `error`, on the SAME row `startRunning()`
     * created, and is rethrown so Messenger's retry still sees it. A generic
     * \RuntimeException from the HTTP client -- not a ModelUnavailable, so
     * neither ImprovementJudge nor anything else in the pipeline catches it --
     * stands in for "something upstream broke".
     */
    public function testAFailureFinishesTheRunFailedAndRethrows(): void
    {
        $runs = new RunRepositorySpy();
        $error = new \RuntimeException('the provider is on fire');
        $generator = $this->fixture()->generator(
            $this->settings(ImprovementCadence::Daily),
            null,
            [self::decision()],
            $this->platformThatThrows($error),
            $runs,
        );

        $caught = null;

        try {
            $generator->generate($this->now());
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertSame($error, $caught, 'the same throwable must escape generate() for Messenger\'s retry');

        self::assertCount(1, $runs->written, 'startRunning() must still have written the running row');
        self::assertCount(1, $runs->updated, 'writeFailed() must update that same row exactly once');
        self::assertSame($runs->written[0]['id'], $runs->updated[0]['id']);
        self::assertSame(RunStatus::Failed->value, $runs->updated[0]['status']);
        self::assertSame(\RuntimeException::class . ': the provider is on fire', $runs->updated[0]['error']);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-21 03:00:00');
    }

    private function settings(ImprovementCadence $cadence): ImprovementSettings
    {
        return new ImprovementSettings(
            enabled: true,
            cadence: $cadence,
            sampleSize: 20,
            candidates: 2,
            llm: new ModelAccess('sk-improve', 'https://api.example.com/v1', 'gpt-4o-mini'),
        );
    }

    /**
     * A ModelPlatform wired to an HTTP client that fails the test if it is
     * ever called -- none of these three behaviours reaches the judge or the
     * replay, so no model call is legitimate here.
     */
    private function platformThatMustNotBeCalled(): ModelPlatform
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');

        return new ModelPlatform($http, new NullLogger(), new DecisionRecorder(new TallyingDecisionWriter()));
    }

    /**
     * A ModelPlatform wired to an HTTP client that throws $error on every
     * request. `withOptions()` must return the SAME configured mock: ModelPlatform
     * wraps whatever it gets back from it in a RetryableHttpClient, and an
     * unstubbed mock method would otherwise hand that wrapper a fresh, inert
     * double instead of this one.
     */
    private function platformThatThrows(\Throwable $error): ModelPlatform
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->method('withOptions')->willReturnSelf();
        $http->method('request')->willThrowException($error);

        return new ModelPlatform($http, new NullLogger(), new DecisionRecorder(new TallyingDecisionWriter()));
    }

    /** One decision, populated only enough for DayPicture and DecisionHarvest to accept it. */
    private static function decision(): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $id = Uuid::randomHex();
        $record->setUniqueIdentifier($id);
        $record->id = $id;
        $record->quoteId = Uuid::randomHex();
        $record->band = 'grant';
        $record->outcome = 'offered';

        return $record;
    }

    private function fixture(): ImprovementGeneratorFixture
    {
        return new ImprovementGeneratorFixture();
    }
}
