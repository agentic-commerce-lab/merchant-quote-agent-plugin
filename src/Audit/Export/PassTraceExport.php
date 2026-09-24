<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Audit\TraceEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/** Loads one decision's trace, keeping a whole page's prompts out of memory. */
final class PassTraceExport
{
    private function __construct() {}

    /** @return list<array<string, mixed>> */
    public static function of(
        QuoteDecisionRecord $record,
        EntityRepository $traces,
        ExportPseudonym $pseudonym,
        Context $context,
        bool $freeText,
    ): array {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('decisionId', $record->id));
        $criteria->addSorting(new FieldSorting('position', FieldSorting::ASCENDING));

        $pseudonyms = $freeText
            ? $pseudonym->map([
                $record->id,
                $record->quoteId,
                $record->customerId,
                $record->salesChannelId,
                $record->revisionVersionId,
                $record->strategyVersionId,
            ])
            : [];
        $trace = [];

        foreach ($traces->search($criteria, $context)->getEntities() as $event) {
            if ($event instanceof TraceEvent) {
                $trace[] = AnonymizedTrace::of($event, $freeText, $pseudonyms);
            }
        }

        return $trace;
    }
}
