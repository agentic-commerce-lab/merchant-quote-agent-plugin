<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class ServiceQuoteHandlerTest extends TestCase
{
    public function testANewFingerprintHandsOffOnceAndStampsTheMarker(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::countingPipeline();
        $handler = ServicingHandlerFixture::handler($gateway, $pipeline);

        $handler(ServicingHandlerFixture::message());

        self::assertSame(1, $pipeline->passes);
        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayHasKey(ServicingFingerprint::MARKER_KEY, $stamp);
        self::assertNull($stamp[ServiceQuoteHandler::ATTEMPTS_KEY]);
    }

    public function testAMatchingFingerprintHandsOffZeroTimes(): void
    {
        $snapshot = ServicingHandlerFixture::snapshot();
        $serviced = ServicingHandlerFixture::snapshot([
            ServicingFingerprint::MARKER_KEY => ServicingFingerprint::of($snapshot),
        ]);
        $gateway = new FakeQuoteGateway([$serviced]);
        $pipeline = ServicingHandlerFixture::countingPipeline();

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        self::assertSame(0, $pipeline->passes);
        self::assertSame([], $gateway->customFieldWrites);
    }

    public function testTheCounterIsIncrementedBeforeTheHandOff(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = new class implements QuoteServicingPipelineInterface {
            public ?int $writesSeenBeforeMe = null;

            #[\Override]
            public function service(QuoteSnapshot $snapshot, QuoteGatewayInterface $gateway): void
            {
                \PHPUnit\Framework\Assert::assertInstanceOf(FakeQuoteGateway::class, $gateway);
                $this->writesSeenBeforeMe = \count($gateway->customFieldWrites);
            }
        };

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        self::assertSame(
            1,
            $pipeline->writesSeenBeforeMe,
            'The crash counter must be committed BEFORE the pipeline runs — that ordering is the '
            . 'entire mechanism. A segfault during service() leaves no exception and no retry '
            . 'stamp, so an uncommitted counter means an hourly redelivery loop forever.',
        );
    }

    public function testAQuoteAtTheAttemptCeilingParksWithoutHandingOff(): void
    {
        $gateway = new FakeQuoteGateway([
            ServicingHandlerFixture::snapshot([ServiceQuoteHandler::ATTEMPTS_KEY => ServiceQuoteHandler::MAX_ATTEMPTS]),
        ]);
        $pipeline = ServicingHandlerFixture::countingPipeline();

        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());
        } finally {
            self::assertSame(0, $pipeline->passes);
        }
    }

    /**
     * The test that pins the design's subtlest point. A handler that stamps
     * `ServicingFingerprint::of($after)` instead of
     * `ServicingFingerprint::stamp($snapshot, $stateAfter)` passes every other
     * test in this file and fails only this one.
     *
     * The two served snapshots simulate a buyer comment landing mid-pass: the
     * handler reads `[T1]`, the pipeline runs, and the post-servicing read is
     * `[T1, T2]` with a changed state. The stamp must describe what was
     * consumed — one authored comment at T1 — carrying only the state forward.
     */
    public function testTheStampCarriesTheServicedCommentsAndTheLaterState(): void
    {
        $servicedAt = new \DateTimeImmutable('2026-08-27 10:00:00.100');
        $duringPass = new \DateTimeImmutable('2026-08-27 10:00:04.900');

        $gateway = new FakeQuoteGateway([
            QuoteSnapshotFixture::snapshot('open', [ServicingHandlerFixture::buyer($servicedAt)]),
            QuoteSnapshotFixture::snapshot('replied', [
                ServicingHandlerFixture::buyer($servicedAt),
                ServicingHandlerFixture::buyer($duringPass),
            ]),
        ]);

        ServicingHandlerFixture::handler($gateway, ServicingHandlerFixture::countingPipeline())(
            ServicingHandlerFixture::message(),
        );

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertSame(
            'replied|1|' . $servicedAt->format('U.u'),
            $stamp[ServicingFingerprint::MARKER_KEY],
            'The stamp claimed credit for a buyer comment that arrived during the pass. That comment '
            . 'will now compute a matching fingerprint and be dropped — the exact failure a revision '
            . 'marker was rejected for. Stamp what was consumed, not what exists afterwards.',
        );
    }

    public function testAHeldLockIsRetriedRatherThanDropped(): void
    {
        $locks = ServicingHandlerFixture::locks();
        // Kept in a variable rather than discarded: Symfony's Lock releases
        // itself in __destruct() when autoRelease is true (the default), so a
        // temporary that is never assigned is garbage-collected — and its lock
        // released — before the handler ever tries to acquire it.
        $held = $locks->for('q1');
        $held->acquire();

        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::countingPipeline();
        $handler = ServicingHandlerFixture::handler($gateway, $pipeline, $locks);

        $this->expectException(RecoverableMessageHandlingException::class);

        try {
            $handler(ServicingHandlerFixture::message());
        } finally {
            self::assertSame(0, $pipeline->passes);
        }
    }

    public function testANullGatewayReturnsWithoutServicingOrLocking(): void
    {
        $locks = ServicingHandlerFixture::locks();
        $pipeline = ServicingHandlerFixture::countingPipeline();

        (new ServiceQuoteHandler($locks, new NullLogger(), null, $pipeline))(ServicingHandlerFixture::message());

        self::assertSame(0, $pipeline->passes, 'A quote was serviced without a gateway.');
        self::assertTrue(
            $locks->for('q1')->acquire(),
            'The handler took a lock before checking the gateway, so an unlicensed shop still '
            . 'serialises on a lock it can never use.',
        );
    }

    public function testANullPipelineReturnsWithoutStamping(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);

        (new ServiceQuoteHandler(ServicingHandlerFixture::locks(), new NullLogger(), $gateway, null))(
            ServicingHandlerFixture::message(),
        );

        self::assertSame([], $gateway->customFieldWrites, 'Nothing was serviced, so nothing may be stamped.');
    }

    public function testADeletedQuoteIsSwallowedRatherThanRetried(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()], quoteMissing: true);
        $pipeline = ServicingHandlerFixture::countingPipeline();

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        self::assertSame(0, $pipeline->passes);
    }

    public function testTheLockIsReleasedSoASecondPassCanRun(): void
    {
        $locks = ServicingHandlerFixture::locks();
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $handler = ServicingHandlerFixture::handler($gateway, ServicingHandlerFixture::countingPipeline(), $locks);

        $handler(ServicingHandlerFixture::message());

        self::assertTrue($locks->for('q1')->acquire(), 'The handler did not release its lock.');
    }
}
