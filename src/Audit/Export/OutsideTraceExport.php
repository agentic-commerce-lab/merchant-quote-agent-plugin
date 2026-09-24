<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\TraceEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/** Streams the outside-pass half of the export after decision lines. */
final readonly class OutsideTraceExport
{
    public function __construct(
        private EntityRepository $traces,
        private Context $context,
        private ExportPseudonym $pseudonym,
    ) {}

    /** @return \Generator<int, string, null, list<string>> */
    public function lines(\DateTimeImmutable $from, \DateTimeImmutable $to, bool $freeText): \Generator
    {
        $events = new RepositoryIterator($this->traces, $this->context, self::criteria($from, $to));
        $skipped = [];

        while (($result = $events->fetch()) !== null) {
            foreach ($result->getEntities() as $event) {
                if (!$event instanceof TraceEvent) {
                    continue;
                }

                $line = json_encode(
                    AnonymizedOutsideTrace::of($event, $this->pseudonym, $freeText),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                );

                if ($line === false) {
                    $skipped[] = \sprintf(
                        'Skipped one event (%s) that could not be JSON-encoded: %s',
                        $this->pseudonym->of($event->id),
                        json_last_error_msg(),
                    );

                    continue;
                }

                yield $line;
            }
        }

        return $skipped;
    }

    private static function criteria(\DateTimeImmutable $from, \DateTimeImmutable $to): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('decisionId', null));
        $criteria->addFilter(new RangeFilter('occurredAt', [
            RangeFilter::GTE => $from->format(\DateTimeInterface::ATOM),
            RangeFilter::LT => $to->format(\DateTimeInterface::ATOM),
        ]));
        $criteria->addSorting(new FieldSorting('occurredAt', FieldSorting::ASCENDING));
        $criteria->setLimit(500);

        return $criteria;
    }
}
