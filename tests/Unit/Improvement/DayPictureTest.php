<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Improvement\DayPicture;
use MerchantQuoteAgentPlugin\Improvement\DecisionClassification;
use MerchantQuoteAgentPlugin\Improvement\DecisionDiscount;
use MerchantQuoteAgentPlugin\Improvement\DecisionExtraction;
use MerchantQuoteAgentPlugin\Improvement\HarvestedDecision;
use PHPUnit\Framework\TestCase;

final class DayPictureTest extends TestCase
{
    public function testItCarriesNoBuyerText(): void
    {
        $escalated = $this->decision(band: 'escalate', outcome: 'escalated', granted: null);
        $granted = $this->decision(band: 'grant', outcome: 'offer_sent', granted: 7.5);

        $described = DayPicture::of([$escalated, $granted])->describe();

        // Aggregates only. raw_proposal and the reply are free text written by
        // a model whose prompt carried the buyer's message verbatim --
        // AnonymizedDecision already treats them as carrying buyer data, and
        // this prompt must not undo that.
        self::assertStringNotContainsString('raw', $described);
        self::assertStringContainsString('escalate', $described);
        self::assertStringContainsString('2', $described);

        // Stronger than the brief's own test: every decision() fixture below
        // carries a quoteId, decisionId, extractPromptHash and an
        // interpretedAsks payload built to LOOK like buyer text (a name, a
        // phone number, a street). None of the ten fields HarvestedDecision
        // carries reach describe() except the four closed-vocabulary ones and
        // the two discount numbers -- prove that by asserting every planted
        // identifying string is absent, not just the literal word "raw".
        foreach ([$escalated, $granted] as $decision) {
            self::assertStringNotContainsString($decision->decisionId, $described);
            self::assertStringNotContainsString($decision->quoteId, $described);
            self::assertStringNotContainsString((string) $decision->extractPromptHash, $described);
        }

        foreach (['Jane Doe', '+1-555-0100', '221B Baker Street'] as $buyerText) {
            self::assertStringNotContainsString($buyerText, $described);
        }
    }

    public function testItCountsBandsEscalationReasonsAndTerminalStates(): void
    {
        $picture = DayPicture::of([
            $this->decision(
                band: 'grant',
                outcome: 'offer_sent',
                granted: 5.0,
                escalationReason: null,
                terminalState: 'accepted',
            ),
            $this->decision(
                band: 'escalate',
                outcome: 'escalated',
                granted: null,
                escalationReason: 'proposal_rejected',
                terminalState: null,
            ),
        ]);

        $described = $picture->describe();

        self::assertStringContainsString('grant=1', $described);
        self::assertStringContainsString('escalate=1', $described);
        self::assertStringContainsString('proposal_rejected=1', $described);
        self::assertStringContainsString('accepted=1', $described);
        // The authorizer-rejection count is called out on its own line, not
        // only buried in the escalation-reason tally.
        self::assertMatchesRegularExpression('/[Aa]uthorizer.*1/', $described);
    }

    public function testItSummarizesTheDiscountSpreadAgainstTheCap(): void
    {
        $picture = DayPicture::of([
            $this->decision(band: 'grant', outcome: 'offer_sent', granted: 10.0),
            $this->decision(band: 'grant', outcome: 'offer_sent', granted: 4.0),
            $this->decision(band: 'escalate', outcome: 'escalated', granted: null),
        ]);

        $described = $picture->describe();

        self::assertStringContainsString('2 grant', $described);
        self::assertStringContainsString('1 at or above', $described);
    }

    public function testAnEmptyWindowDescribesWithoutErrors(): void
    {
        $described = DayPicture::of([])->describe();

        self::assertStringContainsString('0 decisions', $described);
    }

    /** Every fixture's own cap: 10.0, which is what makes a granted 10.0 read as "at cap" in the discount-spread test. */
    private const FIXTURE_MAX_DISCOUNT_PERCENT = 10.0;

    private function decision(
        ?string $band,
        ?string $outcome,
        ?float $granted,
        ?string $escalationReason = null,
        ?string $terminalState = null,
    ): HarvestedDecision {
        static $n = 0;
        ++$n;

        return new HarvestedDecision(
            decisionId: 'DECISION-' . $n,
            quoteId: 'Q-' . (10041 + $n),
            classification: new DecisionClassification($band, $outcome, $escalationReason, $terminalState),
            discount: new DecisionDiscount($granted, self::FIXTURE_MAX_DISCOUNT_PERCENT),
            extraction: new DecisionExtraction(interpretedAsks: ['humanReviewRequests' => [
                'Jane Doe, +1-555-0100, 221B Baker Street',
            ]], extractPromptHash: 'sha256:not-a-real-hash'),
        );
    }
}
