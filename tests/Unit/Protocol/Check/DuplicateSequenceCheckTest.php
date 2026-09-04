<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\DuplicateSequenceCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class DuplicateSequenceCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesOnDistinctSequences(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
            ActKey::for(2, ActRole::Seller) => ProtocolFixtures::sellerAct(2, $session),
        ]);

        self::assertNull((new DuplicateSequenceCheck())->check(
            $chain,
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        ));
    }

    public function testItReportsASequenceClaimedTwice(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $chain = ActChain::read([
            ActKey::SESSION_KEY => $session,
            ActKey::for(1, ActRole::Buyer) => ProtocolFixtures::buyerAct(1, $session),
            ActKey::for(1, ActRole::Seller) => ProtocolFixtures::sellerAct(1, $session),
        ]);

        $violation = (new DuplicateSequenceCheck())->check(
            $chain,
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );

        self::assertNotNull($violation);
        self::assertSame('duplicate_sequence', $violation->violationType);
        // No single act is at fault, so no message id is attributed.
        self::assertNull($violation->messageId);
        self::assertStringContainsString('1', $violation->description);
    }
}
