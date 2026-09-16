<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;

/**
 * The export is an allowlist, so a column added to QuoteDecisionRecord cannot
 * leak into an exported file. It could, however, be forgotten -- and a
 * merchant promise of "this is what leaves" is only kept if every column has
 * had a decision made about it. This test makes that decision mandatory: a new
 * field fails the build until it is named in one of the five lists.
 *
 * It also pins the lists against the mapper itself, so the classification
 * table cannot drift into a description of what the code used to do.
 */
#[CoversClass(AnonymizedDecision::class)]
final class ExportFieldCoverageTest extends TestCase
{
    public function testEveryEntityFieldIsClassifiedExactlyOnce(): void
    {
        $classified = [
            ...array_keys(AnonymizedDecision::PSEUDONYMIZED),
            ...AnonymizedDecision::VERBATIM,
            ...AnonymizedDecision::RESHAPED,
            ...AnonymizedDecision::FREE_TEXT,
            ...AnonymizedDecision::DROPPED,
        ];

        self::assertSame(
            array_unique($classified),
            $classified,
            'A QuoteDecisionRecord field is classified twice by the export.',
        );
        self::assertSame(
            [],
            array_values(array_diff(self::entityFieldNames(), $classified)),
            'A QuoteDecisionRecord field has no export classification. Add it to one of'
            . ' AnonymizedDecision\'s five lists and to the mapper, and say what it means'
            . ' in docs/for-merchants.md.',
        );
        self::assertSame(
            [],
            array_values(array_diff($classified, self::entityFieldNames())),
            'The export classifies a field QuoteDecisionRecord does not have.',
        );
    }

    public function testTheMapperEmitsEveryFieldItClaimsToAndNothingElse(): void
    {
        $row = AnonymizedDecision::of(new QuoteDecisionRecord(), new ExportPseudonym('salt'), freeText: true);

        $expected = [
            ...array_values(AnonymizedDecision::PSEUDONYMIZED),
            ...AnonymizedDecision::VERBATIM,
            ...AnonymizedDecision::RESHAPED,
            ...AnonymizedDecision::FREE_TEXT,
            'createdAt',
        ];

        sort($expected);
        $actual = array_keys($row);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /** @return list<string> */
    private static function entityFieldNames(): array
    {
        $fields = array_filter(
            (new \ReflectionClass(QuoteDecisionRecord::class))->getProperties(),
            static fn(\ReflectionProperty $property): bool => $property->getAttributes(Field::class) !== [],
        );

        return array_values(array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            $fields,
        ));
    }
}
