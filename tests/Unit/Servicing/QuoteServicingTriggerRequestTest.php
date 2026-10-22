<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminSalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;

/**
 * A released SwagCommercial (6.7.1.2-6.7.12) creates a requested quote
 * directly in `open`, with no state transition, so a request with an empty
 * comment dialog raised neither event the trigger used to listen to. The
 * request event is the one signal every release sends.
 */
final class QuoteServicingTriggerRequestTest extends TestCase
{
    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testABuyersQuoteRequestQueuesTheQuote(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        (new QuoteServicingTrigger($bus))->onQuoteRequested(QuoteTriggerEventFixture::requestedEvent('q7'));

        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0] ?? null;
        self::assertNotNull($message);
        self::assertSame('q7', $message->quoteId);
        self::assertSame('quote_requested', $message->reason);
    }

    /**
     * The same route serves a merchant building a quote for the customer
     * through the admin's sales-channel proxy. That is not the buyer asking.
     *
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     */
    public function testARequestTheMerchantPlacedThroughTheAdministrationQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $context = new Context(new AdminSalesChannelApiSource('sc1', Context::createDefaultContext()));

        (new QuoteServicingTrigger($bus))->onQuoteRequested(QuoteTriggerEventFixture::requestedEvent('q7', $context));

        self::assertSame([], $bus->messages);
    }

    /**
     * The listener runs inside the buyer's own request. An event of another
     * shape must cost the agent a trigger, never the buyer their quote.
     *
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     */
    public function testAnEventWithoutAQuoteIdQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = new readonly class implements ShopwareEvent {
            #[\Override]
            public function getContext(): Context
            {
                return Context::createDefaultContext();
            }
        };

        (new QuoteServicingTrigger($bus))->onQuoteRequested($event);

        self::assertSame([], $bus->messages);
    }
}
