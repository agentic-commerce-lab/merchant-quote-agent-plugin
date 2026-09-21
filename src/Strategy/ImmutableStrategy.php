<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * A write that would have broken one of the library's two invariants:
 * version rows never change, and built-in strategies never change.
 */
final class ImmutableStrategy extends \RuntimeException
{
    public static function version(): self
    {
        return new self(
            'A negotiation strategy version cannot be changed or deleted. Editing a strategy appends a new '
            . 'version, so that every past decision keeps resolving the prompt it actually used.',
        );
    }

    public static function builtIn(): self
    {
        return new self(
            'A built-in negotiation strategy cannot be changed or deleted. Use "Duplicate & edit" to make an '
            . 'editable copy of it.',
        );
    }

    public static function versionTransition(): self
    {
        return new self('A strategy version may only be accepted or rejected while it is a proposal, '
        . 'and its prompt can never change.');
    }
}
