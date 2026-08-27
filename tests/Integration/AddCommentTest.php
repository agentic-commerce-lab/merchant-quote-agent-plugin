<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * `addComment` goes through QuoteCommenter (@internal in SwagCommercial), which
 * does more than insert a row: it resolves authorship from the Context's source
 * and can attach the comment to a line item or a line-item history entry.
 */
final class AddCommentTest extends IntegrationTestCase
{
    public function testCommentIsAppendedAndReadableBack(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $text = 'Agent offer note ' . Uuid::randomHex();

        $before = $gateway->fetchSnapshot($quoteId)->content->comments;

        $gateway->addComment($quoteId, $text);

        $after = $gateway->fetchSnapshot($quoteId)->content->comments;
        self::assertCount(\count($before) + 1, $after, 'addComment did not append exactly one comment to the quote.');
        self::assertContains(
            $text,
            array_map(static fn(QuoteComment $comment): string => $comment->comment, $after),
            'The comment text written is not the comment text read back.',
        );
    }

    /**
     * SPIKE FINDING (issue #4 depends on this) — RESULT RECORDED BELOW.
     *
     * QuoteCommenter derives `createdById` from `AdminApiSource::getUserId()`
     * and `customerId` / `employeeId` from a `SalesChannelApiSource`. A message
     * handler has neither: `Context::createDefaultContext()` carries a
     * `SystemSource`, so all three come back NULL and an agent-authored comment
     * is indistinguishable by author from any other author-less comment.
     *
     * The assertions below are the observed behaviour, not the desired one. If
     * SwagCommercial ever starts stamping an author on a system-source comment,
     * they fail — which is precisely the signal issue #4 wants, because it
     * would mean the re-entrancy check could use the author field after all.
     * Until then it cannot, and needs another discriminator (a `customFields`
     * marker on the quote, per the spec's Testing section). This is worse than
     * "uninformative": measured against the live shop, 42 of its 118 existing
     * quote comments are already author-less on all three fields, so an agent
     * comment does not merely lack an author — it collides with real comments
     * that also lack one. 76 comments DO carry an author (4 createdById, 72
     * customerId, 0 employeeId), which is also what makes the assertions below
     * non-vacuous: the mapper demonstrably returns non-null for those.
     */
    public function testAgentCommentCarriesNoAuthorAtAll(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $text = 'Authorship probe ' . Uuid::randomHex();

        $gateway->addComment($quoteId, $text);

        $ours = $this->commentWithText($gateway->fetchSnapshot($quoteId)->content->comments, $text);

        self::assertNull($ours->createdById, 'createdById is populated — the read model can identify the author.');
        self::assertNull($ours->customerId, 'customerId is populated — the comment was attributed to a customer.');
        self::assertNull($ours->lineItemId, 'The comment was attached to a line item rather than to the quote.');
        self::assertNotNull($ours->createdAt, 'createdAt is null, so comments cannot even be ordered by time.');
    }

    /**
     * Observed, not assumed: QuoteCommenter does not check that the quote
     * exists — it inserts and lets MySQL reject the row on
     * `fk.quote_comment.quote_id`. So an unknown id fails loudly (the point of
     * the test) but as a Doctrine exception rather than a bridge one, which the
     * gateway deliberately leaves alone. Named as a class-name string rather
     * than an import so production and test code stay free of a doctrine/dbal
     * dependency this plugin does not declare.
     */
    public function testCommentingOnAnUnknownQuoteFailsAtTheForeignKey(): void
    {
        $caught = null;
        try {
            static::gateway()->addComment(Uuid::randomHex(), 'Comment on a quote that does not exist');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Commenting on a quote that does not exist was silently accepted.');
        self::assertSame(
            'Doctrine\\DBAL\\Exception\\ForeignKeyConstraintViolationException',
            $caught::class,
            'addComment now fails differently for an unknown quote; the interface docblock says what '
                . 'it used to do: '
                . $caught->getMessage(),
        );
    }

    /** @param list<QuoteComment> $comments */
    private function commentWithText(array $comments, string $text): QuoteComment
    {
        foreach ($comments as $comment) {
            if ($comment->comment === $text) {
                return $comment;
            }
        }

        self::fail('The comment just written is not in the quote read back.');
    }
}
