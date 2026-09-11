<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Http\InboundActPayload;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InboundActPayloadTest extends TestCase
{
    public function testItExtractsAValidActFromRequest(): void
    {
        $raw = ProtocolFixtures::buyerAct(1, '0191c95b-7b3b-7f8e-a342-6e2759e6c46a');
        $request = Request::create('/', 'POST', content: (string) json_encode($raw));

        $act = InboundActPayload::from($request);

        self::assertInstanceOf(Act::class, $act);
        self::assertSame($raw['message_id'], $act->messageId());
    }

    public function testItReturnsNullForInvalidJson(): void
    {
        $request = Request::create('/', 'POST', content: 'not valid json');

        self::assertNull(InboundActPayload::from($request));
    }

    public function testItReturnsNullForJsonScalar(): void
    {
        $request = Request::create('/', 'POST', content: (string) json_encode('a scalar string'));

        self::assertNull(InboundActPayload::from($request));
    }

    public function testItReturnsNullForArrayMissingActFields(): void
    {
        $request = Request::create('/', 'POST', content: (string) json_encode(['foo' => 'bar']));

        self::assertNull(InboundActPayload::from($request));
    }
}
