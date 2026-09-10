<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\TimestampFormatCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class TimestampFormatCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesOnZuluTimestamps(): void
    {
        self::assertNull($this->check([
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $this->session()),
        ]));
    }

    public function testItReportsAnOffsetFormTimestamp(): void
    {
        $act = ProtocolFixtures::buyerAct(1, $this->session());
        $act['timestamp'] = '2026-09-10T09:42:07+00:00';

        $violation = $this->check([ActKey::for(1, ActRole::Buyer) => $act]);

        self::assertNotNull($violation);
        self::assertSame('timestamp_format_invalid', $violation->violationType);
        self::assertSame($act['message_id'], $violation->messageId);
        self::assertStringContainsString('timestamp', $violation->description);
    }

    public function testItReportsAMalformedExpiresAt(): void
    {
        $act = ProtocolFixtures::buyerAct(1, $this->session());
        $act['expires_at'] = '2026-09-11 10:00:00';

        $violation = $this->check([ActKey::for(1, ActRole::Buyer) => $act]);

        self::assertNotNull($violation);
        self::assertStringContainsString('expires_at', $violation->description);
    }

    public function testItIgnoresOurOwnActs(): void
    {
        $seller = ProtocolFixtures::sellerAct(1, $this->session());
        $seller['timestamp'] = '2026-09-10T09:42:07+00:00';

        // Our own acts go through ProtocolTimestamp; a check against them
        // would report our bug as the counterparty's misconduct.
        self::assertNull($this->check([ActKey::for(1, ActRole::Seller) => $seller]));
    }

    private function session(): string
    {
        return SessionId::forQuote(self::QUOTE_ID);
    }

    /** @param array<string, mixed> $acts */
    private function check(array $acts): ?\MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation
    {
        return (new TimestampFormatCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $this->session(), ...$acts]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );
    }
}
