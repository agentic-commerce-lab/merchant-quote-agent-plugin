<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures;

/**
 * The same quote comment, on a shop where a comment can be scoped to one
 * line: `quoteLineItemId` is declared here, so `Entity::get()` returns it
 * rather than throwing.
 */
final class ModernQuoteCommentEntity extends LegacyQuoteCommentEntity
{
    protected ?string $quoteLineItemId;

    public function __construct(
        string $comment = 'Can you do better on price?',
        ?string $createdById = null,
        ?string $customerId = 'customer-1',
        ?string $employeeId = null,
        ?string $quoteLineItemId = null,
    ) {
        parent::__construct($comment, $createdById, $customerId, $employeeId);
        $this->quoteLineItemId = $quoteLineItemId;
    }
}
