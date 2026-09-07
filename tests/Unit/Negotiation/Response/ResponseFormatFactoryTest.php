<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Negotiation\Response\ResponseFormatFactory;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The schema is only useful if a provider accepts it. OpenAI and everything
 * that copied its structured-output API validate against JSON Schema 2020-12
 * and reject the whole request on a single malformed keyword, so what matters
 * here is the dialect, not just the field names.
 *
 * This exists because the unit suite passed while every extract call 400'd
 * against a real provider: `Assert\Positive` on
 * InterpretedProductAddition::$quantity made Symfony AI emit draft-04's
 * `exclusiveMinimum: true`, and nothing checked the emitted dialect.
 */
final class ResponseFormatFactoryTest extends TestCase
{
    /** Every numeric keyword 2020-12 defines as a number, so a bool is a bug. */
    private const NUMERIC_KEYWORDS = [
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',
        'minItems',
        'maxItems',
        'minLength',
        'maxLength',
        'minProperties',
        'maxProperties',
    ];

    /** @return iterable<string, array{0: class-string}> */
    public static function promptTargets(): iterable
    {
        yield 'extract' => [CommentInterpretation::class];
        yield 'negotiate' => [NegotiateResponse::class];
    }

    /** @param class-string $target */
    #[DataProvider('promptTargets')]
    public function testNoNumericKeywordCarriesABoolean(string $target): void
    {
        $schema = (new ResponseFormatFactory())->create($target)['json_schema']['schema'];

        self::assertSame([], self::booleanNumerics($schema));
    }

    /**
     * The concrete case: a positive-only integer must come out as 2020-12's
     * numeric `exclusiveMinimum`, with the draft-04 `minimum` companion gone.
     */
    public function testAPositiveConstraintBecomesANumericExclusiveBound(): void
    {
        $schema = (new ResponseFormatFactory())->create(CommentInterpretation::class)['json_schema']['schema'];
        $quantity = $schema['properties']['structural']['properties']['addProducts']['items']['properties']['quantity'];

        self::assertSame(0, $quantity['exclusiveMinimum'] ?? null);
        self::assertArrayNotHasKey('minimum', $quantity, 'draft-04 left its inclusive companion behind.');
    }

    /** The bounds that are genuinely inclusive must survive untouched. */
    public function testAnInclusiveRangeIsLeftAlone(): void
    {
        $schema = (new ResponseFormatFactory())->create(CommentInterpretation::class)['json_schema']['schema'];
        $discount = $schema['properties']['price']['properties']['additionalDiscountPercent'];

        self::assertSame(0, $discount['minimum'] ?? null);
        self::assertSame(100, $discount['maximum'] ?? null);
        self::assertArrayNotHasKey('exclusiveMinimum', $discount);
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return list<string>
     */
    private static function booleanNumerics(array $node, string $path = '$'): array
    {
        $offenders = [];

        foreach ($node as $key => $value) {
            if (\is_bool($value) && \in_array((string) $key, self::NUMERIC_KEYWORDS, strict: true)) {
                $offenders[] = $path . '.' . $key . ' = ' . var_export($value, true);
            }

            if (\is_array($value)) {
                $offenders = [...$offenders, ...self::booleanNumerics($value, $path . '.' . $key)];
            }
        }

        return $offenders;
    }
}
