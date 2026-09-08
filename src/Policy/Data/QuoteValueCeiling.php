<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The merchant's one number: above this net total the quote always escalates.
 *
 * It carried its own ISO currency until the field left the admin (issue #2(b)
 * originally added it). The field defaulted to blank, so the currency check it
 * fed was dormant for every shop that never typed a code; with the field gone
 * the ceiling is simply read in the quote's own currency.
 *
 * ponytail: a multi-currency channel therefore compares the same number
 * against every currency. Give the ceiling a currency again -- derived from
 * the sales channel, not typed by hand -- if that shows up.
 */
final readonly class QuoteValueCeiling
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public float $net,
    ) {}
}
