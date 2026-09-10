<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Protocol\Did\DidWebResolver;

/**
 * Fixtures shared by the Protocol unit tests. Deliberately plain arrays and
 * real DTOs — an act is its raw array, and a builder would hide the shape the
 * tests are about.
 */
final class ProtocolFixtures
{
    public const SELLER = 'did:web:shop.example';
    public const BUYER = 'did:web:buyer.example';

    /**
     * A non-empty placeholder: SellerActFactory::identityFor() treats an empty
     * salesChannelId as "no did:web authority to publish under", so a fixture
     * snapshot needs a non-empty one to reach the emitter's identity double.
     */
    public const SALES_CHANNEL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function __construct() {}

    public static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-04T10:00:00+00:00');
    }

    /** @return array<string, mixed> */
    public static function buyerAct(int $sequence, string $session, string $type = 'offer'): array
    {
        return self::act($sequence, $session, self::BUYER, $type);
    }

    /** @return array<string, mixed> */
    public static function sellerAct(int $sequence, string $session, string $type = 'counteroffer'): array
    {
        return self::act($sequence, $session, self::SELLER, $type);
    }

    /** @return array<string, mixed> */
    public static function act(int $sequence, string $session, string $did, string $type): array
    {
        return [
            'message_type' => $type,
            'message_id' => $session . ':' . $sequence,
            'session_id' => $session,
            'round_number' => 1,
            'sequence_number' => $sequence,
            'sender_did' => $did,
            'sender_agent_id' => $did === self::SELLER ? 'merchant-quote-agent' : 'buyer-agent',
            'sender_verification_method' => $did . '#key-1',
            'timestamp' => '2026-09-04T09:00:00Z',
            'terms' => self::terms(),
            'protocol_act_hash' => 'hash-' . $sequence,
            'protocol_act_signature' => 'signature-' . $sequence,
        ];
    }

    /** @return array<string, mixed> */
    public static function terms(int $quantity = 10): array
    {
        return [
            'total_value' => 760000,
            'currency' => 'EUR',
            'line_items' => [
                [
                    'id' => 'line-1',
                    'description' => 'FusionGlow Sport',
                    'quantity' => $quantity,
                    'unit' => 'piece',
                    'unit_price' => 76000,
                    'total' => 760000,
                ],
            ],
            'custom_terms' => ['tax_status' => 'net', 'quote_number' => 'Q-1001', 'shopware_net_total_minor' => 760000],
        ];
    }

    /** @param array<string, mixed> $customFields */
    public static function snapshot(
        string $quoteId = '11111111111111111111111111111111',
        string $state = 'replied',
        array $customFields = [],
        int $quantity = 10,
        string $salesChannelId = self::SALES_CHANNEL_ID,
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity(
                quoteId: $quoteId,
                quoteNumber: 'Q-1001',
                currencyIso: 'EUR',
                salesChannelId: $salesChannelId,
            ),
            revision: new QuoteRevision('rev-1', new \DateTimeImmutable('2026-09-04T09:00:00+00:00')),
            totals: new QuoteTotals(totalNet: 7600.0),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $state,
                expiresAt: new \DateTimeImmutable('2026-09-18T10:00:00+00:00'),
                customFields: $customFields,
            ),
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity(lineItemId: 'line-1', label: 'FusionGlow Sport'),
                    quantity: $quantity,
                    unitPriceNet: 760.0,
                    totalNet: 7600.0,
                ),
            ]),
        );
    }

    /** @return array{private: string, public: string} */
    public static function keyPair(): array
    {
        $resource = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $private = '';
        if ($resource === false || !openssl_pkey_export($resource, $private)) {
            throw new \RuntimeException('failed to generate a test EC key pair');
        }

        $details = openssl_pkey_get_details($resource);
        if (!\is_array($details) || !\is_string($details['key'])) {
            throw new \RuntimeException('failed to read the generated test EC key pair');
        }

        return ['private' => $private, 'public' => $details['key']];
    }

    /** A DidWebResolver double that always resolves to the same key, or to none. */
    public static function resolvingTo(?string $publicKeyPem): DidWebResolver
    {
        return new class($publicKeyPem) extends DidWebResolver {
            public function __construct(
                private readonly ?string $pem,
            ) {}

            public function publicKeyPemFor(string $verificationMethod): ?string
            {
                return $this->pem;
            }
        };
    }
}
