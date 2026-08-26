<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

enum PaymentTerm: string
{
    case Prepaid = 'prepaid';
    case Net15 = 'net_15';
    case Net30 = 'net_30';
    case Net60 = 'net_60';
    case Net90 = 'net_90';
}
