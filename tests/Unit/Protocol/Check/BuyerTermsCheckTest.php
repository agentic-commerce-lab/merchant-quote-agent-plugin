<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Check;

use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\BuyerTermsCheck;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;

final class BuyerTermsCheckTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItPassesWhenTheActDescribesTheRecordedQuote(): void
    {
        self::assertNull(self::violationFor(quantityOnAct: 10, quantityOnQuote: 10));
    }

    public function testItReportsAQuantityTheQuoteDoesNotRecord(): void
    {
        $violation = self::violationFor(quantityOnAct: 25, quantityOnQuote: 10);

        self::assertNotNull($violation);
        self::assertSame('act_terms_mismatch', $violation->violationType);
        self::assertStringContainsString('25', $violation->description);
        self::assertStringContainsString('10', $violation->description);
    }

    public function testItReportsALineTheQuoteDoesNotHave(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = ProtocolFixtures::buyerAct(1, $session);
        $act['terms']['line_items'][0]['id'] = 'line-ghost';

        $violation = (new BuyerTermsCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => $act]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );

        self::assertNotNull($violation);
        self::assertStringContainsString('line-ghost', $violation->description);
    }

    public function testItIgnoresOurOwnActs(): void
    {
        // Our own act legitimately carries the terms we are about to offer,
        // which need not equal what is on the quote yet.
        $session = SessionId::forQuote(self::QUOTE_ID);
        $ours = ProtocolFixtures::sellerAct(1, $session);
        $ours['terms']['line_items'][0]['quantity'] = 999;

        self::assertNull((new BuyerTermsCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Seller) => $ours]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        ));
    }

    public function testItIgnoresPriceDifferences(): void
    {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = ProtocolFixtures::buyerAct(1, $session);
        $act['terms']['line_items'][0]['unit_price'] = 1;
        $act['terms']['total_value'] = 1;

        self::assertNull((new BuyerTermsCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => $act]),
            ProtocolFixtures::snapshot(self::QUOTE_ID),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        ));
    }

    private static function violationFor(
        int $quantityOnAct,
        int $quantityOnQuote,
    ): ?\MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation {
        $session = SessionId::forQuote(self::QUOTE_ID);
        $act = ProtocolFixtures::buyerAct(1, $session);
        $act['terms']['line_items'][0]['quantity'] = $quantityOnAct;

        return (new BuyerTermsCheck())->check(
            ActChain::read([ActKey::SESSION_KEY => $session, ActKey::for(1, ActRole::Buyer) => $act]),
            ProtocolFixtures::snapshot(self::QUOTE_ID, quantity: $quantityOnQuote),
            ProtocolFixtures::SELLER,
            ProtocolFixtures::at(),
        );
    }
}
