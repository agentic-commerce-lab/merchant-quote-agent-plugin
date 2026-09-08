<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * A quote comment shaped like a released SwagCommercial: no
 * `quote_line_item_id` at all — scoping a comment to one line is trunk-only.
 * `Entity::get()` throws `propertyNotFound` on it, exactly as the real DAL
 * entity would. `createdAt` is not declared here because the base `Entity`
 * class already declares it (it is not trunk-only), so `has('createdAt')`
 * is true regardless of the subclass.
 */
class LegacyQuoteCommentEntity extends Entity
{
    protected string $comment;

    protected ?string $createdById;

    protected ?string $customerId;

    protected ?string $employeeId;

    public function __construct(
        string $comment = 'Can you do better on price?',
        ?string $createdById = null,
        ?string $customerId = 'customer-1',
        ?string $employeeId = null,
    ) {
        $this->comment = $comment;
        $this->createdById = $createdById;
        $this->customerId = $customerId;
        $this->employeeId = $employeeId;
    }
}
