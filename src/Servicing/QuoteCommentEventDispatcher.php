<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
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
        /** @var array<string, bool> $dispatchedQuotes */
        $dispatchedQuotes = [];

        foreach ($event->getWriteResults() as $result) {
            if (!QuoteCommentWriteResultInspector::isLive($result)) {
                continue;
            }

            $payload = $result->getPayload();
            $quoteId = QuoteCommentWriteResultInspector::quoteId($result);
            if ($quoteId === null || ($dispatchedQuotes[$quoteId] ?? false)) {
                continue;
            }

            try {
                $snapshot = $gateway->fetchSnapshot($quoteId);
            } catch (QuoteNotFoundException) {
                continue;
            }

            if (QuoteCommentFilter::shouldSkipComment(
                $payload,
                QuoteCommentWriteResultInspector::commentId($result),
                $snapshot,
            )) {
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
