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
 * belongs to the last one. That holds independently of which states re-trigger
 * servicing — including a buyer's `request_change` into `reopen`, which
 * QuoteServicingTrigger does service, unlike a merchant's un-declining
 * `reopen`: a terminal transition can only ever stamp a record that already
 * exists, and QuoteServicingTrigger only ever queues the NEXT pass onto
 * Messenger rather than running it inline, so that pass's record cannot exist
 * yet at the moment of a terminal transition that precedes it. Whichever
 * record is newest at that moment is therefore, by construction, the one the
 * concluding pass produced, and a plain `update()` picking "whichever record
 * is last" gives "the last terminal transition wins" for free — regardless of
 * how many passes a quote went through or which state names triggered them.
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
