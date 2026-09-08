<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;

/**
 * The merchant's authority, written out for the negotiate prompt.
 *
 * Price only, and deliberately so. This used to render delivery and payment
 * authority too, because a model told nothing about terms declines them or
 * escalates -- observed live as "I don't have approval to change payment terms
 * in this response". Those dimensions have since been removed altogether:
 * AskGate hands every non-price ask to a human, so there is no term for the
 * model to offer and nothing for the brief to permit.
 *
 * It also used to publish the volume tiers, which the model read as a floor to
 * volunteer rather than a rate to honour when asked -- quote 1017 answered a
 * 2.70% ask with 5% and cited the tier while doing it. A published tier is a
 * mandate claim, not negotiating authority, so it is stated in the signed
 * mandate and not here.
 *
 * `maxDiscountPercent` arrives already tightened to the buyer's own ask by
 * AskedDiscountCeiling, so the cap stated here is the one the agent may
 * actually reach.
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
}
