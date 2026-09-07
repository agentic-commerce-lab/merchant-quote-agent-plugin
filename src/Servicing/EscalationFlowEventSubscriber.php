<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Puts QuoteAgentEscalatedEvent in the Administration's Flow Builder trigger
 * list. Without this the event still dispatches and a hand-written listener
 * still hears it, but no merchant can find it in the UI — which is the whole
 * point of using a business event.
 *
 * BusinessEventCollector reads its own registry first and then dispatches
 * BusinessEventCollectorEvent specifically so plugins can add to the result
 * ("allows to mutate different events by plugins", BusinessEventCollector.php).
 * That hook is the supported way in; there is no tag for it.
 *
 * `define()` may return null for an event whose name is empty, so the result is
 * checked rather than assumed — an unnamed definition added to the collection
 * would break the whole trigger list, not just ours.
 */
final readonly class EscalationFlowEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BusinessEventCollector $collector,
    ) {}

    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [BusinessEventCollectorEvent::NAME => 'onCollect'];
    }

    public function onCollect(BusinessEventCollectorEvent $event): void
    {
        $definition = $this->collector->define(QuoteAgentEscalatedEvent::class);

        if ($definition === null) {
            return;
        }

        $event->getCollection()->set($definition->getName(), $definition);
    }
}
