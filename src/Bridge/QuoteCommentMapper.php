<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/** Maps a quote's `comments` association onto the bridge's read model. */
final class QuoteCommentMapper
{
    /** @return list<QuoteComment> */
    public function map(Entity $quote): array
    {
        $comments = $quote->get('comments');
        $result = [];

        if (!is_iterable($comments)) {
            return $result;
        }

        foreach ($comments as $comment) {
            if (!$comment instanceof Entity) {
                continue;
            }

            $createdAt = $comment->get('createdAt');
            $result[] = new QuoteComment(
                comment: (string) $comment->get('comment'),
                lineItemId: $this->nullableString($comment->get('quoteLineItemId')),
                createdById: $this->nullableString($comment->get('createdById')),
                customerId: $this->nullableString($comment->get('customerId')),
                createdAt: $createdAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($createdAt)
                    : null,
                employeeId: $this->nullableString($comment->get('employeeId')),
            );
        }

        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
