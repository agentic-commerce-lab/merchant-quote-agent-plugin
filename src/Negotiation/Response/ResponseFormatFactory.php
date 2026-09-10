<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use Override;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory as PlatformResponseFormatFactory;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactoryInterface;

/**
 * Symfony AI's schema generator, with exclusive numeric bounds rewritten from
 * JSON Schema draft-04 to 2020-12.
 *
 * ValidatorConstraintsDescriber emits draft-04 for every exclusive comparison
 * constraint — `Assert\Positive`, `Assert\Negative`, `Assert\GreaterThan`,
 * `Assert\LessThan` — as a boolean flag beside an inclusive bound:
 *
 *     {"type": "integer", "minimum": 0, "exclusiveMinimum": true}
 *
 * In 2020-12, which is what OpenAI and the providers that copied its
 * structured-output API validate against, `exclusiveMinimum` is the bound
 * ITSELF and must be a number. A boolean there fails the entire request with
 * `Invalid schema for response_format: True is not of type 'number'`, so the
 * agent escalates every quote for a reason no log line explains:
 *
 *     {"type": "integer", "exclusiveMinimum": 0}
 *
 * The rewrite lives here rather than in Policy\Data because the constraint is
 * right: `InterpretedProductAddition::$quantity` genuinely must be positive,
 * and dropping the attribute to please a schema generator would take the
 * validation with it. Applied to every node, so a constraint added to any DTO
 * later is covered without anyone remembering this exists.
 *
 * Also admits `null` into every `enum` whose `type` permits it. `enum` is an
 * absolute whitelist in JSON Schema regardless of `type`, and Symfony AI
 * derives a nullable `type` from a nullable backed-enum property without ever
 * adding `null` to the `enum` list beside it. Left alone, a provider that
 * enforces the schema strictly cannot accept "unset" for that property at
 * all: the property is required and every legal value names something.
 *
 * Transformations are delegated to ExclusiveBound and AdmitNullInEnum to
 * keep this class inside the complexity gate.
 */
final readonly class ResponseFormatFactory implements ResponseFormatFactoryInterface
{
    /** Exclusive keyword => the inclusive one draft-04 pairs it with. */
    private const EXCLUSIVE_BOUNDS = [
        'exclusiveMinimum' => 'minimum',
        'exclusiveMaximum' => 'maximum',
    ];

    public function __construct(
        private ResponseFormatFactoryInterface $inner = new PlatformResponseFormatFactory(),
    ) {}

    #[Override]
    public function create(string $responseClass): array
    {
        $format = $this->inner->create($responseClass);
        $schema = $format['json_schema']['schema'] ?? null;

        if (\is_array($schema)) {
            // The recursion legitimately walks list nodes too (`required`,
            // `type`), so it is typed on array-key; a JSON Schema object node
            // is string-keyed by construction.
            /** @var array<string, mixed> $rewritten */
            $rewritten = self::draft202012($schema);
            $format['json_schema']['schema'] = $rewritten;
        }

        return $format;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private static function draft202012(array $node): array
    {
        foreach (self::EXCLUSIVE_BOUNDS as $exclusive => $inclusive) {
            $node = ExclusiveBound::rewrite($node, $exclusive, $inclusive);
        }

        $node = AdmitNullInEnum::rewrite($node);

        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                $node[$key] = self::draft202012($value);
            }
        }

        return $node;
    }
}
