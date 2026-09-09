<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * An escalation belongs to the pass that raised it, and that is the newest
 * pass at the moment a human acts: QuoteServicingTrigger only ever queues the
 * NEXT pass onto Messenger rather than running it inline, so no newer record
 * can exist yet. Same selection as TerminalOutcomeWriter, for the same reason.
 *
 * Two guards, both here rather than only in the subscriber, because this is
 * the thing that touches the row: a newest pass that did not escalate has no
 * escalation to resolve, and a pass already carrying `resolvedAt` must keep
 * its first resolution — the measure is how long the deal desk took to answer,
 * so a later transition on the same quote must not reset the clock.
 */
final readonly class EscalationResolutionWriter implements EscalationResolutionWriterInterface
{
    private const ESCALATED = 'escalated';

    /** @param EntityRepository<covariant QuoteDecisionRecord> $records */
    public function __construct(
        private EntityRepository $records,
    ) {}

    #[\Override]
    public function recordEscalationResolution(string $quoteId, string $state, \DateTimeImmutable $at): void
    {
        // System scope, because every field carries
        // Protection(write: [Protection::SYSTEM_SCOPE]).
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $newest = $this->records->search($criteria, $context)->first();

        if (!$newest instanceof QuoteDecisionRecord) {
            return;
        }

        if ($newest->outcome !== self::ESCALATED || $newest->resolvedAt !== null) {
            return;
        }

        $this->records->update([[
            'id' => $newest->id,
            'resolvedAt' => $at,
            'resolvedState' => $state,
        ]], $context);
    }
}
