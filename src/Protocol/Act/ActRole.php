<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/** Which party wrote an act. Part of the customFields key, so appends never collide. */
enum ActRole: string
{
    case Buyer = 'b';
    case Seller = 's';

    /**
     * The writer of an act, read back off its wire key.
     *
     * Not derived from the act's sender DID, because the mirror runs BEFORE
     * identity resolution (SellerActEmitter::run(): a buyer act on a quote we
     * cannot yet identify ourselves for must still reach our own copy), so
     * there is no seller DID to compare against at that point.
     *
     * Total by design: we only ever write `…_s` keys, so any other act key on
     * the chain is the counterparty's.
     */
    public static function fromKey(string $key): self
    {
        return str_ends_with($key, '_' . self::Seller->value) ? self::Seller : self::Buyer;
    }
}
