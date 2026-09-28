<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use CuyZ\Valinor\Mapper\MappingError;
use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use MerchantQuoteAgentPlugin\Review\FeedbackRequest;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use PHPUnit\Framework\TestCase;

final class FeedbackRequestTest extends TestCase
{
    public function testReasonsAndCommentMapFromTheWire(): void
    {
        $request = ArrayMapper::mapObject(FeedbackRequest::class, [
            'reasons' => ['wrong_price', 'wrong_price', 'other'],
            'comment' => '  Key account, always 10%.  ',
        ]);

        self::assertSame(['wrong_price', 'other'], $request->reasonValues());
        self::assertSame('Key account, always 10%.', $request->comment);
    }

    public function testAnUnknownReasonIsRefused(): void
    {
        $this->expectException(MappingError::class);

        ArrayMapper::mapObject(FeedbackRequest::class, ['reasons' => ['vibes']]);
    }

    public function testEmptyFeedbackIsRefused(): void
    {
        $this->expectException(InvalidReviewRequest::class);

        ArrayMapper::mapObject(FeedbackRequest::class, ['reasons' => [], 'comment' => '   ']);
    }

    public function testACommentOverTheCapIsRefused(): void
    {
        $this->expectException(InvalidReviewRequest::class);

        ArrayMapper::mapObject(FeedbackRequest::class, ['comment' => str_repeat(
            'ä',
            FeedbackRequest::MAX_COMMENT_LENGTH + 1,
        )]);
    }

    public function testACommentAloneIsEnough(): void
    {
        self::assertSame(
            [],
            ArrayMapper::mapObject(FeedbackRequest::class, ['comment' => 'Too pushy.'])->reasonValues(),
        );
    }
}
