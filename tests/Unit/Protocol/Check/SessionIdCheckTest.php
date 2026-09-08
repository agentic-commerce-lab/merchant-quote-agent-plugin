<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\SessionIdCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class SessionIdCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';
    private const SELLER = 'did:web:shop.example';

    public function testItPassesWhenTheChainDeclaresTheDerivedSession(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
        ]);

        $violation = (new SessionIdCheck())->check(
            $chain,
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            self::SELLER,
            ProtocolFixtures::at(),
        );

        self::assertNull($violation);
    }

    public function testItRefusesAForeignSessionId(): void
    {
        $chain = ActChain::read([
            ActKey::SESSION_KEY => 'not-ours',
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, 'not-ours'),
        ]);

        $violation = (new SessionIdCheck())->check(
            $chain,
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            self::SELLER,
            ProtocolFixtures::at(),
        );

        self::assertNotNull($violation);
        self::assertSame('session_id_mismatch', $violation->violationType);
        self::assertSame(ProtocolFixtures::buyerAct(1, 'not-ours')['message_id'], $violation->messageId);
        self::assertStringContainsString(SessionId::forQuote(self::QUOTE_ID), $violation->description);
    }
}
