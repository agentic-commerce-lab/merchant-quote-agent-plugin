<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Check\TimestampMonotonicityCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class TimestampMonotonicityCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesOnAnOrderedChain(): void
    {
        self::assertNull($this->check('2026-09-10T09:42:07Z', '2026-09-10T09:45:00Z'));
    }

    public function testItPassesOnEqualTimestamps(): void
    {
        // Two acts within the same second are not a causal problem.
        self::assertNull($this->check('2026-09-10T09:42:07Z', '2026-09-10T09:42:07Z'));
    }

    public function testItReportsTheInversionObservedInProduction(): void
    {
        // Buyer offer at 09:42:07, seller counteroffer 17 seconds EARLIER.
        $violation = $this->check('2026-09-10T09:42:07Z', '2026-09-10T09:41:50Z');

        self::assertNotNull($violation);
        self::assertSame('timestamp_inversion', $violation->violationType);
        self::assertStringContainsString('09:41:50Z', $violation->description);
    }

    public function testItAttributesTheInversionToTheLaterActInTheChain(): void
    {
        $violation = $this->check('2026-09-10T09:42:07Z', '2026-09-10T09:41:50Z');

        self::assertNotNull($violation);
        // Sequence 2 is the act that sits out of order, whoever wrote it.
        self::assertStringContainsString(':2', (string) $violation->messageId);
    }

    private function check(string $first, string $second): ?ProtocolViolation
    {
        $session = SessionId::forQuote(self::QUOTE_ID);

        $buyer = ProtocolFixtures::buyerAct(1, $session);
        $buyer['timestamp'] = $first;
        $seller = ProtocolFixtures::sellerAct(2, $session);
        $seller['timestamp'] = $second;

        return (new TimestampMonotonicityCheck())->check(
            ActChain::read([
                ActKey::SESSION_KEY => $session,
                ActKey::for(1, ActRole::Buyer) => $buyer,
                ActKey::for(2, ActRole::Seller) => $seller,
            ]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );
    }
}
