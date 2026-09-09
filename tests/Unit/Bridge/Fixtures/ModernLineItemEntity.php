<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures;

/**
 * The same quote line item, on a shop that has caught up with trunk (or
 * trunk itself): `deletedAt` and `requestedPrice` both exist and are
 * declared here, so `Entity::get()` returns them rather than throwing.
 *
 * `price` is not exposed as a constructor parameter here (see
 * `LegacyLineItemEntity`'s own docblock on why the parameter list stays
 * short); no test needs to vary it on the modern shape either.
 */
final class ModernLineItemEntity extends LegacyLineItemEntity
{
    protected ?\DateTimeImmutable $deletedAt;

    protected mixed $requestedPrice;

    public function __construct(
        string $id = 'line-1',
        int $quantity = 2,
        float $totalPrice = 20.0,
        ?\DateTimeImmutable $deletedAt = null,
        mixed $requestedPrice = null,
    ) {
        parent::__construct($id, $quantity, $totalPrice);
        $this->deletedAt = $deletedAt;
        $this->requestedPrice = $requestedPrice;
    }
}
