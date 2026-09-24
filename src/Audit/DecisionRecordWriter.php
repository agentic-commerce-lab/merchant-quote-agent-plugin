<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Persists one pass. Everything upstream of it — the recorder, the draft, the
 * stages — stays plain PHP and unit-testable without a kernel; that seam, not
 * exclusivity, is the invariant. TerminalOutcomeWriter is the namespace's other
 * DAL-facing class, and it only ever updates rows this one inserted.
 *
 * `startedAt` (the draft's stopwatch) and `trace` (its buffered events,
 * written to their own table below) are not columns, so both are excluded
 * explicitly. The DAL does NOT reject an unknown payload key — it silently
 * drops it (`WriteCommandExtractor` iterates the entity definition's fields,
 * not the payload's keys) — so leaving it in would not fail loudly, it would
 * just never reach a column. DraftMirrorsEntityTest is what actually protects
 * against that class of mistake for every other field.
 */
final readonly class DecisionRecordWriter implements DecisionRecordWriterInterface
{
    private const NOT_A_COLUMN = ['startedAt', 'trace'];

    public function __construct(
        private EntityRepository $records,
        private EntityRepository $traces,
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function write(DecisionDraft $draft): void
    {
        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($draft);

        foreach (self::NOT_A_COLUMN as $field) {
            unset($payload[$field]);
        }

        $context = Context::createDefaultContext();
        $this->records->create([$payload], $context);

        if ($draft->trace === []) {
            return;
        }

        // After the decision row, and never able to take it back: a trace
        // that could not be written is a loss of detail, a decision that
        // could not be written is a loss of the record. Logged at error
        // because a missing trace is otherwise invisible until someone
        // exports that day and wonders why the prompts are gone.
        try {
            $this->traces->create(array_map(static fn(TraceDraft $event): array => $event->payload(
                $draft,
            ), $draft->trace), $context);
        } catch (\Throwable $e) {
            $this->logger->error('The pass trace could not be recorded; the decision record stands.', [
                'decisionId' => $draft->id,
                'quoteId' => $draft->quoteId,
                'exception' => $e,
            ]);
        }
    }
}
