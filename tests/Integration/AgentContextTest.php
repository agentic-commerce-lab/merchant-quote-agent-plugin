<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Pins two measured facts about the real write path, not the one the brief
 * originally worried about.
 *
 * The suspected risk — that QuoteCommenter's `$context->scope(Context::CRUD_API_SCOPE, ...)`
 * strips a state added outside any scope — does NOT happen; STATE survives it.
 *
 * The real risk is elsewhere: an agent comment write fires TWO
 * `quote_comment.written` events, not one. SwagCommercial's
 * QuoteHistoryWriter::trackComment() mirrors every Live-version comment into
 * the quote's snapshot version via createSnapshotQuoteComments(), which
 * derives its Context with `$context->createWithVersionId(...)`. That method
 * (Shopware\Core\Framework\Context::createWithVersionId()) builds a fresh
 * Context and carries over `scope` and `extensions` but never `states`, so
 * the mirrored event's Context has silently lost STATE. Task 9's trigger
 * therefore also filters by version — Defaults::LIVE_VERSION only — rather
 * than relying on the stamp alone; this test pins the fact that decision
 * depends on.
 */
final class AgentContextTest extends IntegrationTestCase
{
    public function testAnAgentCommentWriteStampsTheLiveEventOnlyAndMirrorsUnstampedToSnapshot(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        /** @var list<array{versionId: string, hasState: bool}> $observed */
        $observed = [];
        $listener = static function (EntityWrittenEvent $event) use (&$observed): void {
            $observed[] = [
                'versionId' => $event->getContext()->getVersionId(),
                'hasState' => $event->getContext()->hasState(AgentContext::STATE),
            ];
        };

        $dispatcher->addListener('quote_comment.written', $listener);

        try {
            static::gateway()->addComment($quoteId, 'AgentContextTest probe');
        } finally {
            $dispatcher->removeListener('quote_comment.written', $listener);
        }

        self::assertCount(
            2,
            $observed,
            'Expected exactly two quote_comment.written events for one addComment() call: the '
            . 'Live-version insert and QuoteHistoryWriter\'s snapshot mirror. A different count means '
            . 'this pinned mirroring behaviour has changed and the trigger\'s version filter needs '
            . 're-checking.',
        );

        $live = array_values(array_filter(
            $observed,
            static fn(array $o): bool => $o['versionId'] === Defaults::LIVE_VERSION,
        ));
        $snapshot = array_values(array_filter(
            $observed,
            static fn(array $o): bool => $o['versionId'] === QuoteVersionResolver::SNAPSHOT_VERSION_ID,
        ));

        self::assertCount(1, $live, 'No Live-version quote_comment.written event observed.');
        self::assertTrue(
            $live[0]['hasState'],
            'The Live-version event lost AgentContext::STATE. The trigger\'s own-write suppression '
            . 'depends on this.',
        );

        self::assertCount(1, $snapshot, 'No snapshot-version quote_comment.written event observed.');
        self::assertFalse(
            $snapshot[0]['hasState'],
            'The snapshot-mirror event now carries AgentContext::STATE. Context::createWithVersionId() '
            . 'apparently started preserving states — the trigger\'s version filter is still correct '
            . 'but is no longer load-bearing; update this assertion to document the new behaviour.',
        );
    }

    public function testAPlainDefaultContextDoesNotCarryTheState(): void
    {
        self::assertFalse(Context::createDefaultContext()->hasState(AgentContext::STATE));
        self::assertTrue(AgentContext::create()->hasState(AgentContext::STATE));
    }
}
