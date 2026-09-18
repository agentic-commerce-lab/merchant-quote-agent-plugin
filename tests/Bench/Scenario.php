<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

/**
 * One synthetic customer's negotiation, as JSON.
 *
 * This format is a deliverable, not an implementation detail: issue #22's
 * nightly replay loop and issue #21's buyer-agent handshake both consume it,
 * so it is designed once, here.
 *
 * `maxRounds` is required with no default. Issue #142's round cap was closed
 * unmerged as PR #148 — there is no cap in src/ on this branch — so the
 * bench's own bound is the only thing that stops a non-converging
 * negotiation from burning a real API budget.
 *
 * `productRef` (inside `lines`) is a symbolic name the runner resolves
 * against whichever shop it targets, deliberately not a product UUID, so a
 * scenario ports between shops.
 *
 * `requestedUnitPrice` (inside `lines`) is optional: a per-line ask the
 * buyer typed into the storefront's own price field, with no comment about
 * it at all. Carries no Net/Gross suffix on purpose -- see ScenarioLines
 * for the unit it actually lands in on the quote. `structured-only` exists
 * to carry one -- see BenchNegotiation, which maps it onto requestQuote()'s
 * `requested_unit_price` only when present, never as an explicit null.
 */
final readonly class Scenario
{
    public string $id;

    public string $description;

    /** @var list<array{productRef: string, quantity: int, requestedUnitPrice: ?float}> */
    public array $lines;

    public string $openingAsk;

    public string $persona;

    public int $maxRounds;

    public ?string $expectedBand;

    /**
     * @param array{
     *     id: string,
     *     description: string,
     *     lines: list<array{productRef: string, quantity: int, requestedUnitPrice: ?float}>,
     *     openingAsk: string,
     *     persona: string,
     *     maxRounds: int,
     *     expectedBand: ?string,
     * } $fields
     */
    private function __construct(array $fields)
    {
        $this->id = $fields['id'];
        $this->description = $fields['description'];
        $this->lines = $fields['lines'];
        $this->openingAsk = $fields['openingAsk'];
        $this->persona = $fields['persona'];
        $this->maxRounds = $fields['maxRounds'];
        $this->expectedBand = $fields['expectedBand'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self([
            'id' => ScenarioFields::string($data, 'id'),
            'description' => ScenarioFields::string($data, 'description'),
            'lines' => ScenarioLines::from($data, 'lines'),
            'openingAsk' => ScenarioFields::string($data, 'openingAsk', allowEmpty: true),
            'persona' => ScenarioFields::string($data, 'persona'),
            'maxRounds' => ScenarioFields::maxRounds($data),
            'expectedBand' => ScenarioFields::optionalString($data, 'expectedBand'),
        ]);
    }

    /** @throws \InvalidArgumentException naming the field and, on failure, the file */
    public static function load(string $path): self
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException(\sprintf('Scenario file "%s" could not be read.', $path));
        }

        try {
            $data = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                \sprintf('Scenario file "%s" is not valid JSON: %s', $path, $e->getMessage()),
                previous: $e,
            );
        }

        if (!\is_array($data)) {
            throw new \InvalidArgumentException(\sprintf('Scenario file "%s" must decode to a JSON object.', $path));
        }

        try {
            return self::fromArray($data);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(\sprintf('%s: %s', $path, $e->getMessage()), previous: $e);
        }
    }

    /**
     * Every `*.json` file directly inside $directory, sorted by filename so
     * a run is reproducible.
     *
     * @return list<self>
     */
    public static function all(string $directory): array
    {
        $paths = glob(rtrim($directory, '/') . '/*.json');
        if ($paths === false) {
            throw new \InvalidArgumentException(\sprintf('Scenario directory "%s" could not be read.', $directory));
        }

        sort($paths);

        return array_map(self::load(...), $paths);
    }
}
