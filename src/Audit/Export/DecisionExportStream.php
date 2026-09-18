<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The anonymized export itself, with no opinion about where the lines go.
 *
 * Extracted from DecisionExportCommand when the dashboard grew a download
 * button (#154 follow-up): two callers producing the same file must not be two
 * copies of the range filter and the allowlist call, because a fix to one
 * would leave the other exporting a different shape of the same records.
 *
 * The range is half-open, `$from` inclusive and `$to` exclusive, for the reason
 * the command's `--to` documents: a bare date parses to midnight, so an
 * inclusive end would quietly drop the last day.
 *
 * What leaves and what does not is AnonymizedDecision's five lists, and
 * docs/for-merchants.md describes it for the merchant.
 */
final readonly class DecisionExportStream
{
    public function __construct(
        private EntityRepository $decisions,
        private SystemConfigService $systemConfig,
    ) {}

    /**
     * One JSONL line per record, in creation order.
     *
     * The generator's RETURN value is the list of records it could not encode,
     * which the console caller reports to stderr. Yielding those as lines would
     * put prose into the JSONL stream, and swallowing them would let a record
     * vanish from a file a merchant believes is complete.
     *
     * @return \Generator<int, string, null, list<string>>
     *
     * @throws \Random\RandomException
     */
    public function lines(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool $freeText,
        ?Context $context = null,
    ): \Generator {
        $pseudonym = ExportPseudonym::forShop($this->systemConfig);
        $iterator = new RepositoryIterator(
            $this->decisions,
            $context ?? Context::createDefaultContext(),
            self::criteria($from, $to),
        );
        $skipped = [];

        while (($result = $iterator->fetch()) !== null) {
            foreach ($result->getEntities() as $record) {
                if (!$record instanceof QuoteDecisionRecord) {
                    continue;
                }

                // JSON_INVALID_UTF8_SUBSTITUTE swaps invalid bytes -- the only
                // realistic failure here, e.g. a provider error body stored in
                // errorChain under free text -- for U+FFFD rather than failing
                // the encode, so a merchant's record is kept instead of
                // silently dropped. That makes the `false` branch below
                // unreachable in practice; it stays as a guard so a blank line
                // can never enter the JSONL stream uncounted.
                $line = json_encode(
                    AnonymizedDecision::of($record, $pseudonym, $freeText),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                );

                if ($line === false) {
                    $skipped[] = \sprintf(
                        'Skipped one record (%s) that could not be JSON-encoded: %s',
                        $record->id,
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
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::GTE => $from->format(\DateTimeInterface::ATOM),
            RangeFilter::LT => $to->format(\DateTimeInterface::ATOM),
        ]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(500);

        return $criteria;
    }
}
