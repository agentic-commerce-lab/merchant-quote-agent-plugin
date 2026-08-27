<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Normalizes SwagCommercial quote state transitions and buyer quote comments
 * into asynchronous ServiceQuoteMessage dispatches.
 */
final readonly class QuoteServicingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            'quote.requested' => 'onQuoteStateEnter',
            'state_enter.quote.state.open' => 'onQuoteStateEnter',
            'state_enter.quote.state.in_review' => 'onQuoteStateEnter',
            'state_enter.quote.state.change_requested' => 'onQuoteStateEnter',
            'quote_comment.written' => 'onQuoteCommentWritten',
        ];
    }

    /** @throws ExceptionInterface */
    public function onQuoteStateEnter(object $event): void
    {
        $context = QuoteStateEventResolver::extractContext($event);
        if ($context === null || $context->hasState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING)) {
            return;
        }

        if ($this->gateway === null) {
            return;
        }

        $quoteId = QuoteStateEventResolver::extractQuoteId($event);
        if ($quoteId === null) {
            return;
        }

        try {
            $snapshot = $this->gateway->fetchSnapshot($quoteId);
        } catch (QuoteNotFoundException) {
            return;
        }

        $this->bus->dispatch(
            new ServiceQuoteMessage(
                Uuid::randomHex(),
                $quoteId,
                $snapshot->identity->salesChannelId,
                $snapshot->revision,
            ),
        );
    }

    /** @throws ExceptionInterface */
    public function onQuoteCommentWritten(EntityWrittenEvent $event): void
    {
        if ($this->gateway === null) {
            return;
        }

        QuoteCommentEventDispatcher::dispatchEligible($event, $this->gateway, $this->bus);
    }
}
