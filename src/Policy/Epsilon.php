<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

/**
 * Float-comparison tolerances, ported verbatim from the TS source's two
 * conventions: `EPSILON = 1e-6` for percentages/rates ("exactly at the limit
 * is inclusive"), and `EPSILON = 0.01` for money-rounding slack in
 * offer-verification.ts.
 *
 * RATE only covers a rate that is stated (the model's percentage). A rate
 * DERIVED from cent-rounded money carries far more noise than 1e-6; the band
 * compares those with MoneyMath::roundingSlackPercent() instead.
 */
final class Epsilon
{
    public const float RATE = 1e-6;

    public const float MONEY = 0.01;
}
