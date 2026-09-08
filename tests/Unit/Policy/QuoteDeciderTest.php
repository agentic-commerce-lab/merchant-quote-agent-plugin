<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecisionKind;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\QuoteDecider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteDeciderTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testMatchesTsFixture(string $description, array $input, array $expected): void
    {
        $snapshot = QuoteSnapshot::fromArray($input['snapshot']);
        $limits = QuoteLimits::fromArray($input['limits']);
        $interpretation = $input['interpretation'] !== null
            ? CommentInterpretation::fromArray($input['interpretation'])
            : null;

        $decision = (new QuoteDecider())->decide($snapshot, $limits, $interpretation);

        self::assertSame($expected['kind'], $decision->kind->value, $description);

        if ($decision->kind === QuoteDecisionKind::AutoReply) {
            $details = $decision->autoReply;
            self::assertEqualsWithDelta($expected['discountPercent'], $details->discountPercent, 0.01, $description);
            self::assertSame($expected['perLineAsks'], $details->perLineAsks, $description);
            self::assertSame($expected['validityDays'], $details->validityDays, $description);
            foreach ($expected['lineUnitPricesNet'] as $index => $line) {
                self::assertSame($line['lineItemId'], $details->lineUnitPricesNet[$index]->lineItemId, $description);
                self::assertEqualsWithDelta(
                    $line['unitPriceNet'],
                    $details->lineUnitPricesNet[$index]->unitPriceNet,
                    0.01,
                    $description,
                );
            }
        } else {
            $details = $decision->escalation;
            self::assertSame($expected['reason'], $details->reason->value, $description);
            if (isset($expected['requestedDiscountPercent'])) {
                self::assertEqualsWithDelta(
                    $expected['requestedDiscountPercent'],
                    $details->requestedDiscountPercent,
                    0.01,
                    $description,
                );
            }
            if (isset($expected['humanReviewRequests'])) {
                self::assertSame($expected['humanReviewRequests'], $details->humanReviewRequests, $description);
            }
        }
    }

    public static function fixtures(): iterable
    {
        yield from self::readFixtureFile('quote-decision.json');
    }

    private static function readFixtureFile(string $filename): iterable
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/Policy/' . $filename),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($cases as $case) {
            yield $case['description'] => [$case['description'], $case['input'], $case['expected']];
        }
    }
}
