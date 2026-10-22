<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * A lineage id to the prompt a negotiation should actually send.
 *
 * Two reads, not one: the strategy, then its highest version. A single query
 * would need a DAL association, and the association attributes sit outside
 * what CoreFloorCompatibilityTest checks -- which is skipped entirely without
 * a local core clone, so CI would not catch a floor breakage there. Two plain
 * reads also let the refusal say WHICH of missing and archived happened, which
 * matters because the merchant reads that message in a log line.
 *
 * Uncached. It runs once per quote serviced, alongside the quote snapshot,
 * customer history and order history reads already on that path.
 *
 * Concrete, with no interface: there is one implementation, and a class is
 * just as good a seam for the day assignment stops being per-sales-channel.
 *
 * Deliberately not `final`, unlike almost everything else in this codebase:
 * QuoteAgentSettingsReaderTest mocks it, and PHPUnit cannot double a final
 * class. An interface is not the alternative here -- see above.
 */
readonly class StrategyResolver
{
    public function __construct(
        private EntityRepository $strategies,
        private EntityRepository $versions,
    ) {}

    /** @throws UnknownStrategy */
    public function resolve(string $strategyId, Context $context): ResolvedStrategy
    {
        $strategy = $this->strategies->search(new Criteria([$strategyId]), $context)->first();

        if (!$strategy instanceof Strategy) {
            throw UnknownStrategy::missing($strategyId);
        }

        if ($strategy->archivedAt !== null) {
            throw UnknownStrategy::archived($strategyId);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('strategyId', $strategyId));
        // The enforcement that a nightly proposal never answers a buyer. A
        // proposed row is a real row with a real prompt in the real table;
        // this filter is why the negotiation path structurally cannot reach
        // it. Not a UI rule and not a convention -- this is the only code
        // path that turns a strategy id into a prompt.
        $criteria->addFilter(new EqualsFilter('status', VersionStatus::Active->value));
        $criteria->addSorting(new FieldSorting('version', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $version = $this->versions->search($criteria, $context)->first();

        if (!$version instanceof StrategyVersion) {
            throw UnknownStrategy::withoutVersion($strategyId);
        }

        return new ResolvedStrategy($version->id, $version->prompt);
    }
}
