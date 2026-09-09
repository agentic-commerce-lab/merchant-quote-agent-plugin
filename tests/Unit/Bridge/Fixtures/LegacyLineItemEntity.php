<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * A quote line item shaped like a released SwagCommercial (6.7.1.2 through
 * 6.7.12.x): no `deleted_at` (soft delete is trunk-only) and no
 * `requested_price` (the buyer's per-line ask is trunk-only). Because this
 * extends the real `Entity` base class and simply never declares those two
 * properties, `Entity::get()` throws `propertyNotFound` on either — exactly
 * as a released shop's generated DAL entity would. An `ArrayEntity` cannot
 * stand in for this: its `get()`/`has()` are array lookups that never throw,
 * so a test built on one would pass identically with the production guard
 * deleted.
 *
 * `label` and `referencedId` are fixed defaults rather than constructor
 * parameters: no test varies them, and folding them in would push the
 * constructor over the project's `excessive-parameter-list` threshold.
 */
class LegacyLineItemEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $label = 'Widget';

    protected ?string $referencedId = 'product-1';

    protected int $quantity;

    protected float $totalPrice;

    protected mixed $price;

    public function __construct(string $id = 'line-1', int $quantity = 2, float $totalPrice = 20.0, mixed $price = null)
    {
        $this->setId($id);
        $this->quantity = $quantity;
        $this->totalPrice = $totalPrice;
        $this->price = $price;
    }
}
