<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * A version row's place in the lineage.
 *
 * Only `Active` rows are resolvable (see StrategyResolver): a proposal written
 * by the nightly improvement run is a real row in the real table that the
 * negotiation path structurally cannot reach until a human accepts it.
 *
 * `Rejected` is terminal and kept rather than deleted, so the next night can
 * see what the merchant already turned down.
 */
enum VersionStatus: string
{
    case Active = 'active';
    case Proposed = 'proposed';
    case Rejected = 'rejected';
}
