<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;

/**
 * The DAL does not reject an unknown payload key — it silently drops it (see
 * DecisionRecordWriter's docblock). So a drifted property name would not fail
 * loudly at write time; it would just leave a column permanently empty. This
 * is the test that has to catch that, structurally, before any write happens.
 */
final class DraftMirrorsEntityTest extends TestCase
{
    /** Written by DecisionRecordWriter itself, never a draft property. */
    private const ID_IS_WRITER_GENERATED = 'id';

    /** Reserved for the terminal-state follow-up subscriber, not this issue. */
    private const RESERVED_TERMINAL_FIELDS = ['terminalState', 'terminalAt'];

    /** The draft's own stopwatch; DecisionRecordWriter excludes it, not a column. */
    private const DRAFT_ONLY_WORKING_FIELDS = ['startedAt'];

    public function testEveryDraftPropertyIsAnEntityFieldOrExplicitlyExcluded(): void
    {
        $excluded = [...self::DRAFT_ONLY_WORKING_FIELDS];

        self::assertEmpty(
            array_diff($this->draftPropertyNames(), $this->entityFieldNames(), $excluded),
            'A DecisionDraft property does not match any QuoteDecisionRecord field.',
        );
    }

    public function testEveryEntityFieldIsADraftPropertyOrExplicitlyReserved(): void
    {
        $excluded = [self::ID_IS_WRITER_GENERATED, ...self::RESERVED_TERMINAL_FIELDS];

        self::assertEmpty(
            array_diff($this->entityFieldNames(), $this->draftPropertyNames(), $excluded),
            'A QuoteDecisionRecord field has no corresponding DecisionDraft property.',
        );
    }

    /** @return list<string> */
    private function draftPropertyNames(): array
    {
        return array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(DecisionDraft::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
    }

    /** @return list<string> */
    private function entityFieldNames(): array
    {
        $fields = array_filter(
            (new \ReflectionClass(QuoteDecisionRecord::class))->getProperties(),
            static fn(\ReflectionProperty $property): bool => $property->getAttributes(Field::class) !== [],
        );

        return array_map(static fn(\ReflectionProperty $property): string => $property->getName(), $fields);
    }
}
