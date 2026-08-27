<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * The trigger and handler are wired by services.php, which no unit test can
 * reach. This is possible at all because the plugin is installed and active in
 * this shop (#8) — before that, services.php never loaded here.
 */
final class ServicingWiringTest extends IntegrationTestCase
{
    public function testTheTriggerIsRegisteredForBothEventNames(): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        foreach (array_keys(QuoteServicingTrigger::getSubscribedEvents()) as $eventName) {
            self::assertTrue(
                $dispatcher->hasListeners($eventName),
                sprintf('Nothing listens to "%s"; the servicing trigger is not wired.', $eventName),
            );

            $classes = array_map(static fn(array|object $listener): string => \is_array($listener)
                && \is_object($listener[0])
                    ? $listener[0]::class
                    : $listener::class, $dispatcher->getListeners($eventName));

            self::assertContains(
                QuoteServicingTrigger::class,
                $classes,
                sprintf('The servicing trigger is not among the listeners for "%s".', $eventName),
            );
        }
    }

    public function testTheHandlerAndLockResolve(): void
    {
        $handler = static::getContainer()->get(ServiceQuoteHandler::class);
        self::assertInstanceOf(ServiceQuoteHandler::class, $handler);

        $locks = static::getContainer()->get(QuoteServicingLock::class);
        self::assertInstanceOf(QuoteServicingLock::class, $locks);
        self::assertTrue($locks->for('wiring-probe')->acquire(), 'The wired lock factory cannot acquire.');
    }

    /**
     * Implementing AsyncMessageInterface is the entire routing configuration.
     * If Shopware ever stops routing that interface to `async`, servicing
     * silently becomes synchronous — worth an assertion rather than a comment.
     *
     * Asked of Messenger's own SendersLocator rather than of a container
     * parameter: there is no `messenger.routing` parameter in this Shopware
     * build (checked), and the locator is what actually decides where a message
     * goes, so this asserts the real routing decision rather than a config shape
     * that might not drive it.
     */
    public function testTheMessageIsRoutedToTheAsyncTransport(): void
    {
        $message = ServiceQuoteMessage::because('probe', ServicingTriggerReason::StateEntered);
        self::assertInstanceOf(AsyncMessageInterface::class, $message);

        $locator = static::getContainer()->get('messenger.senders_locator');
        self::assertInstanceOf(SendersLocator::class, $locator);

        // getSenders() yields $transportAlias => $sender.
        $aliases = array_keys(iterator_to_array($locator->getSenders(new Envelope($message))));

        self::assertSame(
            ['async'],
            $aliases,
            'ServiceQuoteMessage no longer routes to the async transport, so servicing would run '
            . 'in the triggering request — where LLM latency of seconds is not acceptable.',
        );
    }

    /**
     * The segment nothing else on this branch exercises: encode, cross the
     * transport, decode. `messenger.default_serializer` is the container's real
     * serializer rather than one of our own choosing, so this test tracks
     * whatever Shopware configures for the `async` transport.
     */
    public function testTheMessageSurvivesTheTransportSerializer(): void
    {
        $serializer = static::getContainer()->get('messenger.default_serializer');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        $message = ServiceQuoteMessage::because('probe', ServicingTriggerReason::StateEntered);
        $decoded = $serializer->decode($serializer->encode(new Envelope($message)))->getMessage();

        self::assertInstanceOf(ServiceQuoteMessage::class, $decoded);
        self::assertSame(
            'probe',
            $decoded->quoteId,
            'The message did not survive encode/decode, so '
            . 'every servicing message dies in the worker and dead-letters.',
        );
        self::assertSame('state_entered', $decoded->reason);
    }
}
