<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

/**
 * The two-stage gate from ADR 0001: class existence decides whether the
 * gateway is built, the license toggle decides whether it can serve.
 */
final class CommercialAvailability
{
    /**
     * The toggle every commercial quote SERVICE checks on entry. Not
     * QUOTE_MANAGEMENT-8702512, which only guards the Admin API route this
     * bridge deliberately does not use.
     */
    public const LICENSE_TOGGLE = 'QUOTE_MANAGEMENT-6302947';

    /**
     * The five commercial services this bridge injects, and the DI ids they
     * are registered under: SwagCommercial writes `$services->set(<FQCN>)`
     * with no alias, so the id IS the class name. Public because
     * `services.php` and the integration harness must name the same strings —
     * a rename on SwagCommercial's side has to be a one-line fix here, not a
     * hunt. Class-name literals, not `::class`: these need not be loadable.
     *
     * GatewayWiringTest resolves all five against the live shop, which is what
     * catches a rename or a typo — static analysis cannot see these.
     */
    public const QUOTE_MANIPULATION = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\Admin\\QuoteManipulation';

    public const QUOTE_COMMENTER = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\Comment\\QuoteCommenter';

    public const CONTEXT_RESTORER = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\SalesChannelContextRestorer\\SalesChannelContextRestorer';

    public const QUOTE_CALCULATOR = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\Recalculation\\QuoteCalculator';

    public const QUOTE_TO_CART_CONVERTER = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteToCart\\QuoteToCartConverter';

    /**
     * The nine commercial services the buyer-side gateway injects, same
     * convention as the four above: GatewayWiringTest resolves all of them
     * against the live shop, so a SwagCommercial rename is a one-line fix
     * here, not a hunt.
     */
    public const QUOTE_REQUEST_ROUTE = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\CartToQuote\\QuoteRequestRoute';

    public const QUOTE_SEND_REQUEST_ROUTE = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteSendRequestRoute';

    public const QUOTE_LINE_ITEM_ROUTE = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\LineItem\\QuoteLineItemRoute';

    public const QUOTE_LOAD_ROUTE = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteAccounting\\QuoteLoadRoute';

    public const QUOTE_LISTING_ROUTE = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteAccounting\\QuoteListingRoute';

    public const QUOTE_REQUEST_CHANGE_ROUTE = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteRequestChangeRoute';

    public const QUOTE_DECLINE_ROUTE = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\State\\QuoteDeclineRoute';

    public const QUOTE_ORDER_ROUTE = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\QuoteToOrder\\QuoteOrderRoute';

    public const CUSTOMER_SPECIFIC_FEATURE_SERVICE = 'Shopware\\Commercial\\B2B\\CustomerSpecificFeatures\\Domain\\CustomerSpecificFeature\\CustomerSpecificFeatureService';

    private const LICENSE_CLASS = 'Shopware\\Commercial\\Licensing\\License';

    /**
     * Checked on the two `@internal` classes plus License rather than on all
     * four: those are the ones whose absence changes what this bridge can do,
     * and they come from the same plugin as the other two.
     */
    public static function isAvailableByClass(): bool
    {
        return (
            class_exists(self::QUOTE_MANIPULATION)
            && class_exists(self::QUOTE_COMMENTER)
            && class_exists(self::LICENSE_CLASS)
        );
    }

    public static function isLicensed(): bool
    {
        if (!self::isAvailableByClass()) {
            return false;
        }

        try {
            /** @var callable(string): (string|bool|int) $get */
            $get = [self::LICENSE_CLASS, 'get'];

            return $get(self::LICENSE_TOGGLE) !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
