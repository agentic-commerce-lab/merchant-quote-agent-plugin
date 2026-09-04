<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/** Which party wrote an act. Part of the customFields key, so appends never collide. */
enum ActRole: string
{
    case Buyer = 'b';
    case Seller = 's';
}
