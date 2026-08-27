<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * Which DAL version lane to read. `Snapshot` is SwagCommercial's fixed
 * "what the counterparty last saw" lane; the gateway maps these onto the
 * actual version ids, since naming them here would leak a commercial constant.
 */
enum QuoteVersion
{
    case Live;
    case Snapshot;
}
