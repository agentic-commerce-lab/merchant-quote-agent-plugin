<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/** Symfony honors Retry-After independently of strategy delays; refuse waits beyond our lock budget. */
final class ModelRetryStrategy extends GenericRetryStrategy
{
    private const DELAY_SECONDS = 2;

    public function __construct()
    {
        // Bare status codes also permit retrying POST; transport failures use 0.
        parent::__construct(
            [0, 423, 425, 429, 500, 502, 503, 504, 507, 510],
            delayMs: self::DELAY_SECONDS * 1000,
            multiplier: 1.0,
            jitter: 0.0,
        );
    }

    #[\Override]
    public function shouldRetry(
        AsyncContext $context,
        ?string $responseContent,
        ?TransportExceptionInterface $exception,
    ): ?bool {
        $after = $context->getHeaders()['retry-after'][0] ?? null;

        if ($after !== null && (!is_string($after) || self::exceedsBudget($after))) {
            return false;
        }

        return parent::shouldRetry($context, $responseContent, $exception);
    }

    private static function exceedsBudget(string $after): bool
    {
        if (is_numeric($after)) {
            $seconds = (float) $after;

            // Reject negatives before Symfony converts to integer milliseconds:
            // sufficiently large negative floats can overflow into a positive delay.
            return !is_finite($seconds) || $seconds < 0 || $seconds > self::DELAY_SECONDS;
        }

        $timestamp = strtotime($after);

        return $timestamp !== false && ($timestamp - time()) > self::DELAY_SECONDS;
    }
}
