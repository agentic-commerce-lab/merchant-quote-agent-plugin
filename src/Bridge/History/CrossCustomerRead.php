<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

/**
 * A history read returned a row belonging to another company.
 *
 * This should be unreachable: CustomerScope applies the filter to every
 * Criteria it builds, and it builds all of them. It exists because "should be
 * unreachable" is not a security control — a wrong field path in a new read, or
 * an association silently not loaded, would both land here rather than in a
 * prompt.
 *
 * Carries NEITHER customer id. The message reaches the merchant-readable
 * `violations` audit column, and one of the two ids belongs to a company that
 * is not party to this quote.
 */
final class CrossCustomerRead extends \RuntimeException
{
    public static function of(string $what): self
    {
        return new self(sprintf(
            'A history read returned %s, which does not belong to this quote\'s customer. ' . 'The read was refused.',
            $what,
        ));
    }
}
