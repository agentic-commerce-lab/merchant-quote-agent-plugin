<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Strategy\StrategyVersion;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Event\NestedEventCollection;

/**
 * A real `merchant_quote_agent_strategy_version.repository` double that
 * actually filters by primary key, `strategyId` and `status` -- unlike the
 * ignore-the-Criteria doubles used elsewhere in this suite -- because a test
 * with TWO strategies in play needs StrategyResolver::resolve()/byVersionId()
 * and StrategyProposalWriter's own lookup to each find the RIGHT lineage, not
 * whichever version happens to be first in an unfiltered list.
 *
 * One instance serves all three roles (StrategyResolver's `versions`
 * dependency for both its reads, and StrategyProposalWriter's `versions`
 * dependency for its lookup-by-id and its `create()` writes), because all
 * three read and write the same table.
 */
final class VersionRepositorySpy extends EntityRepository
{
    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @param list<StrategyVersion> $versions */
    public function __construct(
        private readonly array $versions,
    ) {}

    #[\Override]
    public function search(Criteria $criteria, Context $context): EntitySearchResult
    {
        $ids = $criteria->getIds();
        $strategyId = null;
        $status = null;

        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter && $filter->getField() === 'strategyId') {
                $strategyId = $filter->getValue();
            }

            if ($filter instanceof EqualsFilter && $filter->getField() === 'status') {
                $status = $filter->getValue();
            }
        }

        $matched = array_values(array_filter($this->versions, static function (StrategyVersion $version) use (
            $ids,
            $strategyId,
            $status,
        ): bool {
            if ($ids !== [] && !\in_array($version->getUniqueIdentifier(), $ids, true)) {
                return false;
            }

            if ($strategyId !== null && $version->strategyId !== $strategyId) {
                return false;
            }

            return $status === null || $version->status === $status;
        }));

        return new EntitySearchResult(
            'test',
            \count($matched),
            new EntityCollection($matched),
            null,
            $criteria,
            $context,
        );
    }

    #[\Override]
    public function create(array $data, Context $context): EntityWrittenContainerEvent
    {
        array_push($this->created, ...$data);

        return new EntityWrittenContainerEvent($context, new NestedEventCollection([]), []);
    }
}
