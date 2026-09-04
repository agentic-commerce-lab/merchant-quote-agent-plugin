<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceInspector;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use MerchantQuoteAgentPlugin\Protocol\Emitter\EmissionStatus;
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SellerActEmitterTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItIsInertWithoutASession(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();

        $outcome = self::emitter($store, $gateway)
            ->observe(ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Inert, $outcome->status);
        self::assertSame([], $gateway->updates);
        self::assertSame([], $store->acts);
    }

    public function testItMirrorsTheWholeChainEvenWhenItDoesNotEmit(): void
    {
        // A buyer act that triggers no emission must still reach our own copy:
        // the mirror is the only record we control.
        $store = new InMemoryActStore();
        $snapshot = self::snapshotWithChain(state: 'open');

        $outcome = self::emitter($store, new RecordingQuoteGateway())->observe($snapshot, ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Unchanged, $outcome->status);
        self::assertCount(1, $store->acts);
    }

    public function testItEmitsOneSellerActOnAnOfferVisibleState(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();

        $outcome = self::emitter($store, $gateway)->observe(self::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Emitted, $outcome->status);
        self::assertCount(1, $gateway->updates);
        $customFields = $gateway->updates[0]->customFields ?? [];
        self::assertSame([ActKey::for(2, ActRole::Seller)], array_keys($customFields));
        self::assertSame(2, $customFields[ActKey::for(2, ActRole::Seller)]['sequence_number'] ?? null);
        // Mirror AND wire, both.
        self::assertCount(2, $store->acts);
    }

    public function testItDoesNothingOnASecondObservationOfTheSameTerms(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();
        $emitter = self::emitter($store, $gateway);
        $snapshot = self::snapshotWithChain();

        $emitter->observe($snapshot, ProtocolFixtures::at());
        $emitted = $gateway->updates[0]->customFields ?? [];
        $again = self::snapshotWithChain(extraFields: $emitted);

        $outcome = $emitter->observe($again, ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Unchanged, $outcome->status);
        self::assertCount(1, $gateway->updates);
    }

    public function testAViolationSuppressesEmissionAndIsPersisted(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();
        $emitter = self::emitter($store, $gateway, self::refusingInspector());

        $outcome = $emitter->observe(self::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Violation, $outcome->status);
        self::assertSame('duplicate_sequence', $outcome->violation?->violationType);
        self::assertSame([], $gateway->updates);
        self::assertCount(1, $store->violations);
    }

    public function testItMirrorsBeforeTheWireWrite(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway(failOnUpdate: true);

        $outcome = self::emitter($store, $gateway)->observe(self::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Failed, $outcome->status);
        // The act reached our mirror, so the next observation still sees the
        // terms as changed and retries the append.
        self::assertCount(2, $store->acts);
        // Our own failure is never recorded as the counterparty's violation.
        self::assertSame([], $store->violations);
    }

    public function testItWritesAReceiptOnlyWhenAnEscalationIsUnreleased(): void
    {
        $store = new InMemoryActStore();
        $withMarker = self::snapshotWithChain(extraFields: [QuoteEscalator::MARKER_KEY => 'discount_above_band']);

        self::emitter($store, new RecordingQuoteGateway())->observe($withMarker, ProtocolFixtures::at());

        self::assertCount(1, $store->receipts);
        self::assertSame('discount_above_band', $store->receipts[0]->thresholdCrossed);

        $clean = new InMemoryActStore();
        self::emitter($clean, new RecordingQuoteGateway())->observe(self::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame([], $clean->receipts);
    }

    /** @param array<string, mixed> $extraFields */
    private static function snapshotWithChain(string $state = 'replied', array $extraFields = []): QuoteSnapshot
    {
        $session = SessionId::forQuote(self::QUOTE_ID);

        return ProtocolFixtures::snapshot(self::QUOTE_ID, state: $state, customFields: [
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
            ...$extraFields,
        ]);
    }

    private static function emitter(
        InMemoryActStore $store,
        QuoteGatewayInterface $gateway,
        ?EvidenceInspector $inspector = null,
    ): SellerActEmitter {
        return new SellerActEmitter(
            TestActSigner::factory(),
            $inspector ?? new EvidenceInspector([]),
            new ChainMirror($store),
            $gateway,
            new NullLogger(),
        );
    }

    private static function refusingInspector(): EvidenceInspector
    {
        return new EvidenceInspector([new class implements
            \MerchantQuoteAgentPlugin\Protocol\Check\EvidenceCheckInterface {
            public function check(
                ActChain $chain,
                QuoteSnapshot $snapshot,
                string $sellerDid,
                \DateTimeImmutable $at,
            ): ?ProtocolViolation {
                return new ProtocolViolation($at->format(\DATE_ATOM), 'duplicate_sequence', null, 'sequence 1 twice');
            }
        }]);
    }
}
