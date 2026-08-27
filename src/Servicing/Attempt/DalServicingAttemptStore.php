<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Attempt;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final readonly class DalServicingAttemptStore implements ServicingAttemptStoreInterface
{
    /** @param EntityRepository<ServicingAttemptCollection> $repository */
    public function __construct(
        private EntityRepository $repository,
    ) {}

    #[\Override]
    public function recordDelivery(string $messageId): int
    {
        $context = Context::createDefaultContext();
        $entity = $this->repository
            ->search(new Criteria([$messageId]), $context)
            ->getEntities()
            ->get($messageId);
        $attemptCount = ($entity?->getAttemptCount() ?? 0) + 1;

        $this->repository->upsert([
            [
                'id' => $messageId,
                'attemptCount' => $attemptCount,
            ],
        ], $context);

        return $attemptCount;
    }

    #[\Override]
    public function completeDelivery(string $messageId): void
    {
        $this->repository->delete([['id' => $messageId]], Context::createDefaultContext());
    }
}
