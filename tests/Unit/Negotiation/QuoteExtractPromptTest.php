<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

/**
 * Issue #167: every escalation in the 2026-09-18 session was a buyer stating a
 * quote-level BUDGET ("2,500 for the whole quote", "3,500 across the items",
 * "9,000 EUR budget"). `price.targetTotal` (#164) exists to hold exactly that,
 * but the shipped rule for structural.lineChanges told the model "If a
 * quote-level ask cannot be mapped to exactly one line, use
 * clarificationQuestions" — wording that a budget phrased as being spread
 * "across the items" satisfies just as well as it satisfies the
 * price.targetTotal rule above it, so the model asked how to split the
 * budget instead of using the field #164 built for it.
 *
 * This pins the corrected wording so the trap cannot silently return.
 */
final class QuoteExtractPromptTest extends TestCase
{
    private static function prompt(): string
    {
        $prompt = file_get_contents(__DIR__ . '/../../../config/agents/quote-extract-agent.prompt.md');
        self::assertIsString($prompt);

        return $prompt;
    }

    public function testAWholeQuoteBudgetIsNeverRoutedToClarificationForNamingMultipleItems(): void
    {
        $prompt = self::prompt();

        self::assertStringContainsString('use clarificationQuestions instead — but a budget or', $prompt);
        self::assertStringContainsString(
            'figure for the quote as a whole is price.targetTotal, never a reason to ask',
            $prompt,
        );
        self::assertStringNotContainsString(
            'If a quote-level ask cannot be mapped to exactly one line, use',
            $prompt,
            'This exact phrase read a whole-quote budget as a per-line ask it could not place, and sent a '
            . 'clarifying question instead of using price.targetTotal.',
        );
    }

    public function testTargetTotalExplicitlyCoversABudgetSpreadAcrossItems(): void
    {
        // The rule the 2026-09-18 escalations needed: a budget the buyer
        // describes spreading over the items is still one quote-level number,
        // and asking how to split it costs the buyer a round for nothing.
        $prompt = self::prompt();

        self::assertStringContainsString('NEVER ask the buyer how a', $prompt);
        self::assertStringContainsString('budget should be split across the items', $prompt);
    }

    public function testABareNumberAmbiguousBetweenTotalAndUnitPriceIsStillClarificationWorthy(): void
    {
        // "60" on a 50-unit single-line quote is genuinely
        // ambiguous between the total and a per-unit price, and #167 keeps
        // that a legitimate clarificationQuestions case rather than routing
        // it to price.targetTotal by default.
        $prompt = self::prompt();

        // Phrased as the level rule's own escape hatch: a number that fits no
        // level, or sits between two lines with no wording to settle it.
        self::assertStringContainsString('Only ask when the number fits NO level', $prompt);
    }
}
