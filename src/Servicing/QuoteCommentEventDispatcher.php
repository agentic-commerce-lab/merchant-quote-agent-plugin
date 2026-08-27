<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class QuoteCommentEventDispatcher
{
    private function __construct() {}

    /** @throws ExceptionInterface */
    public static function dispatchEligible(
        EntityWrittenEvent $event,
        QuoteGatewayInterface $gateway,
        MessageBusInterface $bus,
    ): void {
        if ($event->getContext()->hasState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING)) {
            return;
        }

        /** @var array<string, bool> $dispatchedQuotes */
        $dispatchedQuotes = [];

        foreach ($event->getWriteResults() as $result) {
            $payload = $result->getPayload();
            $quoteId = $payload['quoteId'] ?? null;

            if (!\is_string($quoteId) || ($dispatchedQuotes[$quoteId] ?? false)) {
                continue;
            }

            try {
                $snapshot = $gateway->fetchSnapshot($quoteId);
            } catch (QuoteNotFoundException) {
                continue;
            }

            if (QuoteCommentFilter::shouldSkipComment($payload, $snapshot)) {
                continue;
            }

            if (!QuoteCommentFilter::isServiceableState($snapshot->lifecycle->stateTechnicalName)) {
                continue;
            }

            $bus->dispatch(
                new ServiceQuoteMessage(
                    Uuid::randomHex(),
                    $quoteId,
                    $snapshot->identity->salesChannelId,
                    $snapshot->revision,
                ),
            );

            $dispatchedQuotes[$quoteId] = true;
        }
    }
}
