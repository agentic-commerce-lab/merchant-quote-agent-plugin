<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;

/**
 * `base64url(SHA-256(JCS([protocol_act_hash, …])))` in chain order.
 *
 * This is what makes a dropped act detectable after the fact: a chain missing
 * one act cannot reproduce the hash the parties agreed on.
 */
final readonly class OfferChainHash
{
    public function __construct(
        private ProtocolHash $hash,
    ) {}

    /** @param list<Act> $acts */
    public function of(array $acts): string
    {
        return $this->hash->of(array_map(static fn(Act $act): string => $act->hash(), $acts));
    }
}
