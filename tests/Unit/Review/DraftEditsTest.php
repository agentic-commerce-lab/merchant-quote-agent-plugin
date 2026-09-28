<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use PHPUnit\Framework\TestCase;

final class DraftEditsTest extends TestCase
{
    public function testAnEmptyBodyIsNoEdit(): void
    {
        self::assertTrue(ArrayMapper::mapObject(DraftEdits::class, ['reply' => 'x'])->isEmpty());
    }

    public function testEditsMapFromTheWire(): void
    {
        $day = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $edits = ArrayMapper::mapObject(DraftEdits::class, [
            'discountPercent' => 8.0,
            'linePrices' => ['line-1' => 9.5],
            'expiresAt' => $day,
        ]);

        self::assertSame(8.0, $edits->discountPercent);
        self::assertSame(['line-1' => 9.5], $edits->linePrices);
        self::assertSame($day . ' 23:59:59', $edits->expiresAt?->format('Y-m-d H:i:s'));
        self::assertFalse($edits->isEmpty());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refused(): iterable
    {
        yield 'discount above 100' => [['discountPercent' => 120.0]];
        yield 'negative discount' => [['discountPercent' => -1.0]];
        yield 'negative unit price' => [['linePrices' => ['line-1' => -0.01]]];
        yield 'not a day' => [['expiresAt' => '23.09.2026']];
        yield 'a day in the past' => [['expiresAt' => '2020-01-01']];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testOutOfRangeEditsAreRefused(array $body): void
    {
        $this->expectException(InvalidReviewRequest::class);

        ArrayMapper::mapObject(DraftEdits::class, $body);
    }
}
