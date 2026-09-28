<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/**
 * The merchant's feedback on one decision, mapped by valinor (types, and the
 * reason vocabulary) and checked here for what types cannot say.
 */
final readonly class FeedbackRequest
{
    public const MAX_COMMENT_LENGTH = 2000;

    /** @var list<FeedbackReason> */
    public array $reasons;

    public string $comment;

    /**
     * @param list<FeedbackReason> $reasons
     *
     * @throws InvalidReviewRequest
     */
    public function __construct(array $reasons = [], string $comment = '')
    {
        $comment = trim($comment);

        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw InvalidReviewRequest::because(InvalidReviewReason::CommentTooLong, sprintf(
                'The comment is longer than %d characters.',
                self::MAX_COMMENT_LENGTH,
            ));
        }

        $unique = [];

        foreach ($reasons as $reason) {
            $unique[$reason->value] = $reason;
        }

        if ($unique === [] && $comment === '') {
            throw InvalidReviewRequest::because(
                InvalidReviewReason::EmptyFeedback,
                'Pick at least one reason or write a comment.',
            );
        }

        $this->reasons = array_values($unique);
        $this->comment = $comment;
    }

    /** @return list<string> */
    public function reasonValues(): array
    {
        return array_map(static fn(FeedbackReason $reason): string => $reason->value, $this->reasons);
    }
}
