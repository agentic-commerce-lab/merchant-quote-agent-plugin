<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Symfony honors Retry-After independently of strategy delays; refuse waits beyond our lock budget.
 *
 * Also remembers each attempt it sent round again, for the call's trace
 * (ModelPlatform resets it per logical call). Mutable on a shared service,
 * which is safe for the reason DecisionRecorder gives: a worker runs one pass,
 * and one call, at a time.
 */
final class ModelRetryStrategy extends GenericRetryStrategy
{
    private const DELAY_SECONDS = 2;

    /** @var list<array{httpStatus: int, transportError: bool}> */
    private array $attempts = [];

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

    public function reset(): void
    {
        $this->attempts = [];
    }

    /** @return list<array{httpStatus: int, transportError: bool}> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    #[\Override]
    public function shouldRetry(
        AsyncContext $context,
        ?string $responseContent,
        ?TransportExceptionInterface $exception,
    ): ?bool {
        $after = $context->getHeaders()['retry-after'][0] ?? null;
        $retry =
            $after !== null && (!is_string($after) || self::exceedsBudget($after))
                ? false
                : parent::shouldRetry($context, $responseContent, $exception);

        // Only an attempt that is tried again: the last one's fate is the
        // call's own status, recorded by ModelPlatform. 0 is "no response".
        if ($retry === true) {
            $this->attempts[] = ['httpStatus' => $context->getStatusCode(), 'transportError' => $exception !== null];
        }

        return $retry;
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
