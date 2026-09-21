<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\MailAware;
use Shopware\Core\Framework\Event\NestedEvent;
use Shopware\Core\Framework\Event\SalesChannelAware;

/**
 * The Flow Builder trigger for "the agent handed this quote to a human".
 *
 * A business event rather than a mail template of ours: the merchant decides
 * in the Administration whether that means an email, a Slack webhook, a tag or
 * a task, and to whom. Shipping a template would have picked one channel and
 * one recipient for every shop, and owned their translations forever. (The
 * plugin does ship a disabled-by-default flow wired to this event — see
 * Migration1789500001SeedEscalationMailAndFlow — but a merchant who wants
 * something else entirely is free to build their own from this same trigger.)
 *
 * `getName()` must not read constructor state: BusinessEventCollector::define()
 * builds this class with `newInstanceWithoutConstructor()` to inspect it, so
 * the name has to be the constant below rather than anything derived.
 *
 * SalesChannelAware is what lets a merchant scope a flow to one channel.
 * CustomerAware would be a lie — the quote's buyer is not on the snapshot —
 * but MailAware is not: `SendMailAction::handleFlow()` throws
 * `MailEventConfigurationException` unless `MailStorer` finds this interface
 * and populates `mailStruct`/`salesChannelId`, and that guard fires
 * regardless of the flow's chosen recipient type. Even the "admin" recipient
 * (every `user` row with `admin = true`, which is what the shipped flow uses,
 * since an escalation has no buyer address to send to) needs this interface
 * satisfied to get past it — `getMailStruct()` below is never actually read
 * for that recipient type, only the fact that it did not throw is. Confirmed
 * by reading MailStorer/SendMailAction, not assumed: before this the event
 * could not deliver mail through Flow Builder at all, default or hand-built.
 *
 * ScalarValuesAware is the other half of the same gap: `getAvailableData()`
 * below only feeds the Administration's rule-builder documentation panel — it
 * is never consulted when a flow actually runs. Without also implementing
 * this interface, `quoteNumber`/`escalationReason` were listed as available
 * but `{{ quoteNumber }}` in a mail body always rendered empty, because
 * `ScalarValuesStorer` (the thing that actually copies values into
 * `$flow->data()`) skips any event that is not `ScalarValuesAware`.
 */
final class QuoteAgentEscalatedEvent extends NestedEvent implements
    FlowEventAware,
    SalesChannelAware,
    MailAware,
    ScalarValuesAware
{
    public const EVENT_NAME = 'merchant_quote_agent.quote.escalated';

    public function __construct(
        private readonly EscalationNotice $notice,
        private readonly Context $context,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return self::EVENT_NAME;
    }

    #[\Override]
    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())->add('quoteId', new ScalarValueType(ScalarValueType::TYPE_STRING))->add(
            'quoteNumber',
            new ScalarValueType(ScalarValueType::TYPE_STRING),
        )->add('escalationReason', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    #[\Override]
    public function getContext(): Context
    {
        return $this->context;
    }

    #[\Override]
    public function getSalesChannelId(): string
    {
        return $this->notice->salesChannelId;
    }

    public function getQuoteId(): string
    {
        return $this->notice->quoteId;
    }

    public function getQuoteNumber(): string
    {
        return $this->notice->quoteNumber;
    }

    /**
     * The reason as its wire value, not the enum: a flow's mail template or
     * webhook body renders this, and Twig cannot read a PHP enum case.
     */
    public function getEscalationReason(): string
    {
        return $this->notice->reason->value;
    }

    /**
     * Empty on purpose: an escalation has no buyer address to mail, only a
     * merchant to reach, so nothing here ever supplies a real recipient. This
     * exists solely to satisfy `MailStorer`'s `instanceof MailAware` check —
     * see the class docblock. A flow using the "custom" or "default"
     * recipient type would get no one; the shipped flow uses "admin", which
     * ignores this and queries `user` directly.
     */
    #[\Override]
    public function getMailStruct(): MailRecipientStruct
    {
        return new MailRecipientStruct([]);
    }

    /**
     * What actually reaches a flow's rule conditions and mail/Twig context at
     * runtime — see the class docblock. Kept in step with getAvailableData()
     * by QuoteAgentEscalatedEventTest.
     *
     * @return array<string, scalar|array<mixed>|null>
     */
    #[\Override]
    public function getValues(): array
    {
        return [
            'quoteId' => $this->notice->quoteId,
            'quoteNumber' => $this->notice->quoteNumber,
            'escalationReason' => $this->notice->reason->value,
        ];
    }
}
