<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Identity\UcpAgentHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UcpAgentHeaderTest extends TestCase
{
    #[Test]
    #[DataProvider('headerProvider')]
    public function testProfileHostIsReadFromTheHeader(?string $header, ?string $expected): void
    {
        self::assertSame($expected, UcpAgentHeader::profileHost($header));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function headerProvider(): iterable
    {
        yield 'host of the profile uri' => [
            'agent="x"; profile="https://agent.example/.well-known/ucp"',
            'agent.example',
        ];
        yield 'lowercased' => ['profile="https://Agent.Example/.well-known/ucp"', 'agent.example'];
        // Kept as-is rather than stripped: the gates compare the unstripped host.
        yield 'trailing dot kept' => ['profile="https://agent.example./.well-known/ucp"', 'agent.example.'];
        yield 'a bare dot is not widened to the empty string' => ['profile="https://./ucp"', '.'];
        yield 'all dots is not widened to the empty string' => ['profile="https://.../ucp"', '...'];
        yield 'port is not part of the host' => ['profile="https://agent.example:8443/p"', 'agent.example'];
        yield 'null header' => [null, null];
        yield 'blank header' => ['   ', null];
        yield 'no profile parameter' => ['agent="x"', null];
        yield 'profile without a host' => ['profile="/relative/ucp"', null];
        yield 'unparseable profile' => ['profile="://"', null];
    }

    #[Test]
    public function testTheHeaderNameIsMatchedCaseInsensitively(): void
    {
        self::assertSame('agent.example', UcpAgentHeader::profileHostFromHeaders([
            'ucp-agent' => 'profile="https://agent.example/p"',
        ]));
    }

    #[Test]
    public function testMissingHeaderYieldsNoHost(): void
    {
        self::assertNull(UcpAgentHeader::profileHostFromHeaders(['content-type' => 'application/json']));
    }

    #[Test]
    public function testItDropsThePortFromTheProfileHost(): void
    {
        self::assertSame(
            'agent.example',
            UcpAgentHeader::profileHost('manual/1.0; profile="https://agent.example:8443/.well-known/ucp"'),
        );
    }
}
