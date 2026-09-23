<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
     * The generator's RETURN value is what the caller has to say about the
     * file rather than about a record in it: the records it could not encode,
     * and whether a filter narrowed what it holds. The console caller reports
     * both to stderr. Yielding them as lines would put prose into the JSONL
     * stream, and swallowing them would let a record vanish from a file a
     * merchant believes is complete — or let a filtered file pass for a whole
     * one.
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
        string $outcome = '',
    ): \Generator {
        $pseudonym = ExportPseudonym::forShop($this->systemConfig);
        $iterator = new RepositoryIterator(
            $this->decisions,
            $context ?? Context::createDefaultContext(),
            self::criteria($from, $to, $outcome),
        );
        // A filtered file looks exactly like an unfiltered one, and a
        // merchant who does not know a filter applied reads "0 records" as
        // "the agent did nothing" rather than "no pass ended that way". Same
        // stderr channel as the encoding notices below, and for the same
        // reason: it describes the file, not a record in it.
        $skipped = $outcome === '' ? [] : [\sprintf('Only records whose outcome is "%s" were exported.', $outcome)];

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

    private static function criteria(\DateTimeImmutable $from, \DateTimeImmutable $to, string $outcome): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::GTE => $from->format(\DateTimeInterface::ATOM),
            RangeFilter::LT => $to->format(\DateTimeInterface::ATOM),
        ]));

        // One outcome at a time, because the question this answers is about
        // one: a comment the agent reads as empty is `acknowledged` — the
        // quote restated and sent back to `replied` — while a pass with no
        // comment read, or on an escalated quote, is a silent `nothing_to_do`.
        // Settling whether a comment was passed over rightly means reading
        // both outcomes' rows and their buyer comments — which is this
        // export, filtered, and was a row-by-row hunt through every outcome
        // before. Unvalidated on purpose: an unknown value returns nothing,
        // which is a truthful answer, and a list of outcomes here would be a
        // second copy of NegotiationOutcome to keep in step.
        if ($outcome !== '') {
            $criteria->addFilter(new EqualsFilter('outcome', $outcome));
        }

        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(500);

        return $criteria;
    }
}
