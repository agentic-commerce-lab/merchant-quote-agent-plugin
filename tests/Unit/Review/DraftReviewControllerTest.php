<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Review\DraftPreviewer;
use MerchantQuoteAgentPlugin\Review\DraftRejecter;
use MerchantQuoteAgentPlugin\Review\DraftReviewController;
use MerchantQuoteAgentPlugin\Review\DraftSender;
use MerchantQuoteAgentPlugin\Review\FeedbackRequest;
use MerchantQuoteAgentPlugin\Review\PendingDrafts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The 400 contract the review card reads: a machine `reason` beside the
 * merchant-facing message, so the card can say what to fix instead of "Try
 * again". Driven through the feedback route because it is the one route that
 * needs nothing but the store.
 */
final class DraftReviewControllerTest extends TestCase
{
    #[DataProvider('fixableBodies')]
    public function testAFixableRequestAnswers400WithItsReason(string $body, string $reason): void
    {
        $response = self::controller()->feedback('rec-1', self::put($body));

        self::assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('invalid', $payload['code'] ?? null);
        self::assertSame($reason, $payload['reason'] ?? null);
        self::assertIsString($payload['message'] ?? null);
    }

    /** @return iterable<string, array{string, string}> */
    public static function fixableBodies(): iterable
    {
        yield 'nothing to save' => ['{}', 'empty_feedback'];
        yield 'comment too long' => [
            json_encode(['comment' => str_repeat(
                'x',
                times: FeedbackRequest::MAX_COMMENT_LENGTH + 1,
            )], JSON_THROW_ON_ERROR),
            'comment_too_long',
        ];
        yield 'wrong shape' => ['{"reasons": "wrong_price"}', 'malformed'];
        yield 'not JSON' => ['{', 'malformed'];
    }

    private static function put(string $body): Request
    {
        return Request::create('/feedback', 'PUT', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    }

    /** Collaborators the feedback route never reaches are built without their constructors. */
    private static function controller(): DraftReviewController
    {
        return new DraftReviewController(
            (new \ReflectionClass(PendingDrafts::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(DraftPreviewer::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(DraftSender::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(DraftRejecter::class))->newInstanceWithoutConstructor(),
            new FakeReviewStore(),
        );
    }
}
