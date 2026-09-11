<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteStateUnavailable;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalStateReader;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * The real reader, not the controller-test double: what proves the null-vs-
 * throw split, the expiry computation and the acceptance lookup actually
 * work, since A2cnRecordsControllerTest only ever substitutes this class.
 */
final class QuoteTerminalStateReaderTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItReturnsNullWhenTheQuoteGenuinelyDoesNotExist(): void
    {
        $reader = new QuoteTerminalStateReader(self::gateway(throw: QuoteNotFoundException::forId(self::QUOTE_ID)));

        self::assertNull($reader->for(self::QUOTE_ID, ProtocolFixtures::at()));
    }

    /**
     * A null gateway (SwagCommercial present but unlicensed) is treated the
     * same as a failed lookup — not a TypeError on construction, which would
     * be exactly the generic 500 this class exists to avoid.
     */
    public function testItThrowsQuoteStateUnavailableWithNoGateway(): void
    {
        $reader = new QuoteTerminalStateReader();

        $this->expectException(QuoteStateUnavailable::class);
        $reader->for(self::QUOTE_ID, ProtocolFixtures::at());
    }

    public function testItThrowsQuoteStateUnavailableWhenTheLookupFails(): void
    {
        $cause = new \RuntimeException('connection refused');
        $reader = new QuoteTerminalStateReader(self::gateway(throw: $cause));

        try {
            $reader->for(self::QUOTE_ID, ProtocolFixtures::at());
            self::fail('Expected QuoteStateUnavailable.');
        } catch (QuoteStateUnavailable $error) {
            self::assertSame($cause, $error->getPrevious());
        }
    }

    public function testItReadsTheStateAndQuoteNumberOffTheSnapshot(): void
    {
        $snapshot = ProtocolFixtures::snapshot(self::QUOTE_ID, state: 'declined');
        $reader = new QuoteTerminalStateReader(self::gateway(snapshot: $snapshot));

        $state = $reader->for(self::QUOTE_ID, ProtocolFixtures::at());

        self::assertNotNull($state);
        self::assertSame('declined', $state->state);
        self::assertSame('Q-1001', $state->quoteNumber);
        self::assertSame(ProtocolFixtures::SALES_CHANNEL_ID, $state->salesChannelId);
        self::assertNull($state->acceptance);
        self::assertSame('', $state->buyerOrganizationName);
    }

    public function testItComputesExpiredAgainstTheGivenNow(): void
    {
        $snapshot = ProtocolFixtures::snapshot(self::QUOTE_ID);
        $reader = new QuoteTerminalStateReader(self::gateway(snapshot: $snapshot));

        // The fixture's expiresAt is 2026-09-18T10:00:00+00:00.
        $notYet = $reader->for(self::QUOTE_ID, new \DateTimeImmutable('2026-09-17T00:00:00+00:00'));
        $past = $reader->for(self::QUOTE_ID, new \DateTimeImmutable('2026-09-19T00:00:00+00:00'));

        self::assertNotNull($notYet);
        self::assertFalse($notYet->expired);
        self::assertNotNull($past);
        self::assertTrue($past->expired);
    }

    public function testItFindsTheLastAcceptanceActOnTheChain(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $customFields = [
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session, 'offer'),
            ActKey::for(2, ActRole::Seller) => ProtocolFixtures::sellerAct(2, $session, 'counteroffer'),
            ActKey::for(3, ActRole::Buyer) => ProtocolFixtures::buyerAct(3, $session, 'acceptance'),
        ];
        $snapshot = ProtocolFixtures::snapshot(self::QUOTE_ID, customFields: $customFields);
        $reader = new QuoteTerminalStateReader(self::gateway(snapshot: $snapshot));

        $state = $reader->for(self::QUOTE_ID, ProtocolFixtures::at());

        self::assertNotNull($state);
        self::assertNotNull($state->acceptance);
        self::assertSame('acceptance', $state->acceptance->messageType());
        self::assertSame(3, $state->acceptance->sequenceNumber());
        // The same read also carries the raw custom fields forward, so a
        // caller (A2cnMessagesController) can build the chain off this one
        // object instead of fetching the snapshot a second time.
        self::assertSame($customFields, $state->customFields);
    }

    public function testItResolvesOrderNumberWhenQuoteConvertedToOrderAndDegradesGracefully(): void
    {
        $order = new class extends Entity {
            public ?string $orderNumber = null;
        };
        $order->setUniqueIdentifier('order-1');
        $order->orderNumber = '10014';

        $searchResult = new EntitySearchResult(
            'order',
            1,
            new EntityCollection([$order]),
            null,
            new Criteria(['order-1']),
            Context::createDefaultContext(),
        );

        $orders = $this->createMock(EntityRepository::class);
        $orders->method('search')->willReturn($searchResult);

        $convertedSnapshot = new QuoteSnapshot(
            identity: new QuoteIdentity(
                quoteId: self::QUOTE_ID,
                quoteNumber: 'Q-1001',
                currencyIso: 'EUR',
                salesChannelId: ProtocolFixtures::SALES_CHANNEL_ID,
                orderId: 'order-1',
            ),
            revision: new QuoteRevision('rev-1', new \DateTimeImmutable('2026-09-04T09:00:00+00:00')),
            totals: new QuoteTotals(totalNet: 7600.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'replied'),
            content: new QuoteContent(),
        );

        $reader = new QuoteTerminalStateReader(self::gateway(snapshot: $convertedSnapshot), $orders);
        $state = $reader->for(self::QUOTE_ID, ProtocolFixtures::at());

        self::assertNotNull($state);
        self::assertSame('10014', $state->orderNumber);

        // Degrades gracefully when search throws
        $failingOrders = $this->createMock(EntityRepository::class);
        $failingOrders->method('search')->willThrowException(new \RuntimeException('order db failure'));
        $failingReader = new QuoteTerminalStateReader(self::gateway(snapshot: $convertedSnapshot), $failingOrders);
        $failingState = $failingReader->for(self::QUOTE_ID, ProtocolFixtures::at());

        self::assertNotNull($failingState);
        self::assertNull($failingState->orderNumber);

        // Degrades gracefully when quote has no orderId or orders repo is null
        $unconvertedReader =
            new QuoteTerminalStateReader(self::gateway(snapshot: ProtocolFixtures::snapshot(self::QUOTE_ID)));
        $unconvertedState = $unconvertedReader->for(self::QUOTE_ID, ProtocolFixtures::at());

        self::assertNotNull($unconvertedState);
        self::assertNull($unconvertedState->orderNumber);
    }

    private static function gateway(?QuoteSnapshot $snapshot = null, ?\Throwable $throw = null): QuoteGatewayInterface
    {
        return new class($snapshot, $throw) implements QuoteGatewayInterface {
            public function __construct(
                private readonly ?QuoteSnapshot $snapshot,
                private readonly ?\Throwable $throw,
            ) {}

            public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return $this->snapshot ?? ProtocolFixtures::snapshot($quoteId);
            }

            public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
            {
                throw new \LogicException('not used');
            }

            public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
            {
                throw new \LogicException('not used');
            }

            public function addProduct(string $quoteId, string $productId, int $quantity): void
            {
                throw new \LogicException('not used');
            }

            public function recalculate(string $quoteId): void
            {
                throw new \LogicException('not used');
            }

            public function addComment(string $quoteId, string $comment): void
            {
                throw new \LogicException('not used');
            }

            public function transition(string $quoteId, QuoteTransition $action): void
            {
                throw new \LogicException('not used');
            }
        };
    }
}
