<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

enum Band: string
{
    case Grant = 'grant';
    case Counter = 'counter';
    case Escalate = 'escalate';
}
