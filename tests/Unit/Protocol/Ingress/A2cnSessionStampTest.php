<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Ingress\A2cnSessionStamp;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\RecordingQuoteGateway;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class A2cnSessionStampTest extends TestCase
{
    private const QUOTE_ID = '11111111111111111111111111111111';

    public function testItWritesTheDerivedSessionAndReturnsItOnTheSnapshot(): void
    {
        $gateway = new RecordingQuoteGateway();

        $stamped = (new A2cnSessionStamp(new NullLogger(), $gateway))->stamp($this->snapshot());

        self::assertCount(1, $gateway->updates);
        self::assertSame(
            [ActKey::SESSION_KEY => SessionId::forQuote(self::QUOTE_ID)],
            $gateway->updates[0]->customFields,
        );
        self::assertSame(SessionId::forQuote(self::QUOTE_ID), $stamped->toArray()['a2cn_session_id']);
    }

    public function testItLeavesTheSnapshotAloneWhenTheWriteFails(): void
    {
        // An id we advertise but cannot resolve back to a quote is a lie the
        // buyer would act on, so a failed stamp advertises nothing.
        $stamped = (new A2cnSessionStamp(new NullLogger(), new RecordingQuoteGateway(failOnUpdate: true)))->stamp(
            $this->snapshot(),
        );

        self::assertArrayNotHasKey('a2cn_session_id', $stamped->toArray());
    }

    public function testItIsInertWithoutAGateway(): void
    {
        $stamped = (new A2cnSessionStamp(new NullLogger()))->stamp($this->snapshot());

        self::assertArrayNotHasKey('a2cn_session_id', $stamped->toArray());
    }

    private function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            id: self::QUOTE_ID,
            quoteNumber: 'Q-1001',
            state: 'requested',
            expirationDate: null,
            currency: null,
            totalGross: null,
            totalNet: null,
            taxStatus: null,
            lineItems: [],
            comments: [],
        );
    }
}
