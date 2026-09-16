<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\NegotiationRounds;
use PHPUnit\Framework\TestCase;

final class NegotiationRoundsTest extends TestCase
{
    public function testAQuoteWithNoCounterHasCompletedNoRounds(): void
    {
        self::assertSame(0, NegotiationRounds::completed(ServicingHandlerFixture::snapshot()));
        self::assertFalse(NegotiationRounds::exhausted(ServicingHandlerFixture::snapshot()));
    }

    public function testTheLastPassInsideTheCapStillNegotiates(): void
    {
        // The boundary, stated from both sides so an off-by-one cannot pass:
        // MAX - 1 completed passes means this quote has had 14 and may have a
        // 15th; MAX completed means the budget is spent.
        $snapshot = ServicingHandlerFixture::snapshot([
            NegotiationRounds::KEY => NegotiationRounds::MAX - 1,
        ]);

        self::assertSame(NegotiationRounds::MAX - 1, NegotiationRounds::completed($snapshot));
        self::assertFalse(NegotiationRounds::exhausted($snapshot));
    }

    public function testTheCapIsReachedAtTheConfiguredNumberOfPasses(): void
    {
        self::assertTrue(NegotiationRounds::exhausted(ServicingHandlerFixture::snapshot([
            NegotiationRounds::KEY => NegotiationRounds::MAX,
        ])));
    }

    public function testAQuoteDrivenPastTheCapStaysExhausted(): void
    {
        self::assertTrue(NegotiationRounds::exhausted(ServicingHandlerFixture::snapshot([
            NegotiationRounds::KEY => NegotiationRounds::MAX + 7,
        ])));
    }

    public function testAValueThatIsNotAnIntReadsAsNoRounds(): void
    {
        // customFields is a JSON column and nothing stops another writer
        // putting a string there. A count that is not a number is not a count,
        // and reading it as one would either crash the pass or cap a quote
        // that has negotiated nothing.
        foreach (['12', null, [], 3.5] as $stored) {
            self::assertSame(
                0,
                NegotiationRounds::completed(ServicingHandlerFixture::snapshot([NegotiationRounds::KEY => $stored])),
            );
        }
    }

    public function testIncrementReturnsTheStoredValuePlusOne(): void
    {
        self::assertSame(
            [NegotiationRounds::KEY => 4],
            NegotiationRounds::increment(ServicingHandlerFixture::snapshot([NegotiationRounds::KEY => 3])),
        );
        self::assertSame(
            [NegotiationRounds::KEY => 1],
            NegotiationRounds::increment(ServicingHandlerFixture::snapshot()),
        );
    }

    public function testTheCapIsTheNumberTheSpecArguedFor(): void
    {
        // Pinned on purpose: the value is a product decision with an argument
        // behind it (docs/superpowers/specs/2026-09-16-negotiation-round-cap-design.md),
        // not a number to tune casually.
        self::assertSame(15, NegotiationRounds::MAX);
    }
}
