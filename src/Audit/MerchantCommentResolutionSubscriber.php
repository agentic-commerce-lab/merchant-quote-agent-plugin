<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A merchant answering an escalation in the quote's thread, rather than by
 * moving its state (QA-05).
 *
 * EscalationResolutionSubscriber sees only transitions, and from `replied`
 * SwagCommercial's own "send" makes none: it saves the quote, posts the
 * message and fires its admin-requested flow, all without touching the state
 * machine. Before this, a merchant's only way to close a Needs-review item
 * on a `replied` quote was withdraw and resend.
 *
 * Stamps `resolvedState` 'commented' through the same writer, so the same two
 * guards apply: only an escalated, still-unresolved newest pass is stamped,
 * and the first resolution keeps its clock. A send WITH a message from any
 * other state therefore records 'commented' too, because the comment is
 * written before the transition (both lanes' admin send) — the transition
 * then finds the pass already resolved. The time is the merchant's answer
 * either way, which is what the measure is about.
 *
 * Authorship is QuoteServicingTrigger::isMerchantComment(), the one payload
 * predicate both subscribers read: `createdById` set and neither buyer
 * column. The agent's own comments carry no author at all (#3) and are also
 * written under AgentContext::STATE; a buyer's carry a buyer column. A
 * snapshot-lane write is SwagCommercial mirroring a live one — the live one
 * is the event that counts — so only the live version is read, as
 * QuoteServicingTrigger does for the same reason.
 *
 * Only inserts: an edited comment is not a new answer.
 */
final readonly class MerchantCommentResolutionSubscriber implements EventSubscriberInterface
{
    /** What `resolvedState` records for an escalation answered in the thread. */
    public const COMMENTED = 'commented';

    public function __construct(
        private EscalationResolutionWriterInterface $writer,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return ['quote_comment.written' => 'onQuoteCommentWritten'];
    }

    public function onQuoteCommentWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();

        if ($context->getVersionId() !== Defaults::LIVE_VERSION || $context->hasState(AgentContext::STATE)) {
            return;
        }

        foreach ($event->getWriteResults() as $result) {
            $quoteId = $result->getPayload()['quoteId'] ?? null;

            if (
                $result->getOperation() !== EntityWriteResult::OPERATION_INSERT
                || !\is_string($quoteId)
                || !QuoteServicingTrigger::isMerchantComment($result->getPayload())
            ) {
                continue;
            }

            $this->record($quoteId);
        }
    }

    /**
     * EscalationResolutionSubscriber's two-layer guard, for its reason: a
     * merchant's comment must never fail on our account, and a throwing
     * logger must not fail it either.
     */
    private function record(string $quoteId): void
    {
        try {
            try {
                $this->writer->recordEscalationResolution($quoteId, self::COMMENTED, new \DateTimeImmutable());
            } catch (\Throwable $e) {
                $this->logger->error('The escalation resolution could not be recorded.', [
                    'quoteId' => $quoteId,
                    'resolvedState' => self::COMMENTED,
                    'exception' => $e,
                ]);
            }
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty: a logger that throws must not fail the
            // merchant's comment, and there is nowhere left to report the
            // failure.
        }
    }
}
