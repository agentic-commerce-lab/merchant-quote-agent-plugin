<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Attempt;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Shopware's DAL hydrator initializes the id and field properties after
 * construction, so DAL entities intentionally do not define constructors.
 *
 * @mago-expect analysis:missing-constructor
 */
final class ServicingAttemptEntity extends Entity
{
    use EntityIdTrait;

    protected int $attemptCount;

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setAttemptCount(int $attemptCount): void
    {
        $this->attemptCount = $attemptCount;
    }
}
