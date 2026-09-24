<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

/** The part of servicing that made the skip decision. */
enum SkipSource: string
{
    case Handler = 'handler';
    case Preflight = 'preflight';
    case Observer = 'observer';
}
