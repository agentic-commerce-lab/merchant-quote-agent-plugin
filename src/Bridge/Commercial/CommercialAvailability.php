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

    /** Class-name literals, not `::class`: these need not be loadable. */
    private const MANIPULATION_CLASS = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\Admin\\QuoteManipulation';
    private const COMMENTER_CLASS = 'Shopware\\Commercial\\B2B\\QuoteManagement\\Domain\\Comment\\QuoteCommenter';
    private const LICENSE_CLASS = 'Shopware\\Commercial\\Licensing\\License';

    public static function isAvailableByClass(): bool
    {
        return (
            class_exists(self::MANIPULATION_CLASS)
            && class_exists(self::COMMENTER_CLASS)
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
