<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The marker is what the storefront banner reads. Its key is a published
 * contract with a Twig template that cannot import the constant, so the
 * literal is pinned here.
 */
final class AgentDisclosureTest extends TestCase
{
    public function testTheKeyIsTheOneTheStorefrontTemplateReads(): void
    {
        // The Twig banner reads this literal. Changing the constant without
        // changing the template is a silent no-op on the buyer's page.
        self::assertSame('merchant_quote_agent_handled', AgentDisclosure::MARKER_KEY);
    }

    /**
     * Every case listed explicitly rather than derived, so a case added to the
     * enum later fails this test instead of silently inheriting a default.
     * Which side it belongs on is a decision about what the buyer is told.
     *
     * @return iterable<string, array{NegotiationOutcome, bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'offered - the agent made an offer' => [NegotiationOutcome::Offered, true];
        yield 'countered - the agent countered' => [NegotiationOutcome::Countered, true];
        yield 'clarified - the agent asked the buyer a question' => [NegotiationOutcome::Clarified, true];
        // Included even where the buyer notice is off and the buyer sees no
        // agent message: the agent still made a determination on their quote.
        yield 'escalated - the agent decided to hand off' => [NegotiationOutcome::Escalated, true];
        // The agent put a comment in front of the buyer.
        yield 'acknowledged - the agent restated the quote' => [NegotiationOutcome::Acknowledged, true];
        // A human was already on the quote and the agent wrote nothing.
        // Disclosing here would tell the buyer an AI handled what a person did.
        yield 'handed over - a human was already handling it' => [NegotiationOutcome::HandedOver, false];
        yield 'nothing to do - the agent did not act' => [NegotiationOutcome::NothingToDo, false];
    }

    #[DataProvider('outcomes')]
    public function testOnlyAnOutcomeWhereTheAgentActedDisclosesIt(NegotiationOutcome $outcome, bool $expected): void
    {
        $expectedFragment = $expected ? [AgentDisclosure::MARKER_KEY => true] : [];

        self::assertSame($expectedFragment, AgentDisclosure::stampFor($outcome));
    }

    public function testEveryEnumCaseIsCovered(): void
    {
        // The provider is hand-written so a new case fails rather than
        // defaulting. This is what makes that failure happen.
        $covered = array_map(
            static fn(array $case): NegotiationOutcome => $case[0],
            iterator_to_array(self::outcomes(), false),
        );

        self::assertEqualsCanonicalizing(NegotiationOutcome::cases(), $covered);
    }
}
