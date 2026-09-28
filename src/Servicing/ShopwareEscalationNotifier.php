<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Delivers an escalation to the merchant two ways, because they answer
 * different questions.
 *
 * The Flow Builder event is the one that reaches somebody not looking at the
 * Administration — the merchant wires it to mail, a webhook or a task. The
 * notification is what makes an escalation visible to whoever IS looking, with
 * no setup at all.
 *
 * The notification carries no user: an escalation runs in a Messenger worker,
 * so there is no admin session to attribute it to and `createdByUserId` stays
 * null. It is a shop-wide notice gated on privilege rather than a per-user
 * inbox, which is why the flow event exists alongside it rather than instead
 * of it.
 *
 * Neither call may throw past this class. An escalation's first duty is
 * telling the buyer and stamping the marker; a dead mail transport or a failed
 * DAL write must not cost the buyer their answer or re-run the pass. Each
 * channel is caught separately so one failing still lets the other through —
 * the pattern TerminalOutcomeSubscriber established for the same reason.
 */
final readonly class ShopwareEscalationNotifier implements EscalationNotifierInterface
{
    /**
     * Only users who can act on a quote should see it. This is the privilege
     * the plugin's own `merchant_quote_agent.viewer` ACL role grants (see the
     * Administration module's acl/index.ts), so the notice follows the same
     * permission that gates the agent's decision list.
     */
    private const REQUIRED_PRIVILEGES = ['merchant_quote_agent_decision:read'];

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private NotificationService $notifications,
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function notify(EscalationNotice $notice): void
    {
        $context = Context::createDefaultContext();

        $this->attempt('flow event', $notice, fn(): object => $this->dispatcher->dispatch(
            new QuoteAgentEscalatedEvent($notice, $context),
            QuoteAgentEscalatedEvent::EVENT_NAME,
        ));

        $this->attempt('administration notification', $notice, function () use ($notice, $context): void {
            $this->notifications->createNotification(
                [
                    // The id is the caller's to supply here, not the DAL's.
                    'id' => Uuid::randomHex(),
                    'status' => 'warning',
                    'message' => self::message($notice),
                    'adminOnly' => true,
                    'requiredPrivileges' => self::REQUIRED_PRIVILEGES,
                ],
                $context,
            );
        });
    }

    /**
     * Merchant-facing copy, so the reason belongs in it — the buyer's own
     * comment says only that a human will be in touch.
     */
    private static function message(EscalationNotice $notice): string
    {
        if ($notice->reason === QuoteEscalationReason::DraftReady) {
            return sprintf('Quote %s has a draft from the quote agent waiting for your review.', $notice->quoteNumber);
        }

        return sprintf(
            'Quote %s needs a human: the quote agent escalated it (%s).',
            $notice->quoteNumber,
            $notice->reason->value,
        );
    }

    private function attempt(string $channel, EscalationNotice $notice, callable $deliver): void
    {
        try {
            $deliver();
        } catch (\Throwable $e) {
            $this->logger->error('The merchant could not be notified that a quote escalated.', [
                'channel' => $channel,
                'quoteId' => $notice->quoteId,
                'quoteNumber' => $notice->quoteNumber,
                'escalationReason' => $notice->reason->value,
                'exception' => $e,
            ]);
        }
    }
}
