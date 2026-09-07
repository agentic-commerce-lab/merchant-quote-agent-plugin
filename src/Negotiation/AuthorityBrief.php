<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;

/**
 * The merchant's authority, written out for the negotiate prompt.
 *
 * Until this existed the prompt stated the discount cap and nothing else, while
 * the response schema advertised `terms.delivery` and `terms.payment`. A model
 * told it may grant a discount and nothing about terms does the only safe
 * thing available to it: declines the term or escalates. Observed live on a
 * shop with `paymentAllowedTerms = [prepaid, net_30]` configured — "I don't
 * have approval to change payment terms in this response" — so a merchant who
 * had allowed net 30 could never actually have it offered.
 *
 * Every dimension states what is NOT permitted as well, because silence is
 * what produced that failure. An unset policy is a refusal, not an omission:
 * OfferAuthorizer rejects the concession either way, and the difference is
 * whether the buyer gets a considered counter or a human hand-off.
 *
 * Rendered per dimension in its own method so the class stays inside the
 * complexity gate; `optional()` is what keeps a field-per-branch out of it.
 */
final class AuthorityBrief
{
    private function __construct() {}

    public static function of(NegotiationPolicy $policy, ?float $counteredRequestPercent): string
    {
        return implode("\n", [
            sprintf('- maximum discount you may grant: %.2f%%', $policy->price->maxDiscountPercent),
            ...self::optional([
                '- the buyer asked for %.2f%%, which is above your cap: counter, do not grant it' =>
                    $counteredRequestPercent,
            ]),
            ...self::delivery($policy->delivery),
            ...self::payment($policy->payment),
            ...self::bundle($policy->bundle),
        ]);
    }

    /**
     * One line per field the merchant actually set. A null field is not
     * mentioned at all — its dimension's opening line already said whether the
     * concession is on the table.
     *
     * @param array<string, float|int|null> $fields sprintf format => value
     *
     * @return list<string>
     */
    private static function optional(array $fields): array
    {
        $lines = [];

        foreach ($fields as $format => $value) {
            if ($value !== null) {
                $lines[] = sprintf($format, $value);
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function delivery(?DeliveryPolicy $policy): array
    {
        if ($policy === null) {
            return ['- you may NOT offer free shipping, expedited shipping or a lead-time commitment'];
        }

        return [
            $policy->expeditedAllowed === true
                ? '- you may offer expedited shipping'
                : '- you may NOT offer expedited shipping',
            ...self::optional([
                '- you may waive shipping on orders above %.2f net' => $policy->freeShippingAboveNet,
                '- the most shipping cost you may waive is %.2f net' => $policy->maxShippingWaiverNet,
                '- the shortest lead time you may commit to is %d days' => $policy->committedLeadTimeDaysMin,
            ]),
        ];
    }

    /** @return list<string> */
    private static function payment(?PaymentPolicy $policy): array
    {
        if ($policy === null || $policy->allowedTerms === []) {
            return ['- you may NOT offer any payment term, deposit or net-days concession'];
        }

        $terms = array_map(static fn(PaymentTerm $term): string => $term->value, $policy->allowedTerms);

        return [
            '- payment terms you may grant: ' . implode(', ', $terms),
            ...self::optional([
                '- the most net days you may grant is %d' => $policy->maxNetDays,
                '- any deposit you ask for must be at least %.2f%%' => $policy->minDepositPercent,
            ]),
        ];
    }

    /**
     * Volume tiers are guidance rather than a cap: the discount they imply is
     * still bounded by maxDiscountPercent above, and OfferAuthorizer checks it.
     *
     * @return list<string>
     */
    private static function bundle(?BundlePolicy $policy): array
    {
        if ($policy === null || $policy->volumeTiers === []) {
            return [];
        }

        $tiers = array_map(static fn(VolumeTier $tier): string => sprintf(
            '%d+ units → %.2f%%',
            $tier->minQty,
            $tier->discountPercent,
        ), $policy->volumeTiers);

        return ['- volume pricing the merchant publishes: ' . implode(', ', $tiers)];
    }
}
