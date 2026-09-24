<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * The three ways the handler declines a delivery without servicing it, and the
 * disposition each one asks Messenger for. Separate from ServiceQuoteHandlerTest
 * because that class covers the pass itself and is already at the
 * too-many-methods ceiling.
 */
final class ServiceQuoteHandlerRefusalTest extends TestCase
{
    /**
     * #4 asks for a loud log line AND a parked message, not a silent ack: the
     * buyer asked and got nothing, and re-licensing the shop is what makes the
     * message worth replaying out of the `failed` transport.
     */
    public function testANullGatewayParksTheMessageWithoutServicingOrLocking(): void
    {
        $locks = ServicingHandlerFixture::locks();
        $pipeline = ServicingHandlerFixture::countingPipeline();
        $handler = new ServiceQuoteHandler(
            $locks,
            ServicingTestJournal::create(),
            ServicingSettingsFixture::preflightReturning(ServicingSettingsFixture::settings()),
            null,
            $pipeline,
        );

        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            $handler(ServicingHandlerFixture::message());
        } finally {
            self::assertSame(0, $pipeline->passes, 'A quote was serviced without a gateway.');
            self::assertTrue(
                $locks->for('q1')->acquire(),
                'The handler took a lock before checking the gateway, so an unlicensed shop still '
                . 'serialises on a lock it can never use.',
            );
        }
    }

    /**
     * Messenger's default backoff multiplies by 2 against `max_delay: 0` —
     * unbounded, verified against this shop's `debug:config framework
     * messenger` — so a quote that stays busy backs off to hours. Lock
     * contention resolves on the scale of one servicing pass.
     */
    public function testABusyQuoteAsksForAFlatRetryRatherThanExponentialBackoff(): void
    {
        $locks = ServicingHandlerFixture::locks();
        // Held in a local for the rest of the method: Symfony's Lock releases
        // itself in __destruct() when autoRelease is true, so a temporary would
        // be collected — and its lock freed — before the handler tries to
        // acquire it.
        $held = $locks->for('q1');
        $held->acquire();
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $handler = ServicingHandlerFixture::handler($gateway, ServicingHandlerFixture::countingPipeline(), $locks);

        try {
            $handler(ServicingHandlerFixture::message());
            self::fail('A busy quote was not refused for retry.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(5000, $e->getRetryDelay());
        }

        self::assertTrue($held->isAcquired());
    }

    /**
     * A comment can be written against an accepted quote — it is a
     * conversation, not a closed file — and the trigger has no state filter on
     * the comment path. Handing that to the pipeline means every write it makes
     * is refused by SwagCommercial.
     *
     * @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions
     */
    #[DataProvider('terminalStates')]
    public function testATerminalQuoteIsNeitherServicedNorStamped(string $state): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot(state: $state)]);
        $pipeline = ServicingHandlerFixture::countingPipeline();

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        self::assertSame(0, $pipeline->passes, sprintf('A %s quote was handed to the pipeline.', $state));
        self::assertSame(
            [],
            $gateway->customFieldWrites,
            'Nothing was serviced, so nothing may be stamped — a stamp would suppress the real '
            . 'trigger if the quote is ever reopened.',
        );
    }

    /**
     * SwagCommercial's own NON_EDITABLE_STATES (QuoteSnapshotVersionResolver),
     * mirrored on ServicingPreflight.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function terminalStates(): iterable
    {
        yield 'accepted' => ['accepted'];
        yield 'declined' => ['declined'];
        yield 'expired' => ['expired'];
        yield 'cancelled' => ['cancelled'];
    }
}
