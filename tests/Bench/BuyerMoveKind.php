<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

/**
 * What a synthetic buyer decided to do with a round's offer.
 */
enum BuyerMoveKind: string
{
    case Accept = 'accept';
    case Counter = 'counter';
    case Walk = 'walk';
}
