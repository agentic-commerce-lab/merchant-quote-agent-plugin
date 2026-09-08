<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Crypto;

use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use PHPUnit\Framework\TestCase;

final class SessionIdTest extends TestCase
{
    /** The published RFC 4122 / RFC 9562 name-based vector: DNS namespace + "python.org". */
    public function testItMatchesThePublishedUuidV5Vector(): void
    {
        self::assertSame('886313e1-3b8a-5372-9b90-0c9aee199e5d', SessionId::derive(
            'python.org',
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ));
    }

    public function testItDerivesTheSessionFromTheQuoteId(): void
    {
        // Derived, not random: this is what makes emission idempotent and lets
        // a records reader recompute the id from the quote it already holds.
        self::assertSame(
            '57d88e14-5e38-5b75-a94e-1b46206f6215',
            SessionId::forQuote('11111111111111111111111111111111'),
        );
    }

    public function testItIsStableAndVersionFive(): void
    {
        $id = SessionId::forQuote('0189d1c8f4f27c3ea0d4a5b6c7d8e9f0');

        self::assertSame($id, SessionId::forQuote('0189d1c8f4f27c3ea0d4a5b6c7d8e9f0'));
        self::assertSame('5', $id[14]);
        self::assertContains($id[19], ['8', '9', 'a', 'b']);
    }
}
