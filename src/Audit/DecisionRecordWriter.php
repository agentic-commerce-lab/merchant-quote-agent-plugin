<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Persists one pass. Everything upstream of it — the recorder, the draft, the
 * stages — stays plain PHP and unit-testable without a kernel; that seam, not
 * exclusivity, is the invariant. TerminalOutcomeWriter is the namespace's other
 * DAL-facing class, and it only ever updates rows this one inserted.
 *
 * `startedAt` is the draft's own stopwatch, not a column: it is excluded
 * explicitly. The DAL does NOT reject an unknown payload key — it silently
 * drops it (`WriteCommandExtractor` iterates the entity definition's fields,
 * not the payload's keys) — so leaving it in would not fail loudly, it would
 * just never reach a column. DraftMirrorsEntityTest is what actually protects
 * against that class of mistake for every other field.
 */
final readonly class DecisionRecordWriter implements DecisionRecordWriterInterface
{
    private const NOT_A_COLUMN = ['startedAt'];

    public function __construct(
        private EntityRepository $records,
    ) {}

    #[\Override]
    public function write(DecisionDraft $draft): void
    {
        /** @var array<string, mixed> $payload */
        $payload = get_object_vars($draft);

        foreach (self::NOT_A_COLUMN as $field) {
            unset($payload[$field]);
        }

        $payload['id'] = Uuid::randomHex();

        $this->records->create([$payload], Context::createDefaultContext());
    }
}
