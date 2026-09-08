<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Crypto;

use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class ProtocolHashTest extends TestCase
{
    public function testItCanonicalizesKeysInCodeUnitOrder(): void
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        self::assertSame('{"a":2,"b":1}', $hash->canonical(['b' => 1, 'a' => 2]));
    }

    public function testItPinsTheDigestOfAKnownSignedObject(): void
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        self::assertSame('URfXIwylDk4_weTXPY5hoEPnFvUdKgGCZZ2g39kTamI', $hash->of(self::signedObject()));
    }

    public function testItHashesAListForTheOfferChain(): void
    {
        $hash = new ProtocolHash(new DefaultJsonCanonicalization());

        self::assertSame('n6b0RQ2eX2xHsYIKnSUzelAONkFLftZzriw5jhlAVuA', $hash->of(['h1', 'h2']));
    }

    /** @return array<string, mixed> */
    private static function signedObject(): array
    {
        return [
            'protocol_version' => '0.1',
            'session_id' => 'a2cn-session',
            'sequence_number' => 1,
            'message_type' => 'counteroffer',
            'sender_did' => 'did:web:shop.example',
            'timestamp' => '2026-09-04T10:00:00Z',
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
                'custom_terms' => [
                    'tax_status' => 'net',
                    'quote_number' => 'Q-1001',
                    'shopware_net_total_minor' => 760000,
                ],
            ],
        ];
    }
}
