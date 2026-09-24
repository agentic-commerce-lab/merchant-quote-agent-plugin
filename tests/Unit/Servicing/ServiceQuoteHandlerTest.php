<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class ServiceQuoteHandlerTest extends TestCase
{
    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
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

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testThePipelineIsToldWhyTheQuoteWasQueuedAndWhichAttemptThisIs(): void
    {
        // Without the attempt number every redelivery reads as a separate
        // negotiation, which would inflate #21's counts by exactly the number
        // of retries the crash budget allows.
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = new RecordingPipeline();
        $handler = ServicingHandlerFixture::handler($gateway, $pipeline);

        $handler(ServicingHandlerFixture::message());

        self::assertNotNull($pipeline->context);
        self::assertSame(ServicingTriggerReason::CommentWritten, $pipeline->context->reason);
        self::assertSame(0, $pipeline->context->attempt);
    }

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
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

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testTheCounterIsIncrementedBeforeTheHandOff(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = new class implements QuoteServicingPipelineInterface {
            public ?int $writesSeenBeforeMe = null;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
                PassContext $context,
            ): NegotiationOutcome {
                \PHPUnit\Framework\Assert::assertInstanceOf(FakeQuoteGateway::class, $gateway);
                $this->writesSeenBeforeMe = \count($gateway->customFieldWrites);

                return NegotiationOutcome::Offered;
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

    /**
     * Two distinct refusals — the attempt ceiling and a lock held by another
     * worker — share one guarantee: the pipeline never runs. Merged into one
     * parameterized test, rather than two, to stay under mago's
     * too-many-methods threshold; the scenario builders live in
     * ServicingHandlerFixture alongside this class's other collaborator
     * builders.
     *
     * @param class-string<\Throwable> $expectedException
     * @throws \Throwable the expected exception, via expectException()
     */
    #[DataProviderExternal(ServicingHandlerFixture::class, 'handoffRefusalScenarios')]
    public function testAHandoffIsRefusedWithoutRunningThePipeline(string $scenario, string $expectedException): void
    {
        $locks = ServicingHandlerFixture::locks();
        // Index 1 (the held lock, when present) must stay referenced by this
        // local for the rest of the method: Symfony's Lock releases itself in
        // __destruct() when autoRelease is true, so a temporary that nothing
        // references would be garbage-collected — and its lock released —
        // before the handler ever tries to acquire it.
        $scenarioResult = ServicingHandlerFixture::refusalScenario($scenario, $locks);
        $gateway = $scenarioResult[0];
        $pipeline = ServicingHandlerFixture::countingPipeline();

        $this->expectException($expectedException);

        try {
            ServicingHandlerFixture::handler($gateway, $pipeline, $locks)(ServicingHandlerFixture::message());
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
     *
     * @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions
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

    /**
     * The process survived a thrown pipeline failure, so Messenger's
     * RedeliveryStamp already bounds it via retry — the quote-side counter
     * exists only for a delivery that vanishes without one (a segfaulted
     * worker, #3). A caught throw must therefore clear the counter rather
     * than let four transient failures in a row permanently park the quote.
     *
     * @throws \Throwable anything other than the RuntimeException this test throws
     */
    public function testAThrownPipelineFailureClearsTheAttemptCounterAndRethrows(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = new class implements QuoteServicingPipelineInterface {
            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
                PassContext $context,
            ): NegotiationOutcome {
                throw new \RuntimeException('LLM provider unavailable.');
            }
        };

        try {
            ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());
            self::fail('The pipeline failure did not propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('LLM provider unavailable.', $e->getMessage());
        }

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertNull(
            $stamp[ServiceQuoteHandler::ATTEMPTS_KEY],
            'A thrown pipeline failure left the crash-budget counter standing, so four transient LLM '
            . 'failures in a row would strand every future trigger for this quote — Messenger\'s retry '
            . 'already bounds this failure; the counter must be cleared on a caught throw.',
        );
    }

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testANullPipelineReturnsWithoutStamping(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);

        (new ServiceQuoteHandler(
            ServicingHandlerFixture::locks(),
            ServicingTestJournal::create(),
            ServicingSettingsFixture::preflightReturning(ServicingSettingsFixture::settings()),
            $gateway,
            null,
        ))(ServicingHandlerFixture::message());

        self::assertSame([], $gateway->customFieldWrites, 'Nothing was serviced, so nothing may be stamped.');
    }

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testADeletedQuoteIsSwallowedRatherThanRetried(): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()], quoteMissing: true);
        $pipeline = ServicingHandlerFixture::countingPipeline();

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        self::assertSame(0, $pipeline->passes);
    }

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testTheLockIsReleasedSoASecondPassCanRun(): void
    {
        $locks = ServicingHandlerFixture::locks();
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $handler = ServicingHandlerFixture::handler($gateway, ServicingHandlerFixture::countingPipeline(), $locks);

        $handler(ServicingHandlerFixture::message());

        self::assertTrue($locks->for('q1')->acquire(), 'The handler did not release its lock.');
    }
}
