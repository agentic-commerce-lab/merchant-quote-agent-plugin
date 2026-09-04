<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;

/**
 * One reason not to counter-sign into a chain.
 *
 * Implementations are registered in order (services.php) and run cheapest
 * first: every local comparison before anything that touches the network.
 */
interface EvidenceCheckInterface
{
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation;
}
