<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use PHPUnit\Framework\TestCase;

final class ActTest extends TestCase
{
    public function testItReadsTheEnvelope(): void
    {
        $act = Act::fromArray(self::buyerAct());

        self::assertNotNull($act);
        self::assertSame('offer', $act->messageType());
        self::assertSame('session:1', $act->messageId());
        self::assertSame(1, $act->sequenceNumber());
        self::assertSame(1, $act->roundNumber());
        self::assertSame('did:web:buyer.example', $act->senderDid());
        self::assertSame('did:web:buyer.example#key-1', $act->verificationMethod());
        self::assertTrue($act->isOffer());
    }

    public function testItKeepsUnmodelledTermsKeysVerbatim(): void
    {
        $raw = self::buyerAct();
        $raw['terms']['not_modelled_yet'] = ['nested' => true];
        $raw['also_unknown'] = 'kept';

        $act = Act::fromArray($raw);

        self::assertNotNull($act);
        self::assertSame(['nested' => true], $act->terms()['not_modelled_yet'] ?? null);
        self::assertSame($raw, $act->raw());
    }

    public function testItReturnsNullForAMalformedAct(): void
    {
        $raw = self::buyerAct();
        unset($raw['sender_verification_method']);

        self::assertNull(Act::fromArray($raw));
        self::assertNull(Act::fromArray(['message_type' => 'offer']));
    }

    public function testItRefusesAnOversizedAct(): void
    {
        $raw = self::buyerAct();
        $raw['terms']['custom_terms']['padding'] = str_repeat('x', Act::MAX_ENCODED_BYTES);

        self::assertNull(Act::fromArray($raw));
    }

    public function testItRefusesASequenceNumberBelowTheRange(): void
    {
        $raw = self::buyerAct();
        $raw['sequence_number'] = 0;

        self::assertNull(Act::fromArray($raw));

        $raw['sequence_number'] = -1;

        self::assertNull(Act::fromArray($raw));
    }

    public function testItRefusesASequenceNumberAboveTheRange(): void
    {
        $raw = self::buyerAct();
        $raw['sequence_number'] = ActKey::MAX_SEQUENCE + 1;

        self::assertNull(Act::fromArray($raw));
    }

    public function testItRefusesAStringSequenceNumberRatherThanCoercingIt(): void
    {
        $raw = self::buyerAct();
        $raw['sequence_number'] = '3';

        self::assertNull(Act::fromArray($raw));
    }

    public function testItExposesLineQuantitiesForTheTermsCheck(): void
    {
        $act = Act::fromArray(self::buyerAct());

        self::assertNotNull($act);
        self::assertSame(['line-1' => 10], $act->lineQuantities());
    }

    /** @return array<string, mixed> */
    private static function buyerAct(): array
    {
        return [
            'message_type' => 'offer',
            'message_id' => 'session:1',
            'session_id' => '57d88e14-5e38-5b75-a94e-1b46206f6215',
            'round_number' => 1,
            'sequence_number' => 1,
            'sender_did' => 'did:web:buyer.example',
            'sender_agent_id' => 'buyer-agent',
            'sender_verification_method' => 'did:web:buyer.example#key-1',
            'timestamp' => '2026-09-04T09:00:00Z',
            'terms' => [
                'total_value' => 760000,
                'currency' => 'EUR',
                'line_items' => [
                    [
                        'id' => 'line-1',
                        'description' => 'FusionGlow Sport',
                        'quantity' => 10,
                        'unit' => 'piece',
                        'unit_price' => 76000,
                        'total' => 760000,
                    ],
                ],
                'custom_terms' => ['tax_status' => 'net'],
            ],
            'protocol_act_hash' => 'URfXIwylDk4_weTXPY5hoEPnFvUdKgGCZZ2g39kTamI',
            'protocol_act_signature' => 'eyJhbGciOiJFUzI1NiJ9.dGVzdA.c2ln',
        ];
    }
}
