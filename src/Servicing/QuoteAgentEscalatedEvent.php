<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\NestedEvent;
use Shopware\Core\Framework\Event\SalesChannelAware;

/**
 * The Flow Builder trigger for "the agent handed this quote to a human".
 *
 * A business event rather than a mail template of ours: the merchant decides
 * in the Administration whether that means an email, a Slack webhook, a tag or
 * a task, and to whom. Shipping a template would have picked one channel and
 * one recipient for every shop, and owned their translations forever.
 *
 * `getName()` must not read constructor state: BusinessEventCollector::define()
 * builds this class with `newInstanceWithoutConstructor()` to inspect it, so
 * the name has to be the constant below rather than anything derived.
 *
 * SalesChannelAware is what lets a merchant scope a flow to one channel, and
 * it is the only "aware" interface this can honestly implement — the quote's
 * buyer is not on the snapshot, so CustomerAware would be a lie.
 */
final class QuoteAgentEscalatedEvent extends NestedEvent implements FlowEventAware, SalesChannelAware
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
}
