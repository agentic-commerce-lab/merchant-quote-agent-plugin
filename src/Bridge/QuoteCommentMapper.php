<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/** Maps a quote's `comments` association onto the bridge's read model. */
final readonly class QuoteCommentMapper
{
    public function __construct(
        private CommercialCapabilities $capabilities,
    ) {}

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
                // A released SwagCommercial has no `quote_comment.quote_line_item_id`
                // column at all, so the scope is simply unknowable there — never
                // null, always dropped to null rather than read (Entity::get()
                // would throw propertyNotFound on the missing column). The comment
                // TEXT itself always survives regardless: on that shop a comment is
                // the buyer's only ask channel at all, with no per-line
                // `requestedPrice` to fall back to, so losing the text here would
                // lose the ask entirely rather than just its line scope.
                lineItemId: $this->capabilities->lineScopedComments
                    ? $this->nullableString($comment->get('quoteLineItemId'))
                    : null,
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

    /**
     * When the administration last wrote on this quote, or null if it never
     * did — the value QuoteSnapshotReader puts on QuoteLifecycle for
     * Servicing\PendingEscalation (QA-05).
     *
     * No capability gate, unlike `lineItemId`: `created_by_id` is in the
     * table since SwagCommercial's first quote_comment migration, and both
     * lanes fill it for an admin comment. Trunk's QuoteCommenter passes it
     * explicitly; 6.7.12's leaves it to CreatedByField, whose default write
     * scope is the SYSTEM_SCOPE that commenter wraps every admin write in.
     *
     * @param list<QuoteComment> $comments
     */
    public static function newestMerchantAt(array $comments): ?\DateTimeImmutable
    {
        $times = array_filter(array_map(
            static fn(QuoteComment $comment): ?\DateTimeImmutable => $comment->isMerchantAuthored()
                ? $comment->createdAt
                : null,
            $comments,
        ));

        return $times === [] ? null : max($times);
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
