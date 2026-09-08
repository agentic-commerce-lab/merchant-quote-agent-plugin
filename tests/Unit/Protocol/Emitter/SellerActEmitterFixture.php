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
use MerchantQuoteAgentPlugin\Protocol\Emitter\SellerActEmitter;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use Psr\Log\NullLogger;

/**
 * Builders for SellerActEmitterTest — split out for the same reason
 * ServicingHandlerFixture is split from the servicing tests: it keeps the
 * test class itself under mago's too-many-methods ceiling.
 */
final class SellerActEmitterFixture
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    private function __construct() {}

    /** @param array<string, mixed> $extraFields */
    public static function snapshotWithChain(
        string $state = 'replied',
        array $extraFields = [],
        ?string $salesChannelId = null,
    ): QuoteSnapshot {
        $session = SessionId::forQuote(self::QUOTE_ID);

        return ProtocolFixtures::snapshot(
            self::QUOTE_ID,
            state: $state,
            customFields: [
                ActKey::SESSION_KEY => $session,
                ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
                ...$extraFields,
            ],
            salesChannelId: $salesChannelId ?? ProtocolFixtures::SALES_CHANNEL_ID,
        );
    }

    public static function emitter(
        InMemoryActStore $store,
        ?QuoteGatewayInterface $gateway,
        ?EvidenceInspector $inspector = null,
    ): SellerActEmitter {
        return new SellerActEmitter(
            TestActSigner::factory(),
            $inspector ?? new EvidenceInspector([]),
            new ChainMirror($store),
            new NullLogger(),
            $gateway,
        );
    }

    public static function refusingInspector(): EvidenceInspector
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
