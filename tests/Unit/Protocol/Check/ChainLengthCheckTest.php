<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\ChainLengthCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class ChainLengthCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItReportsAChainLongerThanWeWillRead(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $fields = [ActKey::SESSION_KEY => $session];
        for ($sequence = 1; $sequence <= (ActChain::MAX_ACTS + 1); ++$sequence) {
            $fields[ActKey::for($sequence, ActRole::Buyer)] = ProtocolFixtures::buyerAct($sequence, $session);
        }

        $violation = (new ChainLengthCheck())->check(
            ActChain::read($fields),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );

        self::assertNotNull($violation);
        self::assertSame('chain_length_exceeded', $violation->violationType);
    }
}
