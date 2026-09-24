<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
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

    /**
     * @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions
     *
     * In Draft Mode a pass that drafted a reply is withheld: the merchant
     * reviews and sends what the buyer reads. An escalation drafts nothing
     * and still discloses, exactly as outside Draft Mode.
     */
    #[DataProvider('draftModeOutcomes')]
    public function testADraftModePassDisclosesOnlyWhatItDidNotDraft(
        NegotiationOutcome $outcome,
        bool $expectDisclosed,
    ): void {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $base = ServicingSettingsFixture::settings();
        $settings = new QuoteAgentSettings($base->policy, $base->llm, null, draftMode: true);

        ServicingHandlerFixture::handler(
            $gateway,
            ServicingHandlerFixture::countingPipeline($outcome),
            preflight: ServicingSettingsFixture::preflightReturning($settings),
        )(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertSame($expectDisclosed, $stamp[AgentDisclosure::MARKER_KEY] ?? false);
    }

    /** @return iterable<string, array{NegotiationOutcome, bool}> */
    public static function draftModeOutcomes(): iterable
    {
        yield 'offered - drafted' => [NegotiationOutcome::Offered, false];
        yield 'countered - drafted' => [NegotiationOutcome::Countered, false];
        yield 'clarified - drafted' => [NegotiationOutcome::Clarified, false];
        yield 'escalated - nothing drafted, still disclosed' => [NegotiationOutcome::Escalated, true];
    }

    /**
     * @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions
     *
     * The two neighbouring markers are released by a pass that did not act.
     * This one must survive exactly that pass, or a quote already marked
     * handled stops disclosing the moment the agent has nothing further to do
     * on it. HandedOver is the outcome to seed this with, not Offered: a
     * quote is only ever already marked while an outcome that itself
     * discloses (Offered included) would stamp the key back in regardless,
     * which is what made the previous version of this test pass no matter
     * what stampFor() returned.
     *
     * The real invariant is "already marked + a non-acting outcome ⇒ the key
     * is OMITTED from the fragment, not nulled" — QuoteWriter's shallow merge
     * means an omitted key leaves the existing customField untouched, while a
     * present-but-null one would clear it.
     */
    public function testTheDisclosureMarkerIsNeverCleared(): void
    {
        $gateway = new FakeQuoteGateway([
            ServicingHandlerFixture::snapshot([AgentDisclosure::MARKER_KEY => true]),
        ]);
        $pipeline = ServicingHandlerFixture::countingPipeline(NegotiationOutcome::HandedOver);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayNotHasKey(AgentDisclosure::MARKER_KEY, $stamp);
    }
}
