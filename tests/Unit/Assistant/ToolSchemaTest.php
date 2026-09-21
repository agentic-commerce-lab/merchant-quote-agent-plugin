<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\QuoteStatusTool;
use MerchantQuoteAgentPlugin\Assistant\RequestQuoteTool;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;

/**
 * The JSON schema the model actually receives — not the docblock we think we
 * wrote.
 *
 * Those are two different artefacts, and the gap between them shipped two
 * defects nobody could see by reading the source. `symfony/property-info`
 * renders a `@param` into the schema only for shapes it can express; for
 * anything it cannot — `list<array{...}>`, `array<string, float>` — it drops
 * the shape AND silently discards the description with it. A parameter can
 * therefore reach the model with no description at all while its docblock
 * looks fully documented.
 *
 * That is exactly what happened to `targets`: it arrived as a nested array of
 * `number|string` with no description, so a model could not use it, fell back
 * to writing the price ask into the free-text comment, and the whole
 * structured price path was dead on arrival without a single failing test.
 *
 * So these assertions are about the schema, not the source. Read the built
 * tool, not the file.
 */
final class ToolSchemaTest extends TestCase
{
    /**
     * The comment is what the merchant reads on the quote, so the schema must
     * ask for a message addressed to THEM.
     *
     * It previously said "the shopper's own words", and a live model did
     * exactly that: a shopper who typed "Request a quote based on my cart, for
     * 10% discount" — an instruction to the assistant — had that sentence
     * persisted verbatim as the quote's comment, so the merchant's quote view
     * read like a chat log.
     */
    public function testTheCommentAsksForAMessageToTheMerchantRatherThanATranscript(): void
    {
        $comment = self::schema(RequestQuoteTool::class)['properties']['comment'];

        self::assertSame('string', $comment['type']);
        self::assertStringContainsString('shop', $comment['description']);
        self::assertStringNotContainsString("shopper's own words", $comment['description']);
    }

    /**
     * Whatever figure the shopper stated has to survive into the comment
     * unchanged, because `Negotiation\AskInterpreter` reads this text as an ask
     * channel — a rephrasing that turns "10% off" into "a small discount"
     * silently drops the buyer's ask on the floor.
     */
    public function testTheCommentSchemaRequiresAStatedFigureToBeCarriedOver(): void
    {
        $comment = self::schema(RequestQuoteTool::class)['properties']['comment'];

        self::assertMatchesRegularExpression('/exact|verbatim|unchanged/i', $comment['description']);
    }

    /**
     * Both target parameters must be flat lists. A nested or keyed shape is
     * not merely uglier — property-info cannot express it, so the description
     * disappears and the model is handed an undocumented parameter.
     */
    public function testTheTargetParametersAreFlatListsThatKeepTheirDescriptions(): void
    {
        $properties = self::schema(RequestQuoteTool::class)['properties'];

        self::assertSame('array', $properties['targetProductIds']['type']);
        self::assertSame('string', $properties['targetProductIds']['items']['type']);
        self::assertNotEmpty($properties['targetProductIds']['description']);

        self::assertSame('array', $properties['targetUnitPrices']['type']);
        self::assertSame('number', $properties['targetUnitPrices']['items']['type']);
        self::assertNotEmpty($properties['targetUnitPrices']['description']);
    }

    /** Every parameter the model can see carries a description, or it cannot use it correctly. */
    public function testEveryParameterOfEveryToolIsDescribed(): void
    {
        foreach ([RequestQuoteTool::class, QuoteStatusTool::class] as $tool) {
            foreach (self::schema($tool)['properties'] as $name => $property) {
                self::assertNotEmpty($property['description'] ?? '', \sprintf(
                    '%s::%s reaches the model with no description',
                    $tool,
                    $name,
                ));
            }
        }
    }

    /**
     * @return array{properties: array<string, array<string, mixed>>}
     */
    private static function schema(string $tool): array
    {
        foreach ((new ReflectionToolFactory())->getTool($tool) as $built) {
            /** @var array{properties: array<string, array<string, mixed>>} $parameters */
            $parameters = $built->getParameters();

            return $parameters;
        }

        self::fail($tool . ' produced no tool definition');
    }
}
