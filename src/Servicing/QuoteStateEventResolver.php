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

        return self::contextOrNull($event->getContext());
    }

    public static function extractQuoteId(object $event): ?string
    {
        if (method_exists($event, 'getQuoteId')) {
            return self::nonEmptyStringOrNull($event->getQuoteId());
        }

        if (method_exists($event, 'getTransition')) {
            return self::extractTransitionQuoteId($event->getTransition());
        }

        return null;
    }

    private static function contextOrNull(mixed $context): ?Context
    {
        return $context instanceof Context ? $context : null;
    }

    private static function extractTransitionQuoteId(mixed $transition): ?string
    {
        if (!\is_object($transition) || !method_exists($transition, 'getEntityId')) {
            return null;
        }

        return self::nonEmptyStringOrNull($transition->getEntityId());
    }

    private static function nonEmptyStringOrNull(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
