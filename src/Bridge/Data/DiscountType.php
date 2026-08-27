<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case Absolute = 'absolute';
}
