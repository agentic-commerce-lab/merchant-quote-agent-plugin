<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Attempt;

interface ServicingAttemptStoreInterface
{
    public function recordDelivery(string $messageId): int;

    public function completeDelivery(string $messageId): void;
}
