<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * A sales channel points at a strategy that cannot be used.
 *
 * Deliberately fatal to the configuration rather than a silent fallback to no
 * strategy: falling back would change that channel's negotiating behaviour
 * invisibly, where this escalates the quote to a human instead. The reader
 * turns it into InvalidQuoteAgentConfiguration, which ServicingPreflight
 * already handles.
 */
final class UnknownStrategy extends \RuntimeException
{
    public static function missing(string $id): self
    {
        return new self(sprintf(
            'The configured negotiation strategy (%s) no longer exists. Pick one in the plugin configuration.',
            $id,
        ));
    }

    public static function archived(string $id): self
    {
        return new self(sprintf(
            'The configured negotiation strategy (%s) has been archived. Pick a live one in the plugin '
            . 'configuration, or restore it.',
            $id,
        ));
    }

    public static function withoutVersion(string $id): self
    {
        return new self(sprintf(
            'The configured negotiation strategy (%s) has no prompt version, so there is nothing to send. Open it '
            . 'in Settings → Negotiation strategies and save a prompt.',
            $id,
        ));
    }
}
