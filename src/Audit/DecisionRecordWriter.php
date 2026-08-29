<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The only class in Audit that touches Shopware. Everything upstream of it —
 * the recorder, the draft, the stages — stays plain PHP and unit-testable
 * without a kernel.
 *
 * `startedAt` is the draft's own stopwatch, not a column: it is excluded
 * explicitly, because handing the DAL an unknown field fails the whole write.
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
