<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Identity;

use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use PHPUnit\Framework\TestCase;

final class A2cnIdentityTest extends TestCase
{
    public function testItBuildsADidWebIdentityFromAHostAndKid(): void
    {
        $identity = A2cnIdentity::forHost('shop.example', 'key-1', 'Example Shop');

        self::assertSame('did:web:shop.example', $identity->did);
        self::assertSame('did:web:shop.example#key-1', $identity->verificationMethod);
        self::assertSame('Example Shop', $identity->organizationName);
        self::assertSame('merchant-quote-agent', $identity->agentId);
    }

    public function testItPercentEncodesAPortInTheAuthority(): void
    {
        // did:web encodes the colon of a port, or the DID's own segment
        // separator would swallow it.
        self::assertSame(
            'did:web:shop.example%3A8443',
            A2cnIdentity::forHost('shop.example:8443', 'key-1', 'Example Shop')->did,
        );
    }

    public function testItPublishesOneDealTypeAndTheActsConformanceLevel(): void
    {
        self::assertSame(['goods_procurement'], A2cnIdentity::DEAL_TYPES);
        self::assertSame('acts', A2cnIdentity::CONFORMANCE_LEVEL);
    }
}
