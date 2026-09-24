<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/** Closed route selection keeps static documents and catch-all misses out. */
final class HttpTraceRoute
{
    public const PREFIX = 'frontend.merchant_quote_agent.';

    private function __construct() {}

    public static function recorded(string $route): bool
    {
        if (!str_starts_with($route, self::PREFIX)) {
            return false;
        }

        $name = substr($route, \strlen(self::PREFIX));
        if (str_starts_with($name, 'quote.')) {
            return !\in_array($name, ['quote.schema', 'quote.spec'], true);
        }

        return (
            str_starts_with($name, 'a2cn.messages.')
            || $name === 'a2cn.acts'
            || str_starts_with($name, 'a2cn.record')
            || self::identity($route)
        );
    }

    public static function identity(string $route): bool
    {
        return str_starts_with($route, self::PREFIX . 'authorize') || $route === self::PREFIX . 'authorization_request';
    }
}
