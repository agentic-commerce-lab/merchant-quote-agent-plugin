<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

/**
 * SwagCommercial's "the buyer asked for a quote" event: its name, and the one
 * field the servicing trigger reads from it.
 *
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Event\QuoteRequestedEvent::getQuoteId()
 * — from its QuoteAware interface, declared on every release from the 6.7.1.2
 * floor to trunk (ReleaseCapabilityMatrixTest). Dispatched by class name, so
 * this is the key to subscribe on.
 */
final class QuoteRequested
{
    public const EVENT = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Event\\QuoteRequestedEvent';

    private function __construct() {}

    /**
     * Null for an event of any other shape rather than an Error: the listener
     * runs inside the buyer's own request, and a throw there fails their quote.
     */
    public static function quoteId(object $event): ?string
    {
        if (!method_exists($event, 'getQuoteId')) {
            return null;
        }

        /** @mago-expect analysis:mixed-assignment */
        $quoteId = $event->getQuoteId();

        return \is_string($quoteId) ? $quoteId : null;
    }
}
