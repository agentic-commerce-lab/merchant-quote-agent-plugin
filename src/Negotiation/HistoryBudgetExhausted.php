<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;

/** Keeps the last request for audit without resolving a read the model will never see. */
final class HistoryBudgetExhausted extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly NegotiateResponse $response,
    ) {
        parent::__construct($message);
    }

    public static function after(int $rounds, NegotiateResponse $response): self
    {
        return new self(sprintf('The agent requested more history after %d history rounds.', $rounds), $response);
    }
}
