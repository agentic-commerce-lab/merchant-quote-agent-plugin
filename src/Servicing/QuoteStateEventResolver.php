<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use Shopware\Core\Framework\Context;

final class QuoteStateEventResolver
{
    private function __construct() {}

    public static function extractContext(object $event): ?Context
    {
        if (!method_exists($event, 'getContext')) {
            return null;
        }

        $context = $event->getContext();

        return $context instanceof Context ? $context : null;
    }

    public static function extractQuoteId(object $event): ?string
    {
        if (method_exists($event, 'getQuoteId')) {
            $quoteId = $event->getQuoteId();

            return \is_string($quoteId) && $quoteId !== '' ? $quoteId : null;
        }

        if (method_exists($event, 'getTransition')) {
            $transition = $event->getTransition();
            if (\is_object($transition) && method_exists($transition, 'getEntityId')) {
                $quoteId = $transition->getEntityId();

                return \is_string($quoteId) && $quoteId !== '' ? $quoteId : null;
            }
        }

        return null;
    }
}
