<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteValueCeiling;
use MerchantQuoteAgentPlugin\Strategy\BuiltInStrategies;
use MerchantQuoteAgentPlugin\Tests\Bench\LlmBuyer;
use MerchantQuoteAgentPlugin\Tests\Bench\Scenario;
use MerchantQuoteAgentPlugin\Tests\Bench\ScriptedBuyer;
use MerchantQuoteAgentPlugin\Tests\Bench\SyntheticBuyer;

/**
 * The bench. Opt-in: it makes real model calls against a live shop and leaves
 * its decision records behind. Never gate CI on this.
 *
 * Iterates scenarios (`tests/Bench/scenarios/*.json` -- shop-free data lives
 * under `tests/Bench/` per Ruling A1, and Task 2's `ScenarioTest` already
 * reads that directory; a scenario missing from it fails the "no scenarios
 * found" assertion below rather than silently running zero cells) x the three
 * built-in strategies x every model in `QUOTE_AGENT_BENCH_MODELS`. Each
 * strategy's current version id is resolved from
 * `merchant_quote_agent_strategy_version` so every decision row this run
 * leaves behind carries it -- a row without one groups as "Unattributed" and
 * Track B's admin table has nothing to group. A cell that throws (an
 * unreachable model, a scenario that cannot convert) is recorded as a failure
 * and does not stop the remaining cells; only every cell failing fails this
 * test outright, since that means the harness itself is broken rather than
 * one provider being down.
 *
 * One JSONL object per decision-record pass this run produced is appended to
 * `var/bench/<runId>.jsonl` (git-ignored -- run output is never committed),
 * field names matching the decision record's own, plus `runId`, `scenarioId`
 * and `round`, plus `orderId`/`orderFailure` so a cell that never tried to
 * convert is distinguishable from one that tried and failed.
 *
 * A cell that throws before producing any decision row (an unreachable
 * model, a scenario that cannot convert) still leaves exactly one line
 * behind: a failure row carrying `cellFailure: true`, `runId`, `scenarioId`,
 * the cell's own `strategyVersionId` and `model`, and the throwable's class
 * and message. Without it that cell vanishes from the JSONL entirely, and a
 * scorer reading the file cannot tell "nothing attempted this model" from
 * "this model failed every time" -- exactly the gap that matters most when
 * one model in the matrix is the one that is down.
 *
 *   QUOTE_AGENT_BENCH_KEY=sk-... \
 *   QUOTE_AGENT_BENCH_MODELS=google/gemini-3.7-flash,openai/gpt-5-mini \
 *   QUOTE_AGENT_BENCH_BUYER=scripted \
 *   SHOP_SSH=user@host SHOP_PATH=/abs/docroot \
 *     composer run test:integration -- --filter BenchRunTest
 *
 * `QUOTE_AGENT_BENCH_BASE_URL` overrides the OpenRouter default
 * (https://openrouter.ai/api/v1) that `QUOTE_AGENT_BENCH_MODELS`' "vendor/model"
 * naming implies. `QUOTE_AGENT_BENCH_BUYER` is "scripted" (default: free,
 * deterministic, the CI-safe regression path) or "llm" (costs a second model
 * call per round, over the SAME model and key as the cell under test -- there
 * is no separate buyer-model knob; see LlmBuyer for why a swapped buyer model
 * was cut from this spec entirely).
 *
 * Cost: for `scripted`, one API call per round for the negotiating model
 * under test (three for the first round -- extract, negotiate, reply -- one
 * for each round after, per `ModelPlatform`'s own docblock); for `llm`, one
 * more on top of that per round for the synthetic buyer. Multiplied by every
 * scenario x strategy x model cell.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The matrix driver IS the orchestration of scenario x strategy x model, plus
 * env parsing and the two harness-precondition assertions (Ruling A6: every
 * assertion here must be able to fail, which is why there are several rather
 * than one). `DecisionRowMapper` already carries the row-shaping branching
 * out of this class; splitting the orchestration itself further would
 * scatter one control flow across multiple classes for no reader's benefit.
 */
final class BenchRunTest extends BenchTestCase
{
    private const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';

    public function testRunsTheScenarioStrategyModelMatrix(): void
    {
        $env = self::requireEnv();

        $container = static::getContainer();
        $connection = self::connection($container);
        $platform = $container->get(ModelPlatform::class);
        self::assertInstanceOf(ModelPlatform::class, $platform);

        $scenarios = Scenario::all(\dirname(__DIR__, levels: 2) . '/Bench/scenarios');
        self::assertNotEmpty(
            $scenarios,
            'No scenarios found under tests/Bench/scenarios -- seed at least one *.json file first (Task 7).',
        );

        $strategyVersions = self::resolveStrategyVersions($connection);

        $runId = self::newRunId();
        $config = new BenchRunConfig($env['apiKey'], $env['baseUrl'], $env['buyerKind'], $platform, $runId);
        $writer = new RunWriter(self::runPath($runId));
        $bench = new BenchNegotiation($container, self::gateway(), self::buyerGateway(), $platform);

        $attempted = 0;
        $written = 0;
        $cellFailures = [];

        foreach ($scenarios as $scenario) {
            foreach ($strategyVersions as $strategyId => $strategyVersionId) {
                foreach ($env['models'] as $model) {
                    $attempted++;
                    $cell = new BenchCell($scenario, $strategyId, $strategyVersionId, $model, $config);
                    $outcome = self::attemptCell($bench, $connection, $writer, $cell);
                    $written += $outcome->written;
                    $cellFailures[] = $outcome->failure;
                }
            }
        }

        $writer->close();
        $failures = array_values(array_filter($cellFailures));

        if ($failures !== []) {
            // A partial failure is a legitimate bench finding, not a harness
            // bug -- printed for visibility, not asserted to zero, per the
            // brief's "a failed cell must not end the matrix".
            fwrite(\STDERR, sprintf(
                "%d/%d bench cells failed:\n%s\n",
                \count($failures),
                $attempted,
                implode("\n", $failures),
            ));
        }

        self::assertGreaterThan(
            0,
            $attempted - \count($failures),
            sprintf(
                "Every one of %d bench cells failed -- the harness itself is broken, not one unreachable model:\n%s",
                $attempted,
                implode("\n", $failures),
            ),
        );

        $lines = file(self::runPath($runId), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines, 'The run file must be readable back after writing it.');
        self::assertCount(
            $written,
            $lines,
            'The JSONL must carry exactly one line per decision row or cell failure this run wrote.',
        );
    }

    /**
     * The one line the whole bench rests on: `CellSettings::for()` must hand
     * each cell ITS OWN strategy prompt, ITS OWN version id and ITS OWN
     * model -- not a shared default. If this regresses, the run still
     * completes and the JSONL still fills, so nothing else here would catch
     * it; every decision row would just group as "Unattributed" in the
     * admin, silently. Deliberately not gated behind requireEnv(): a guard
     * against a silent failure must not itself be silent about running.
     */
    public function testCellSettingsCarryThatCellsOwnStrategyVersionAndModel(): void
    {
        $platform = static::getContainer()->get(ModelPlatform::class);
        self::assertInstanceOf(ModelPlatform::class, $platform);

        // Never reached over the network -- CellSettings::for() only builds
        // an object, it makes no call.
        $config = new BenchRunConfig(
            'sk-fake-for-this-test',
            'https://example.invalid/v1',
            'scripted',
            $platform,
            'run-fake',
        );
        $scenario = Scenario::fromArray([
            'id' => 'settings-wiring-check',
            'description' => 'Fixture only -- CellSettings::for() never makes a network call.',
            'lines' => [['productRef' => 'any-purchasable', 'quantity' => 1]],
            'openingAsk' => 'n/a',
            'persona' => 'n/a',
            'maxRounds' => 1,
        ]);

        $marginDefenderId = BuiltInStrategies::MARGIN_DEFENDER;
        $marginDefenderVersionId = str_repeat('a', 32);
        $marginDefenderCell = new BenchCell($scenario, $marginDefenderId, $marginDefenderVersionId, 'model-a', $config);

        $fastCloseId = BuiltInStrategies::FAST_CLOSE;
        $fastCloseVersionId = str_repeat('b', 32);
        $fastCloseCell = new BenchCell($scenario, $fastCloseId, $fastCloseVersionId, 'model-b', $config);

        $marginDefenderSettings = CellSettings::for($marginDefenderCell);
        $fastCloseSettings = CellSettings::for($fastCloseCell);

        // Non-null and each cell's OWN id -- two different cells must not
        // collapse onto the same version, which a hardcoded or defaulted
        // value would do.
        self::assertSame($marginDefenderVersionId, $marginDefenderSettings->strategyVersionId);
        self::assertSame($fastCloseVersionId, $fastCloseSettings->strategyVersionId);
        self::assertNotSame($marginDefenderSettings->strategyVersionId, $fastCloseSettings->strategyVersionId);

        // The RIGHT strategy's prompt, not a coincidentally-shared one.
        self::assertSame(
            BuiltInStrategies::all()[$marginDefenderId]['prompt'],
            $marginDefenderSettings->strategyPrompt,
        );
        self::assertSame(BuiltInStrategies::all()[$fastCloseId]['prompt'], $fastCloseSettings->strategyPrompt);
        self::assertNotSame($marginDefenderSettings->strategyPrompt, $fastCloseSettings->strategyPrompt);

        // The cell's OWN model, not a shared default.
        self::assertSame('model-a', $marginDefenderSettings->llm->model);
        self::assertSame('model-b', $fastCloseSettings->llm->model);
    }

    /** @return array{apiKey: string, models: list<string>, buyerKind: string, baseUrl: string} */
    private static function requireEnv(): array
    {
        $apiKey = getenv('QUOTE_AGENT_BENCH_KEY');
        $modelsCsv = getenv('QUOTE_AGENT_BENCH_MODELS');

        if (!\is_string($apiKey) || $apiKey === '' || !\is_string($modelsCsv) || $modelsCsv === '') {
            self::markTestSkipped('Set QUOTE_AGENT_BENCH_KEY and QUOTE_AGENT_BENCH_MODELS to run the bench.');
        }

        $models = array_values(array_filter(
            array_map(trim(...), explode(',', $modelsCsv)),
            static fn(string $model): bool => $model !== '',
        ));
        self::assertNotEmpty($models, 'QUOTE_AGENT_BENCH_MODELS must list at least one model.');

        $buyerKind = getenv('QUOTE_AGENT_BENCH_BUYER');
        $buyerKind = \is_string($buyerKind) && $buyerKind !== '' ? $buyerKind : 'scripted';

        $baseUrl = getenv('QUOTE_AGENT_BENCH_BASE_URL');
        $baseUrl = \is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL;

        return ['apiKey' => $apiKey, 'models' => $models, 'buyerKind' => $buyerKind, 'baseUrl' => $baseUrl];
    }

    /**
     * The current version of every built-in strategy, so each cell's
     * `QuoteAgentSettings` can carry a `strategyVersionId` that actually
     * resolves. Fails the whole run immediately rather than per-cell: a
     * missing version row is a shop precondition (the seeding migration
     * never ran), not a flaky model call, and burning API budget across every
     * scenario before finding that out would be its own kind of harness bug.
     *
     * @return array<string, string> strategy id => its current version id, both lowercase hex
     */
    private static function resolveStrategyVersions(Connection $connection): array
    {
        $versions = [];

        foreach (array_keys(BuiltInStrategies::all()) as $strategyId) {
            $versionId = $connection->fetchOne(
                'SELECT LOWER(HEX(id)) FROM merchant_quote_agent_strategy_version
                 WHERE strategy_id = UNHEX(:strategyId) ORDER BY version DESC LIMIT 1',
                ['strategyId' => $strategyId],
            );

            self::assertIsString($versionId, sprintf(
                'Strategy "%s" has no row in merchant_quote_agent_strategy_version -- '
                . 'Migration1789400001SeedBuiltInStrategies must run on this shop before the bench.',
                $strategyId,
            ));
            self::assertNotSame('', $versionId);

            $versions[$strategyId] = $versionId;
        }

        return $versions;
    }

    /**
     * One cell, isolated from the matrix loop so the loop itself stays flat:
     * this is where the try/catch that turns a thrown cell into a recorded
     * failure lives, rather than nested inside three loops (mago's
     * excessive-nesting gate).
     */
    private static function attemptCell(
        BenchNegotiation $bench,
        Connection $connection,
        RunWriter $writer,
        BenchCell $cell,
    ): BenchCellOutcome {
        try {
            return new BenchCellOutcome(self::runCell($bench, $connection, $writer, $cell), null);
        } catch (\Throwable $e) {
            $failure = self::describeFailure($cell, $e);
            $writer->writeRow(DecisionRowMapper::toFailureRow($cell, $e));

            // The failure row IS this cell's one JSONL line -- not a decision
            // row, but a line the loop's $written tally (and the final
            // assertCount below) must still account for, or the harness
            // would under-count its own output.
            return new BenchCellOutcome(1, $failure);
        }
    }

    /** Runs one matrix cell and appends its decision rows to the run file. Returns how many rows it wrote. */
    private static function runCell(
        BenchNegotiation $bench,
        Connection $connection,
        RunWriter $writer,
        BenchCell $cell,
    ): int {
        $result = $bench->run($cell->scenario, self::buyerFor($cell), CellSettings::for($cell), $cell->config->runId);

        $rows = DecisionRowMapper::rows($connection, $result->quoteId);
        foreach ($rows as $index => $row) {
            $writer->writeRow(DecisionRowMapper::toJsonlRow(
                $cell->config->runId,
                $cell->scenario->id,
                $index + 1,
                $row,
                $result,
            ));
        }

        return \count($rows);
    }

    private static function buyerFor(BenchCell $cell): SyntheticBuyer
    {
        if ($cell->config->buyerKind === 'llm') {
            return new LlmBuyer(
                $cell->config->platform,
                new ModelAccess($cell->config->apiKey, $cell->config->baseUrl, $cell->model),
                $cell->scenario->persona,
            );
        }

        // ponytail: one generic scripted profile for every scenario, rather
        // than a persona -> (target, ratio, patience) table Task 7's scenario
        // set does not define. Targets 10% off, closes half the remaining gap
        // per round, and never walks on patience alone -- the scenario's own
        // maxRounds is what bounds a non-converging cell. Upgrade to a
        // per-scenario profile once scenarios carry their own target/ratio.
        return new ScriptedBuyer(
            targetDiscountPercent: 10.0,
            concessionRatio: 0.5,
            patience: $cell->scenario->maxRounds,
        );
    }

    private static function describeFailure(BenchCell $cell, \Throwable $e): string
    {
        return sprintf(
            '%s / %s / %s: %s: %s',
            $cell->scenario->id,
            $cell->strategyId,
            $cell->model,
            $e::class,
            $e->getMessage(),
        );
    }

    private static function newRunId(): string
    {
        return 'bench-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    private static function runPath(string $runId): string
    {
        return \dirname(__DIR__, levels: 3) . '/var/bench/' . $runId . '.jsonl';
    }
}

/**
 * Per-run bench configuration threaded into every cell: the credentials and
 * base URL `QUOTE_AGENT_BENCH_KEY`/`QUOTE_AGENT_BENCH_BASE_URL` name, which
 * synthetic buyer `QUOTE_AGENT_BENCH_BUYER` selects, the shared `ModelPlatform`
 * every cell's model calls go through, and the run's own id.
 *
 * Grouped out of `BenchCell`'s own constructor for the same reason
 * `OrderConversion` is grouped out of `NegotiationResult`'s (see that class):
 * `BenchCell` already carries scenario/strategyId/strategyVersionId/model, and
 * a bare fifth through ninth scalar would blow past this codebase's
 * five-parameter cap.
 */
final readonly class BenchRunConfig
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $baseUrl,
        public string $buyerKind,
        public ModelPlatform $platform,
        public string $runId,
    ) {}
}

/**
 * One (scenario, strategy, model) cell of the matrix, bundled so the helper
 * methods that run and record it stay inside the five-parameter cap.
 */
final readonly class BenchCell
{
    public function __construct(
        public Scenario $scenario,
        public string $strategyId,
        public string $strategyVersionId,
        public string $model,
        public BenchRunConfig $config,
    ) {}
}

/** What one matrix cell produced: rows written on success, or a failure description -- never both. */
final readonly class BenchCellOutcome
{
    public function __construct(
        public int $written,
        public ?string $failure,
    ) {}
}

/**
 * Shapes a quote's `merchant_quote_agent_decision` rows into the bench's
 * JSONL, moved out of `BenchRunTest` itself so that class stays under mago's
 * per-class method-count gate -- this is pure data shaping with no test
 * assertions of its own, and reads naturally as one collaborator.
 */
final class DecisionRowMapper
{
    private function __construct() {}

    /**
     * One row per decision-record pass the cell's quote left behind, oldest
     * first, so the caller can number them into scenario rounds. Values are
     * cast to real PHP types: DBAL returns DOUBLE/INT columns as strings, and
     * the JSONL is meant to be read as data, not re-parsed as text.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(Connection $connection, string $quoteId): array
    {
        $rows = $connection->fetchAllAssociative('SELECT
                LOWER(HEX(quote_id)) AS quoteId,
                outcome,
                band,
                discount_percent_granted AS discountPercentGranted,
                total_net_before AS totalNetBefore,
                total_net_after AS totalNetAfter,
                LOWER(HEX(strategy_version_id)) AS strategyVersionId,
                model,
                prompt_tokens AS promptTokens,
                completion_tokens AS completionTokens,
                terminal_state AS terminalState,
                created_at AS createdAt,
                resolved_at AS resolvedAt
             FROM merchant_quote_agent_decision
             WHERE quote_id = UNHEX(:quote)
             ORDER BY created_at ASC, id ASC', ['quote' => $quoteId]);

        return array_map(self::typed(...), $rows);
    }

    /** @param array<string, mixed> $decisionRow */
    public static function toJsonlRow(
        string $runId,
        string $scenarioId,
        int $round,
        array $decisionRow,
        NegotiationResult $result,
    ): array {
        return [
            'runId' => $runId,
            'scenarioId' => $scenarioId,
            'round' => $round,
            ...$decisionRow,
            'orderId' => $result->order->orderId,
            'orderFailure' => $result->order->orderFailure,
        ];
    }

    /**
     * The trace a cell that threw before any decision row existed still
     * leaves behind. Same runId/scenarioId/strategyVersionId/model shape as
     * a real decision row, so the scorer's grouping-by-key logic sees it,
     * plus `cellFailure: true` as an explicit discriminator -- inferring
     * "this was a failure" from an absent field (a null outcome, say) is
     * exactly the kind of silent gap this row exists to close.
     *
     * @return array<string, mixed>
     */
    public static function toFailureRow(BenchCell $cell, \Throwable $e): array
    {
        return [
            'runId' => $cell->config->runId,
            'scenarioId' => $cell->scenario->id,
            'strategyVersionId' => $cell->strategyVersionId,
            'model' => $cell->model,
            'cellFailure' => true,
            'failureClass' => $e::class,
            'failureMessage' => $e->getMessage(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function typed(array $row): array
    {
        $row['discountPercentGranted'] = self::nullableFloat($row['discountPercentGranted']);
        $row['totalNetBefore'] = self::nullableFloat($row['totalNetBefore']);
        $row['totalNetAfter'] = self::nullableFloat($row['totalNetAfter']);
        $row['promptTokens'] = self::nullableInt($row['promptTokens']);
        $row['completionTokens'] = self::nullableInt($row['completionTokens']);

        return $row;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

/**
 * Builds one cell's `QuoteAgentSettings` -- the single line the whole bench
 * rests on, per its own docblock in `BenchRunTest`. Kept as its own class
 * (rather than a private method on `BenchRunTest`) for two reasons: it moves
 * one more method off `BenchRunTest`'s own mago method-count budget, and it
 * makes the wiring directly callable from a covering test with no
 * reflection needed.
 */
final class CellSettings
{
    private function __construct() {}

    public static function for(BenchCell $cell): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            self::policy(),
            new ModelAccess($cell->config->apiKey, $cell->config->baseUrl, $cell->model),
            BuiltInStrategies::all()[$cell->strategyId]['prompt'],
            strategyVersionId: $cell->strategyVersionId,
        );
    }

    /**
     * The bands hoelshare's own `system_config` carries (see the design
     * spec's "The two tracks use different shops"): generous enough that
     * scenarios exercise them instead of bouncing off a ceiling.
     * `QuoteAgentSettings` is built directly here rather than read from
     * config -- writing to `system_config` per cell would race any other
     * session on the shop -- so this mirrors that shop's config by hand
     * instead of drifting from it.
     */
    private static function policy(): NegotiationPolicy
    {
        return new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 15.0,
            counterOfferMaxPercent: 25.0,
            valueCeiling: new QuoteValueCeiling([QuoteValueCeiling::ANY_CURRENCY => 500_000.0]),
            validityDays: 10,
        ));
    }
}
