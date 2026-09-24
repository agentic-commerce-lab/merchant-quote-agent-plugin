<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Emitter\EmissionStatus;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeTraceWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway;
use PHPUnit\Framework\TestCase;

final class SellerActEmitterTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testEachObservationRecordsItsOutcomeAndPublishedAct(): void
    {
        $writer = new FakeTraceWriter();
        $store = new InMemoryActStore();
        $emitter = SellerActEmitterFixture::emitter($store, new RecordingQuoteGateway(), writer: $writer);

        $emitter->observe(ProtocolFixtures::snapshot(self::QUOTE_ID), ProtocolFixtures::at());
        $emitter->observe(SellerActEmitterFixture::snapshotWithChain(), ProtocolFixtures::at());

        self::assertCount(2, $writer->events);
        self::assertSame(TraceKind::SellerAct, $writer->events[0]->kind);
        self::assertSame('inert', $writer->events[0]->meta['result']);
        self::assertNull($writer->events[0]->content);
        self::assertSame('emitted', $writer->events[1]->meta['result']);
        self::assertSame(2, $writer->events[1]->meta['seq']);
        self::assertSame('counteroffer', $writer->events[1]->meta['actType']);
        self::assertSame($writer->events[1]->meta['offerHash'], $writer->events[1]->content['protocol_act_hash']);
    }

    public function testItIsInertWithoutASession(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();

        $outcome = SellerActEmitterFixture::emitter($store, $gateway)->observe(
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::at(),
        );

        self::assertSame(EmissionStatus::Inert, $outcome->status);
        self::assertSame([], $gateway->updates);
        self::assertSame([], $store->acts);
    }

    public function testItMirrorsTheWholeChainEvenWhenItDoesNotEmit(): void
    {
        // A buyer act that triggers no emission must still reach our own copy:
        // the mirror is the only record we control.
        $store = new InMemoryActStore();
        $snapshot = SellerActEmitterFixture::snapshotWithChain(state: 'open');

        $outcome = SellerActEmitterFixture::emitter($store, new RecordingQuoteGateway())->observe(
            $snapshot,
            ProtocolFixtures::at(),
        );

        self::assertSame(EmissionStatus::Unchanged, $outcome->status);
        self::assertCount(1, $store->acts);
    }

    public function testItEmitsOneSellerActOnAnOfferVisibleState(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();

        $outcome = SellerActEmitterFixture::emitter($store, $gateway)->observe(
            SellerActEmitterFixture::snapshotWithChain(),
            ProtocolFixtures::at(),
        );

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
        $emitter = SellerActEmitterFixture::emitter($store, $gateway);
        $snapshot = SellerActEmitterFixture::snapshotWithChain();

        $emitter->observe($snapshot, ProtocolFixtures::at());
        $emitted = $gateway->updates[0]->customFields ?? [];
        $again = SellerActEmitterFixture::snapshotWithChain(extraFields: $emitted);

        $outcome = $emitter->observe($again, ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Unchanged, $outcome->status);
        self::assertCount(1, $gateway->updates);
    }

    public function testAViolationSuppressesEmissionAndIsPersisted(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway();
        $emitter = SellerActEmitterFixture::emitter($store, $gateway, SellerActEmitterFixture::refusingInspector());

        $outcome = $emitter->observe(SellerActEmitterFixture::snapshotWithChain(), ProtocolFixtures::at());

        self::assertSame(EmissionStatus::Violation, $outcome->status);
        self::assertSame('duplicate_sequence', $outcome->violation?->violationType);
        self::assertSame([], $gateway->updates);
        self::assertCount(1, $store->violations);
    }

    public function testItMirrorsBeforeTheWireWrite(): void
    {
        $store = new InMemoryActStore();
        $gateway = new RecordingQuoteGateway(failOnUpdate: true);

        $outcome = SellerActEmitterFixture::emitter($store, $gateway)->observe(
            SellerActEmitterFixture::snapshotWithChain(),
            ProtocolFixtures::at(),
        );

        self::assertSame(EmissionStatus::Failed, $outcome->status);
        // The act reached our mirror, so the next observation still sees the
        // terms as changed and retries the append.
        self::assertCount(2, $store->acts);
        // Our own failure is never recorded as the counterparty's violation.
        self::assertSame([], $store->violations);
    }

    public function testItStaysInertRatherThanCrashWhenTheShopIsUnlicensed(): void
    {
        // QuoteGatewayFactory::create() returns null (not an undefined
        // service) when SwagCommercial's classes exist but the licence is
        // off. A null gateway must never reach a non-nullable constructor
        // parameter and crash before observe()'s try/catch exists.
        $store = new InMemoryActStore();

        $outcome = SellerActEmitterFixture::emitter($store, null)->observe(
            SellerActEmitterFixture::snapshotWithChain(),
            ProtocolFixtures::at(),
        );

        self::assertSame(EmissionStatus::Inert, $outcome->status);
        // The chain still had a session and a readable act, so the mirror
        // ran; only the wire write — which needs the gateway — did not.
        self::assertCount(1, $store->acts);
    }

    public function testAnUnresolvableIdentityStillMirrorsTheChain(): void
    {
        // Real buyer acts on the quote, but nothing we can sign into them
        // yet (no did:web authority for this sales channel) — exactly the
        // "never triggers an emission" case the mirror exists for.
        $store = new InMemoryActStore();
        $snapshot = SellerActEmitterFixture::snapshotWithChain(salesChannelId: '');

        $outcome = SellerActEmitterFixture::emitter($store, new RecordingQuoteGateway())->observe(
            $snapshot,
            ProtocolFixtures::at(),
        );

        self::assertSame(EmissionStatus::Inert, $outcome->status);
        self::assertCount(1, $store->acts);
    }

    public function testItWritesAReceiptOnlyWhenAnEscalationIsUnreleased(): void
    {
        $store = new InMemoryActStore();
        $withMarker = SellerActEmitterFixture::snapshotWithChain(extraFields: [
            QuoteEscalator::MARKER_KEY => 'discount_above_band',
        ]);

        SellerActEmitterFixture::emitter($store, new RecordingQuoteGateway())->observe(
            $withMarker,
            ProtocolFixtures::at(),
        );

        self::assertCount(1, $store->receipts);
        self::assertSame('discount_above_band', $store->receipts[0]->thresholdCrossed);

        $clean = new InMemoryActStore();
        SellerActEmitterFixture::emitter($clean, new RecordingQuoteGateway())->observe(
            SellerActEmitterFixture::snapshotWithChain(),
            ProtocolFixtures::at(),
        );

        self::assertSame([], $clean->receipts);
    }
}
