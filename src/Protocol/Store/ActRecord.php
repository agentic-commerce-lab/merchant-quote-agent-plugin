<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;

/**
 * One row of the mirror.
 *
 * The row's identity is (session, sequence, ROLE), not (session, sequence):
 * the wire deliberately keeps two acts at one sequence — that is what the
 * role suffix on the customFields key is for — so a mirror without the role
 * would collapse the buyer's act and ours onto one row and lose one of them.
 */
final readonly class ActRecord
{
    public function __construct(
        public string $sessionId,
        public string $quoteId,
        public int $sequence,
        public ActRole $role,
        public Act $act,
    ) {}
}
