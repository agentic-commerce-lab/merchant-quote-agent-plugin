<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Protocol\Emitter\EmissionOutcome;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteHandler;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteMessage;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingJournal;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeTraceWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

final class ObserveQuoteHandlerTest extends TestCase
{
    public function testObserverExitsRecordSkipsWithoutRecordingBusyRetries(): void
    {
        $writer = new FakeTraceWriter();
        $journal = new ServicingJournal(new NullLogger(), $writer);
        $message = new ObserveQuoteMessage('quote-1');

        (new ObserveQuoteHandler(self::locks(free: true), new NullLogger(), null, self::emitter(), $journal))($message);

        $missing = $this->createMock(QuoteGatewayInterface::class);
        $missing->method('fetchSnapshot')->willThrowException(QuoteNotFoundException::forId('quote-1'));
        (new ObserveQuoteHandler(self::locks(free: true), new NullLogger(), $missing, self::emitter(), $journal))(
            $message,
        );

        (new ObserveQuoteHandler(
            self::locks(free: true),
            new NullLogger(),
            new RecordingQuoteGateway(),
            self::failingEmitter(),
            $journal,
        ))($message);

        self::assertSame(
            ['no_gateway', 'quote_not_found', 'observation_failed'],
            array_column(array_map(static fn($event): array => $event->meta, $writer->events), 'reason'),
        );
        self::assertSame(
            ['observer', 'observer', 'observer'],
            array_column(array_map(static fn($event): array => $event->meta, $writer->events), 'source'),
        );
    }

    /**
     * Proven by mutation: with the null-gateway guard replaced by `if
     * (false)`, all three tests in this class still passed, because
     * `$gateway->fetchSnapshot()` on a null gateway throws \Error, which the
     * handler's own catch-all swallows — the emitter is never reached either
     * way, so asserting only "the emitter was not called" cannot tell a
     * working guard from a broken one. Asserting on the actual skip line
     * closes that gap: the broken guard logs "observation failed outside the
     * emitter" instead of "observation skipped: no commercial quote gateway",
     * so this test fails under the mutation (verified locally, then
     * reverted).
     */
    public function testItDoesNothingWithoutTheCommercialGateway(): void
    {
        // Same posture as ServiceQuoteHandler: no gateway means SwagCommercial
        // is absent or unlicensed, and evidence is not worth a parked message.
        //
        // In real wiring SellerActEmitter is registered unconditionally inside
        // the SwagCommercial-class-exists gate (services.php), so the only
        // constructor argument that is ever actually null in production is the
        // gateway — the emitter here is a real spy, not null, which is what
        // makes the assertion below meaningful: if the null-gateway guard were
        // ever weakened to check only one of the two collaborators, this would
        // catch it by observing the emitter got called anyway.
        $emitter = self::emitter();
        $logger = self::recordingLogger();
        $handler = new ObserveQuoteHandler(self::locks(free: true), $logger, null, $emitter);

        $handler(new ObserveQuoteMessage('quote-1'));

        self::assertSame(0, $emitter->spy->calls, 'no gateway means the emitter must never be observed');
        self::assertContains(
            'A2CN observation skipped: no commercial quote gateway.',
            $logger->messages,
            'the guard must log the skip, not fall through to fetchSnapshot() on a null gateway',
        );
    }

    /**
     * A busy lock is a RETRY, exactly as ServiceQuoteHandler treats it — and
     * on this module's primary path it is the normal case, not an edge one:
     * the `replied` transition that queues the observation happens INSIDE
     * ServiceQuoteHandler's own lock, so a worker that picks the message up
     * before servicing finishes finds the lock held. Dropping it there meant
     * the act was never emitted at all for the agent's own reply, silently,
     * because OfferVisibleStateSubscriber is the only trigger and the quote
     * stays in `replied`.
     */
    public function testABusyLockIsRetriedRatherThanDropped(): void
    {
        $emitter = self::emitter();
        $handler = new ObserveQuoteHandler(
            self::locks(free: false),
            new NullLogger(),
            new RecordingQuoteGateway(),
            $emitter,
        );

        try {
            $handler(new ObserveQuoteMessage('quote-1'));
            self::fail('a busy lock must park the message for a retry, not drop the observation');
        } catch (RecoverableMessageHandlingException $error) {
            self::assertSame(ServiceQuoteHandler::BUSY_RETRY_DELAY_MS, $error->getRetryDelay());
        }

        self::assertSame(0, $emitter->spy->calls);
    }

    /**
     * The busy lock is the ONLY throwing path. Everything else stays
     * fail-open: evidence must never park a message because our own signing,
     * store or gateway code broke.
     */
    public function testAFailingEmitterIsLoggedAndSwallowed(): void
    {
        $logger = self::recordingLogger();
        $handler = new ObserveQuoteHandler(
            self::locks(free: true),
            $logger,
            new RecordingQuoteGateway(),
            self::failingEmitter(),
        );

        $handler(new ObserveQuoteMessage('quote-1'));

        self::assertContains('A2CN observation failed outside the emitter.', $logger->messages);
    }

    public function testItObservesUnderTheLockAndReleasesIt(): void
    {
        $emitter = self::emitter();
        $locks = self::locks(free: true);
        $handler = new ObserveQuoteHandler($locks, new NullLogger(), new RecordingQuoteGateway(), $emitter);

        $handler(new ObserveQuoteMessage('quote-1'));

        self::assertSame(1, $emitter->spy->calls);
    }

    /**
     * SellerActEmitter is `readonly` (task 16), so a subclass must be
     * `readonly` too — which rules out a plain mutable counter property on
     * the subclass itself. The call count instead lives on a plain \stdClass
     * held through a readonly property: the property is assigned exactly
     * once (in the constructor, satisfying readonly), and every observe()
     * call only mutates the stdClass it already points to.
     *
     * @return SellerActEmitter&object{spy: \stdClass}
     */
    private static function emitter(): object
    {
        return new readonly class extends SellerActEmitter {
            public \stdClass $spy;

            public function __construct()
            {
                $this->spy = new \stdClass();
                $this->spy->calls = 0;
            }

            #[\Override]
            public function observe(
                \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $snapshot,
                \DateTimeImmutable $now,
            ): EmissionOutcome {
                ++$this->spy->calls;

                return EmissionOutcome::unchanged();
            }
        };
    }

    /** An emitter whose own work throws — the fail-open arm, not the lock arm. */
    private static function failingEmitter(): SellerActEmitter
    {
        return new readonly class extends SellerActEmitter {
            public function __construct() {}

            #[\Override]
            public function observe(
                \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $snapshot,
                \DateTimeImmutable $now,
            ): EmissionOutcome {
                throw new \RuntimeException('signing blew up');
            }
        };
    }

    /**
     * Records every log message verbatim, so a test can assert which line
     * fired.
     *
     * @return AbstractLogger&object{messages: list<string>}
     */
    private static function recordingLogger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            /**
             * @param mixed $level
             * @param array<array-key, mixed> $context
             */
            #[\Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }

    /**
     * Held locks from a busy fixture, kept alive so Symfony's Lock does not
     * auto-release them in __destruct() before the handler under test tries
     * to acquire the same one.
     *
     * @var list<\Symfony\Component\Lock\LockInterface>
     */
    private static array $held = [];

    /**
     * Built the same way the existing servicing tests build it
     * (tests/Unit/Servicing/ServicingHandlerFixture.php::refusalScenario()): a
     * real QuoteServicingLock over an in-memory Symfony lock store, not a
     * second lock fixture. `free: false` acquires through the SAME
     * QuoteServicingLock instance the handler will also use — LockFactory
     * caches its Key objects per resource string per factory instance, so two
     * separate factories over the same store would not actually contend.
     */
    private static function locks(bool $free): QuoteServicingLock
    {
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x');

        if (!$free) {
            $held = $locks->for('quote-1');
            $held->acquire();
            self::$held[] = $held;
        }

        return $locks;
    }
}
