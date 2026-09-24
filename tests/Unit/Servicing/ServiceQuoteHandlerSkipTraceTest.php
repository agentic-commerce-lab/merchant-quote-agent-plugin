<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use MerchantQuoteAgentPlugin\Servicing\ServicingJournal;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeTraceWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ServiceQuoteHandlerSkipTraceTest extends TestCase
{
    public function testNoGatewayAndBusyLockEachLeaveASkip(): void
    {
        $writer = new FakeTraceWriter();
        $locks = ServicingHandlerFixture::locks();
        $pipeline = ServicingHandlerFixture::countingPipeline();
        $handler = self::handler($writer, null, $pipeline, $locks);

        try {
            $handler(ServicingHandlerFixture::message());
            self::fail('A missing gateway must park the message.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('gateway is unavailable', $e->getMessage());
        }

        $held = $locks->for('q1');
        $held->acquire();
        $handler = self::handler(
            $writer,
            new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]),
            $pipeline,
            $locks,
        );

        try {
            $handler(ServicingHandlerFixture::message());
            self::fail('A busy lock must retry.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertStringContainsString('being serviced', $e->getMessage());
        }

        self::assertSame(['no_gateway', 'lock_busy'], self::reasons($writer));
        self::assertSame(0, $pipeline->passes);
    }

    public function testMissingQuoteAndStaleTriggerEachLeaveASkip(): void
    {
        $writer = new FakeTraceWriter();
        $pipeline = ServicingHandlerFixture::countingPipeline();
        self::handler($writer, new FakeQuoteGateway([], quoteMissing: true), $pipeline)(
            ServicingHandlerFixture::message(),
        );

        try {
            self::handler(
                $writer,
                new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]),
                $pipeline,
            )(new ServiceQuoteMessage('q1', 'Anna@example.com'));
            self::fail('An unknown trigger must retain its original failure.');
        } catch (\ValueError $e) {
            self::assertStringContainsString('Anna@example.com', $e->getMessage());
        }

        self::assertSame(['quote_not_found', 'stale_trigger'], self::reasons($writer));
        self::assertNull($writer->events[1]->meta['trigger']);
        self::assertSame(0, $pipeline->passes);
    }

    public function testNothingNewAndNoPipelineEachLeaveASkip(): void
    {
        $writer = new FakeTraceWriter();
        $snapshot = ServicingHandlerFixture::snapshot();
        $stamped = ServicingHandlerFixture::snapshot([
            ServicingFingerprint::MARKER_KEY => ServicingFingerprint::of($snapshot),
        ]);
        $pipeline = ServicingHandlerFixture::countingPipeline();
        self::handler($writer, new FakeQuoteGateway([$stamped]), $pipeline)(ServicingHandlerFixture::message());
        self::handler($writer, new FakeQuoteGateway([$snapshot]), null)(ServicingHandlerFixture::message());

        self::assertSame(['nothing_new', 'no_pipeline'], self::reasons($writer));
        self::assertNull($writer->events[0]->customerId, 'The fixture has no customer id.');
        self::assertSame(0, $pipeline->passes);
    }

    public function testQuoteDeletedDuringAttemptWriteLeavesOneQuoteNotFoundSkip(): void
    {
        $writer = new FakeTraceWriter();
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $gateway->updateThrows = QuoteNotFoundException::forId('q1');

        self::handler($writer, $gateway, ServicingHandlerFixture::countingPipeline())(
            ServicingHandlerFixture::message(),
        );

        self::assertSame(['quote_not_found'], self::reasons($writer));
    }

    public function testCrashBudgetAndFailedAttemptWriteEachLeaveASkip(): void
    {
        $writer = new FakeTraceWriter();
        $pipeline = ServicingHandlerFixture::countingPipeline();
        $ceiling = new FakeQuoteGateway([ServicingHandlerFixture::snapshot([
            ServiceQuoteHandler::ATTEMPTS_KEY => ServiceQuoteHandler::MAX_ATTEMPTS,
        ])]);

        try {
            self::handler($writer, $ceiling, $pipeline)(ServicingHandlerFixture::message());
            self::fail('A spent crash budget must park the message.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('crash budget', $e->getMessage());
        }

        $failedWrite = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $failedWrite->updateThrows = new \RuntimeException('quote write failed');

        try {
            self::handler($writer, $failedWrite, $pipeline)(ServicingHandlerFixture::message());
            self::fail('The original quote write failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('quote write failed', $e->getMessage());
        }

        self::assertSame(['crash_budget', 'attempt_write_failed'], self::reasons($writer));
        self::assertSame(4, $writer->events[0]->meta['attempt']);
        self::assertSame(0, $pipeline->passes);
    }

    public function testASuccessfulPassDoesNotAddAnOutsidePassSkip(): void
    {
        $writer = new FakeTraceWriter();
        $pipeline = ServicingHandlerFixture::countingPipeline();

        self::handler($writer, new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]), $pipeline)(
            ServicingHandlerFixture::message(),
        );

        self::assertSame([], $writer->events);
        self::assertSame(1, $pipeline->passes);
    }

    private static function handler(
        FakeTraceWriter $writer,
        ?FakeQuoteGateway $gateway,
        ?\MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface $pipeline,
        ?\MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock $locks = null,
    ): ServiceQuoteHandler {
        return new ServiceQuoteHandler(
            $locks ?? ServicingHandlerFixture::locks(),
            new ServicingJournal(new NullLogger(), $writer),
            ServicingSettingsFixture::preflightReturning(ServicingSettingsFixture::settings()),
            $gateway,
            $pipeline,
        );
    }

    /** @return list<string> */
    private static function reasons(FakeTraceWriter $writer): array
    {
        return array_map(static fn($event): string => $event->meta['reason'], $writer->events);
    }
}
