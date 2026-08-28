<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * Assembles the array `NegotiationPolicy::fromArray()` expects out of the
 * flat raw config values. Split out of QuoteAgentSettingsFactory so its own
 * per-field null-handling doesn't add to that class's complexity budget.
 */
final class NegotiationPolicyArray
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $raw
     * @param list<array{minQty: int, discountPercent: float}> $tiers
     *
     * @return array<string, mixed>
     *
     * @throws \TypeError see RawValueGuard
     */
    public static function build(array $raw, array $tiers): array
    {
        return array_filter(
            [
                'price' => self::price($raw),
                'delivery' => self::section([
                    'freeShippingAboveNet' => RawConfigValue::float($raw, 'deliveryFreeShippingAboveNet'),
                    'maxShippingWaiverNet' => RawConfigValue::float($raw, 'deliveryMaxShippingWaiverNet'),
                    'expeditedAllowed' => RawConfigValue::bool($raw, 'deliveryExpeditedAllowed') === true ? true : null,
                    'committedLeadTimeDaysMin' => RawConfigValue::int($raw, 'deliveryCommittedLeadTimeDaysMin'),
                ]),
                'payment' => self::section([
                    'allowedTerms' => RawConfigValue::stringList($raw, 'paymentAllowedTerms'),
                    'maxNetDays' => RawConfigValue::int($raw, 'paymentMaxNetDays'),
                    'minDepositPercent' => RawConfigValue::float($raw, 'paymentMinDepositPercent'),
                ]),
                'bundle' => self::section(['volumeTiers' => $tiers === [] ? null : $tiers]),
            ],
            static fn(mixed $section): bool => $section !== null,
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     *
     * @throws \TypeError see RawValueGuard
     */
    private static function price(array $raw): array
    {
        $ceilingNet = RawConfigValue::float($raw, 'maxQuoteValueNet');

        $price = [
            // Null means the merchant cleared the field. Zero is the safe
            // reading: every price ask escalates.
            'maxDiscountPercent' => RawConfigValue::float($raw, 'maxDiscountPercent') ?? 0.0,
            'counterOfferMaxPercent' => RawConfigValue::float($raw, 'counterOfferMaxPercent'),
            'validityDays' => RawConfigValue::int($raw, 'validityDays') ?? 0,
            'replyTone' => RawConfigValue::string($raw, 'replyTone'),
        ];

        if ($ceilingNet !== null) {
            $price['maxQuoteValueNet'] = $ceilingNet;
            $price['maxQuoteValueCurrency'] = RawConfigValue::string($raw, 'maxQuoteValueCurrency');
        }

        return $price;
    }

    /**
     * A sub-policy is emitted only when the merchant set at least one of its
     * fields. Blank means null, and null is what makes DeliveryDecider answer
     * "no delivery policy configured" rather than a subtly different per-field
     * reason.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>|null
     */
    private static function section(array $fields): ?array
    {
        $set = array_filter($fields, static fn(mixed $value): bool => $value !== null);

        return $set === [] ? null : $set;
    }
}
