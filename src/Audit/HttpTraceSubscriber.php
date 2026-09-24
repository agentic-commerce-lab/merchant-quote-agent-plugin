<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Records one bounded event for each decision-bearing plugin HTTP request. */
final readonly class HttpTraceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HttpTraceCapture $capture,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !HttpTraceRoute::recorded($route)) {
            return;
        }

        try {
            $this->capture->record($route, $event->getRequest(), $event->getResponse());
        } catch (\Throwable $error) {
            $this->logger->error('An HTTP trace event could not be recorded.', [
                'route' => $route,
                'exception' => $error,
            ]);
        }
    }
}
