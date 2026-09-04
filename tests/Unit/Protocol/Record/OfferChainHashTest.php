<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Record\OfferChainHash;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\DefaultJsonCanonicalization;

final class OfferChainHashTest extends TestCase
{
    public function testItHashesTheOrderedActHashes(): void
    {
        $hash = new OfferChainHash(new ProtocolHash(new DefaultJsonCanonicalization()));

        // Pinned: the same two hashes in the same order must always give this.
        self::assertSame('n6b0RQ2eX2xHsYIKnSUzelAONkFLftZzriw5jhlAVuA', $hash->of([
            self::actWithHash('h1'),
            self::actWithHash('h2'),
        ]));
    }

    public function testOrderMatters(): void
    {
        $hash = new OfferChainHash(new ProtocolHash(new DefaultJsonCanonicalization()));

        self::assertNotSame(
            $hash->of([self::actWithHash('h1'), self::actWithHash('h2')]),
            $hash->of([self::actWithHash('h2'), self::actWithHash('h1')]),
        );
    }

    public function testADroppedActChangesTheHash(): void
    {
        // This is the property the whole record rests on: an omission is
        // detectable after the fact.
        $hash = new OfferChainHash(new ProtocolHash(new DefaultJsonCanonicalization()));

        self::assertNotSame(
            $hash->of([self::actWithHash('h1'), self::actWithHash('h2')]),
            $hash->of([self::actWithHash('h1')]),
        );
    }

    private static function actWithHash(string $digest): Act
    {
        $raw = ProtocolFixtures::sellerAct(1, 'session');
        $raw['protocol_act_hash'] = $digest;
        $act = Act::fromArray($raw);
        self::assertNotNull($act);

        return $act;
    }
}
