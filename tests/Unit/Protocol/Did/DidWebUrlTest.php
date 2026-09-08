<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Did;

use MerchantQuoteAgentPlugin\Protocol\Did\DidWebUrl;
use PHPUnit\Framework\TestCase;

final class DidWebUrlTest extends TestCase
{
    public function testItResolvesAHostOnlyDidToTheWellKnownPath(): void
    {
        self::assertSame('https://buyer.example/.well-known/did.json', DidWebUrl::forDid('did:web:buyer.example'));
    }

    public function testItResolvesAPathDidWithoutTheWellKnownSegment(): void
    {
        // W3C did:web: path form drops /.well-known/ entirely.
        self::assertSame(
            'https://buyer.example/agents/quote/did.json',
            DidWebUrl::forDid('did:web:buyer.example:agents:quote'),
        );
    }

    public function testItDecodesAPercentEncodedAuthority(): void
    {
        self::assertSame(
            'https://buyer.example:8443/.well-known/did.json',
            DidWebUrl::forDid('did:web:buyer.example%3A8443'),
        );
    }

    public function testItRejectsAnythingThatIsNotDidWeb(): void
    {
        self::assertNull(DidWebUrl::forDid('did:key:z6Mk'));
        self::assertNull(DidWebUrl::forDid('https://buyer.example'));
        self::assertNull(DidWebUrl::forDid('did:web:'));
    }

    public function testItSplitsTheDidOffAVerificationMethod(): void
    {
        self::assertSame('did:web:buyer.example', DidWebUrl::didOf('did:web:buyer.example#key-1'));
        self::assertNull(DidWebUrl::didOf('did:web:buyer.example'));
    }
}
