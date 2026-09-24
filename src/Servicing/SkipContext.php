<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;

/** The identifiers and safe machine values known at an early exit. */
final readonly class SkipContext
{
    private function __construct(
        public ?string $quoteId,
        public ?string $customerId,
        public ?string $trigger,
        public ?int $attempt,
    ) {}

    public static function forMessage(
        ServiceQuoteMessage $message,
        ?int $attempt = null,
        ?string $customerId = null,
    ): self {
        return new self(
            $message->quoteId,
            $customerId === '' ? null : $customerId,
            ServicingTriggerReason::tryFrom($message->reason)?->value,
            $attempt,
        );
    }

    public static function forSnapshot(QuoteSnapshot $snapshot, PassContext $context): self
    {
        return new self(
            $snapshot->identity->quoteId,
            $snapshot->identity->customerId === '' ? null : $snapshot->identity->customerId,
            $context->reason->value,
            $context->attempt,
        );
    }
}
