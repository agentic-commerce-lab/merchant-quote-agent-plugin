<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * A write that would have broken one of the library's invariants: a version
 * row never changes once it is `active` or `rejected` (a `proposed` row may
 * move to one of those exactly once -- see VersionTransition for that one
 * admitted exception), and a built-in strategy never changes at all.
 */
final class ImmutableStrategy extends \RuntimeException
{
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
