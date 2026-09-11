<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Http\RecordPartiesResolver;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\TestActSigner;
use PHPUnit\Framework\TestCase;

final class RecordPartiesResolverTest extends TestCase
{
    public function testTheInitiatorCarriesTheBuyersCompanyName(): void
    {
        $parties = $this->resolver()->resolve(
            [$this->buyerAct()],
            $this->quoteState(buyerOrganizationName: 'Nordwind Handel GmbH'),
        );

        self::assertSame('Nordwind Handel GmbH', $parties->initiator->organizationName);
    }

    public function testAnAccountWithNoCompanyKeepsTheBlank(): void
    {
        $parties = $this->resolver()->resolve([$this->buyerAct()], $this->quoteState());

        self::assertSame('', $parties->initiator->organizationName);
    }

    private function resolver(): RecordPartiesResolver
    {
        return new RecordPartiesResolver(TestActSigner::identities());
    }

    private function buyerAct(): Act
    {
        $act = Act::fromArray(ProtocolFixtures::buyerAct(1, SessionId::forQuote('11111111111111111111111111111111')));
        \assert($act !== null, description: 'Fixture act must be valid.');

        return $act;
    }

    private function quoteState(string $buyerOrganizationName = ''): QuoteTerminalState
    {
        return new QuoteTerminalState(
            state: 'replied',
            expired: false,
            quoteNumber: 'Q-1001',
            salesChannelId: ProtocolFixtures::SALES_CHANNEL_ID,
            acceptance: null,
            buyerOrganizationName: $buyerOrganizationName,
        );
    }
}
