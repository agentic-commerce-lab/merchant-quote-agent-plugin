<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a servicing pass stamps AgentDisclosure::MARKER_KEY on the quote it
 * just handled.
 *
 * Split out of ServiceQuoteHandlerTest rather than appended to it: that class
 * was already at mago's too-many-methods threshold (see
 * testAHandoffIsRefusedWithoutRunningThePipeline's docblock there), so any new
 * method added to it fails the gate regardless of content.
 */
final class AgentDisclosureServicingTest extends TestCase
{
    /**
     * @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions
     *
     * The escalation case matters most here: it writes no buyer comment at all
     * on a sales channel with the notice switched off, and the buyer would
     * otherwise learn nothing about the agent's determination. HandedOver
     * means a human merchant was already on the quote and the agent wrote
     * nothing — disclosing there would tell the buyer an AI handled what a
     * person handled.
     */
    #[DataProvider('outcomes')]
    public function testAPassDisclosesOnlyWhenTheAgentActed(NegotiationOutcome $outcome, bool $expectDisclosed): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::countingPipeline($outcome);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);

        if ($expectDisclosed) {
            self::assertTrue($stamp[AgentDisclosure::MARKER_KEY] ?? false);
        } else {
            self::assertArrayNotHasKey(AgentDisclosure::MARKER_KEY, $stamp);
        }
    }

    /** @return iterable<string, array{NegotiationOutcome, bool}> */
    public static function outcomes(): iterable
    {
        yield 'offered' => [NegotiationOutcome::Offered, true];
        yield 'countered' => [NegotiationOutcome::Countered, true];
        yield 'clarified' => [NegotiationOutcome::Clarified, true];
        yield 'escalated' => [NegotiationOutcome::Escalated, true];
        yield 'handed over' => [NegotiationOutcome::HandedOver, false];
        yield 'nothing to do' => [NegotiationOutcome::NothingToDo, false];
    }

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testTheDisclosureMarkerIsNeverCleared(): void
    {
        // The two neighbouring markers are released by a pass that answered.
        // This one must survive exactly that pass, or a quote stops disclosing
        // the moment the agent succeeds on it.
        $gateway = new FakeQuoteGateway([
            ServicingHandlerFixture::snapshot([AgentDisclosure::MARKER_KEY => true]),
        ]);
        $pipeline = ServicingHandlerFixture::countingPipeline(NegotiationOutcome::Offered);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayNotHasKey(AgentDisclosure::MARKER_KEY, array_filter(
            $stamp,
            static fn(mixed $value): bool => $value === null,
        ));
    }
}
