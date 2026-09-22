<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;

/**
 * Removes one buyer from the decision records, keeping the decisions. An
 * interface so DecisionForgetCommand is unit-testable without a DAL, exactly
 * as DecisionRecordWriterInterface does for the recorder.
 */
interface DecisionEraserInterface
{
    /** @return int the number of records changed */
    public function forget(string $customerId, ?Context $context = null): int;
}
