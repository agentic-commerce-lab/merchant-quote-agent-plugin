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

    /**
     * The other half of the same alignment SalesChannelHostReaderTest pins:
     * `Request::getHttpHost()` lower-cases the host and drops the scheme's
     * default port, but it does NOT strip the root dot of a fully qualified
     * name (verified against vendor/symfony/http-foundation — getHost() only
     * trims, lower-cases and removes the port). So a buyer agent that fetches
     * `https://shop.example./.well-known/did.json` would otherwise be served
     * a document naming `did:web:shop.example.` while the acts on the quote
     * say `did:web:shop.example`. Both identity paths run through here, so
     * this is the one place that closes it for both.
     */
    public function testItStripsTheRootDotOfAFullyQualifiedAuthority(): void
    {
        self::assertSame('did:web:shop.example', A2cnIdentity::forHost('shop.example.', 'key-1', 'Example Shop')->did);
    }

    /**
     * `rtrim($host, '.')` only strips a dot at the end of the whole string,
     * so a host that carries a port survived with the dot still in the
     * middle: `shop.example.:8095` published `did:web:shop.example.%3A8095`
     * while `SalesChannelHostReader::hostFor()` — which strips the dot
     * before appending the port — signed acts under
     * `did:web:shop.example%3A8095`. Same divergence, its last shape.
     */
    public function testItStripsTheRootDotWhenAPortFollowsIt(): void
    {
        self::assertSame(
            'did:web:shop.example%3A8095',
            A2cnIdentity::forHost('shop.example.:8095', 'key-1', 'Example Shop')->did,
        );
    }

    /**
     * An IPv6 literal brackets its own colons, so the port separator has to
     * be found after the closing bracket — not at the first ':' in the
     * string — or the dot-stripping split would cut the address in half.
     */
    public function testItStripsTheRootDotOfAnIpv6LiteralWithAPort(): void
    {
        self::assertSame('did:web:[%3A%3A1]%3A8095', A2cnIdentity::forHost('[::1]:8095', 'key-1', 'Example Shop')->did);
    }

    public function testItPublishesOneDealTypeAndTheActsConformanceLevel(): void
    {
        self::assertSame(['goods_procurement'], A2cnIdentity::DEAL_TYPES);
        self::assertSame('acts', A2cnIdentity::CONFORMANCE_LEVEL);
    }
}
