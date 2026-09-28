<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/** Why a rounding was computed and the unrounded offer written instead (spec 2026-09-28, rules 2–4). */
enum RoundingSkip: string
{
    /** Rule 2: the offer is the buyer's own figure. */
    case BuyerFigure = 'buyer_figure';

    /** Rule 3: rounded, it would price a line above what the buyer already holds. */
    case StandingPrice = 'standing_price';

    /** Rule 4: rounded, no discount would be left. */
    case ToZero = 'to_zero';

    /**
     * quote_total only: a net quote with tax added on top. The absolute
     * discount is net there and the tax is rounded per rate after it, so no
     * amount is guaranteed to land on a round total.
     */
    case TaxOnTop = 'tax_on_top';
}
