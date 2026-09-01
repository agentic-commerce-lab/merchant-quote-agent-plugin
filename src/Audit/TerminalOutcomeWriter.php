<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * A quote accumulates one record per servicing pass, and the terminal outcome
 * belongs to the last one. `reopen` is not in QuoteServicingTrigger's trigger
 * states, so a reopened quote is never serviced again and never produces a
 * newer record — whichever record is last stays last, and a plain `update()`
 * gives "the last terminal transition wins" for free.
 *
 * `createdAt` is queryable although QuoteDecisionRecord never declares it:
 * EntityDefinition::defaultFields() adds CreatedAtField and UpdatedAtField with
 * ApiAware() to every definition. The `id` sorting is a deterministic tiebreak
 * — created_at is DATETIME(3) and a pass takes seconds, so a tie is not
 * expected, but an arbitrary-but-stable order beats an undefined one.
 */
final readonly class TerminalOutcomeWriter implements TerminalOutcomeWriterInterface
{
    public function __construct(
        private EntityRepository $records,
    ) {}

    #[\Override]
    public function recordTerminalOutcome(string $quoteId, string $state, \DateTimeImmutable $at): void
    {
        // System scope, because every field carries
        // Protection(write: [Protection::SYSTEM_SCOPE]).
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $id = $this->records->searchIds($criteria, $context)->firstId();

        if ($id === null) {
            return;
        }

        $this->records->update([[
            'id' => $id,
            'terminalState' => $state,
            'terminalAt' => $at,
        ]], $context);
    }
}
