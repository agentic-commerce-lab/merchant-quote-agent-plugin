<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Attempt;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/** @extends EntityCollection<ServicingAttemptEntity> */
final class ServicingAttemptCollection extends EntityCollection
{
    #[\Override]
    public function getApiAlias(): string
    {
        return 'merchant_quote_agent_servicing_attempt_collection';
    }

    /** @return class-string<ServicingAttemptEntity> */
    #[\Override]
    protected function getExpectedClass(): string
    {
        return ServicingAttemptEntity::class;
    }
}
