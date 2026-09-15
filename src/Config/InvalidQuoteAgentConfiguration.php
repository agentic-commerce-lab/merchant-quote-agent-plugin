<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * Every reason the configuration is unusable, collected rather than
 * short-circuited: a merchant fixing one field at a time and re-saving is a
 * worse experience than being told all of it at once.
 */
final class InvalidQuoteAgentConfiguration extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(
        public readonly array $problems,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Quote agent configuration is invalid: ' . implode('; ', $problems), 0, $previous);
    }
}
