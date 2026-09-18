<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Kernel;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Every seeded row hangs off one of these quote ids, which is also how the
 * script stays re-runnable: it deletes anything already on these ids before
 * writing, so running it twice does not double the rows.
 *
 * @return array<string, string> tag => 32-hex quote id
 */
function seedQuoteIds(): array
{
    $tags = [
        'relBuilder1',
        'relBuilder2',
        'fastClose1',
        'fastCloseEscalated',
        'marginDefender1',
        'mixedStrategy',
        'marginDefenderV2',
    ];
    $ids = [];
    foreach ($tags as $tag) {
        // '5eed' prefix marks these as seed rows at a glance in a raw dump;
        // the rest is just the tag, hashed down to fill 28 hex characters.
        $ids[$tag] = '5eed' . substr(sha1($tag), 0, 28);
    }
    return $ids;
}

/**
 * One row per servicing pass. `quote` keys into seedQuoteIds(); `strategy`
 * keys into the name => id map read from the database (null = unattributed,
 * which this script never writes — the shop already has nine such rows). A
 * pass may instead set `strategyVersionId` directly, bypassing that lookup,
 * for the one pass seeded against a version the lookup deliberately never
 * returns (see `$marginDefenderV2Id` below).
 *
 * `daysAgo`/`minutesAgo` are offsets from "now" rather than fixed calendar
 * dates, so every row stays inside the dashboard's trailing 30/60-day window
 * no matter when this script runs.
 *
 * @return list<array<string, mixed>>
 */
function seedPasses(string $marginDefenderV2Id): array
{
    return [
        // Relationship builder: a two-round negotiation that lands an order.
        // Round two holds the price round one set and drops its own token
        // counts, covering a pass with no usage block.
        [
            'quote' => 'relBuilder1',
            'number' => '9101',
            'strategy' => 'Relationship builder',
            'daysAgo' => 7,
            'minutesAgo' => 40,
            'outcome' => 'countered',
            'promptTokens' => 1200,
            'completionTokens' => 300,
            'netBefore' => 1000.0,
            'netAfter' => 950.0,
        ],
        [
            'quote' => 'relBuilder1',
            'number' => '9101',
            'strategy' => 'Relationship builder',
            'daysAgo' => 7,
            'minutesAgo' => 20,
            'outcome' => 'offered',
            'promptTokens' => null,
            'completionTokens' => null,
            'netBefore' => 950.0,
            'netAfter' => 900.0,
            'terminalState' => 'accepted',
            'terminalMinutesAgo' => 0,
        ],
        // Relationship builder: a single quiet pass, no tokens recorded.
        [
            'quote' => 'relBuilder2',
            'number' => '9102',
            'strategy' => 'Relationship builder',
            'daysAgo' => 6,
            'minutesAgo' => 0,
            'outcome' => 'nothing_to_do',
            'promptTokens' => null,
            'completionTokens' => null,
            'netBefore' => 500.0,
            'netAfter' => null,
        ],
        // Fast close: one pass, straight to an order.
        [
            'quote' => 'fastClose1',
            'number' => '9103',
            'strategy' => 'Fast close',
            'daysAgo' => 5,
            'minutesAgo' => 30,
            'outcome' => 'offered',
            'promptTokens' => 2000,
            'completionTokens' => 500,
            'netBefore' => 2000.0,
            'netAfter' => 1800.0,
            'terminalState' => 'accepted',
            'terminalMinutesAgo' => 0,
        ],
        // Fast close: escalated on every round, so it has no answered pass at
        // all -- the fallback in attributeStrategy() is what attributes this
        // quote, not the last-answered rule. Round two resolves ~5h45m later.
        [
            'quote' => 'fastCloseEscalated',
            'number' => '9104',
            'strategy' => 'Fast close',
            'daysAgo' => 4,
            'minutesAgo' => 15,
            'outcome' => 'escalated',
            'promptTokens' => null,
            'completionTokens' => null,
            'netBefore' => 1200.0,
            'netAfter' => null,
            'escalationReason' => 'discount_limit_exceeded',
        ],
        [
            'quote' => 'fastCloseEscalated',
            'number' => '9104',
            'strategy' => 'Fast close',
            'daysAgo' => 4,
            'minutesAgo' => 0,
            'outcome' => 'escalated',
            'promptTokens' => 800,
            'completionTokens' => 200,
            'netBefore' => 1200.0,
            'netAfter' => null,
            'escalationReason' => 'discount_limit_exceeded',
            'resolvedMinutesAgo' => -345,
        ],
        // Margin defender: one pass, straight to an order.
        [
            'quote' => 'marginDefender1',
            'number' => '9105',
            'strategy' => 'Margin defender',
            'daysAgo' => 3,
            'minutesAgo' => 45,
            'outcome' => 'offered',
            'promptTokens' => 1000,
            'completionTokens' => 400,
            'netBefore' => 3000.0,
            'netAfter' => 2700.0,
            'terminalState' => 'accepted',
            'terminalMinutesAgo' => 0,
        ],
        // Spans two strategies: opened under Relationship builder, then the
        // merchant switched the quote to Margin defender before the pass that
        // actually answered the buyer. Last answered pass wins the
        // attribution (Margin defender), and this is the one quote counted
        // in `mixedQuotes`.
        [
            'quote' => 'mixedStrategy',
            'number' => '9106',
            'strategy' => 'Relationship builder',
            'daysAgo' => 2,
            'minutesAgo' => 30,
            'outcome' => 'countered',
            'promptTokens' => 1100,
            'completionTokens' => 250,
            'netBefore' => 1600.0,
            'netAfter' => 1550.0,
        ],
        [
            'quote' => 'mixedStrategy',
            'number' => '9106',
            'strategy' => 'Margin defender',
            'daysAgo' => 2,
            'minutesAgo' => 0,
            'outcome' => 'offered',
            'promptTokens' => 1300,
            'completionTokens' => 350,
            'netBefore' => 1550.0,
            'netAfter' => 1500.0,
            'terminalState' => 'accepted',
            'terminalMinutesAgo' => 0,
        ],
        // Margin defender, VERSION 2: the merchant edited this strategy's
        // prompt. StrategyVersion never rewrites a version -- editing appends
        // one -- so this quote's pass carries a genuinely different version
        // id under the SAME strategy as marginDefender1 above. It is what
        // exercises the rollup this task adds against real data: without it,
        // every built-in strategy on this shop still has exactly one
        // version, which is exactly the condition that made the defect
        // invisible in the first place.
        [
            'quote' => 'marginDefenderV2',
            'number' => '9107',
            'strategyVersionId' => $marginDefenderV2Id,
            'daysAgo' => 1,
            'minutesAgo' => 20,
            'outcome' => 'offered',
            'promptTokens' => 900,
            'completionTokens' => 300,
            'netBefore' => 2200.0,
            'netAfter' => 2000.0,
            'terminalState' => 'accepted',
            'terminalMinutesAgo' => 0,
        ],
    ];
}

/** @param array<string, string> $versionIdsByName @throws \DateInvalidTimeZoneException */
function insertPasses(Connection $connection, array $quoteIds, array $versionIdsByName, string $marginDefenderV2Id): int
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $at = static fn(int $daysAgo, int $minutesAgo): string => $now
        ->modify('-' . $daysAgo . ' days')
        ->modify('-' . $minutesAgo . ' minutes')
        ->format('Y-m-d H:i:s.v');

    $written = 0;
    foreach (seedPasses($marginDefenderV2Id) as $pass) {
        $versionId = $pass['strategyVersionId'] ?? $versionIdsByName[$pass['strategy']];
        $connection->insert(
            'merchant_quote_agent_decision',
            [
                'id' => random_bytes(16),
                'quote_id' => hex2bin($quoteIds[$pass['quote']]),
                'quote_number' => $pass['number'],
                'outcome' => $pass['outcome'],
                'strategy_version_id' => hex2bin($versionId),
                'prompt_tokens' => $pass['promptTokens'],
                'completion_tokens' => $pass['completionTokens'],
                'total_net_before' => $pass['netBefore'],
                'total_net_after' => $pass['netAfter'] ?? null,
                'terminal_state' => $pass['terminalState'] ?? null,
                'terminal_at' => isset($pass['terminalMinutesAgo'])
                    ? $at($pass['daysAgo'], $pass['terminalMinutesAgo'])
                    : null,
                'escalation_reason' => $pass['escalationReason'] ?? null,
                'resolved_at' => isset($pass['resolvedMinutesAgo'])
                    ? $at($pass['daysAgo'], $pass['resolvedMinutesAgo'])
                    : null,
                'created_at' => $at($pass['daysAgo'], $pass['minutesAgo']),
            ],
            [
                'id' => 'binary',
                'quote_id' => 'binary',
                'strategy_version_id' => 'binary',
            ],
        );
        ++$written;
    }
    return $written;
}

/**
 * Reads version 1 specifically, not "whichever version this name has": once
 * `ensureSecondStrategyVersion()` below gives Margin defender a version 2,
 * a query with no version filter would be ambiguous about which of its two
 * rows a bare strategy name should resolve to. Version 1 is what every other
 * seeded pass in this file was written against.
 *
 * @return array{versionIds: array<string, string>, strategyIds: array<string, string>}
 *   both maps keyed by the strategy's OWN name
 */
function loadStrategyVersionIds(Connection $connection): array
{
    $rows = $connection->fetchAllAssociative(
        'SELECT LOWER(HEX(v.id)) AS id, LOWER(HEX(s.id)) AS strategy_id, s.name AS name'
        . ' FROM merchant_quote_agent_strategy_version v'
        . ' INNER JOIN merchant_quote_agent_strategy s ON s.id = v.strategy_id'
        . ' WHERE v.version = 1',
    );
    $versionIds = [];
    $strategyIds = [];
    foreach ($rows as $row) {
        $versionIds[(string) $row['name']] = (string) $row['id'];
        $strategyIds[(string) $row['name']] = (string) $row['strategy_id'];
    }
    foreach (['Relationship builder', 'Fast close', 'Margin defender'] as $required) {
        if (!isset($versionIds[$required])) {
            throw new RuntimeException(
                "Missing built-in strategy '$required'; run the plugin's own strategy seeding first.",
            );
        }
    }
    return ['versionIds' => $versionIds, 'strategyIds' => $strategyIds];
}

/**
 * A real second version of Margin defender, so the dashboard's strategy
 * rollup (this task) is exercised against actual multi-version data rather
 * than only the single-version case every built-in strategy otherwise still
 * has on this shop.
 *
 * Looked up before it is written: `(strategy_id, version)` is unique, so if
 * a real version 2 already exists here (seeded by an earlier run of this
 * script, or created by hand through the admin UI), this reuses that row's
 * own id instead of trying to insert a second, colliding one. `INSERT IGNORE`
 * on the deterministic id is the same idempotency the built-in strategies'
 * own migration uses for version 1 -- this never rewrites an existing
 * version, only appends one if none exists yet.
 *
 * @throws \DateInvalidTimeZoneException
 */
function ensureSecondStrategyVersion(Connection $connection, string $strategyId): string
{
    $existing = $connection->fetchOne('SELECT LOWER(HEX(id)) FROM merchant_quote_agent_strategy_version WHERE strategy_id = UNHEX(?) AND version = 2', [
        $strategyId,
    ]);
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    // Deterministic so a rerun that lost this race still resolves to the
    // same id it would have inserted.
    $versionId = substr(sha1('mqa-seed:marginDefender:v2'), 0, 32);
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

    $connection->executeStatement('INSERT IGNORE INTO merchant_quote_agent_strategy_version (id, strategy_id, version, prompt, created_at)'
    . ' VALUES (UNHEX(?), UNHEX(?), 2, ?, ?)', [
        $versionId,
        $strategyId,
        'Seeded for the dashboard\'s strategy rollup check: a second, '
            . 'otherwise-unused prompt revision of Margin defender.',
        $now,
    ]);

    return $versionId;
}

try {
    $externalEnvironment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
    if (is_string($externalEnvironment) && !in_array($externalEnvironment, ['dev', 'test'], true)) {
        throw new RuntimeException('Refusing decision seeding: APP_ENV must be dev or test.');
    }
    $root = getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4);
    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException(
            'Run this script in the installed Shopware plugin, or set SHOPWARE_ROOT to its shop root.',
        );
    }
    $classLoader = require $root . '/vendor/autoload.php';
    if (!$classLoader instanceof ClassLoader) {
        throw new RuntimeException('The installed Shopware autoloader did not return a Composer ClassLoader.');
    }
    (new Dotenv())->bootEnv($root . '/.env');
    $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
    if (!in_array($environment, ['dev', 'test'], true)) {
        throw new RuntimeException('Refusing decision seeding: effective APP_ENV must be dev or test.');
    }
    $kernel = KernelFactory::create($environment, false, $classLoader);
    if (!$kernel instanceof Kernel) {
        throw new RuntimeException('The installed Shopware kernel could not be created.');
    }
    $kernel->boot();

    $connection = $kernel->getContainer()->get(Connection::class);
    if (!$connection instanceof Connection) {
        throw new RuntimeException('The Doctrine connection is unavailable.');
    }

    $strategyLookup = loadStrategyVersionIds($connection);
    $marginDefenderV2Id = ensureSecondStrategyVersion($connection, $strategyLookup['strategyIds']['Margin defender']);
    $quoteIds = seedQuoteIds();

    $connection->executeStatement(
        'DELETE FROM merchant_quote_agent_decision WHERE quote_id IN ('
        . implode(',', array_fill(0, count($quoteIds), 'UNHEX(?)'))
        . ')',
        array_values($quoteIds),
    );

    $written = insertPasses($connection, $quoteIds, $strategyLookup['versionIds'], $marginDefenderV2Id);

    fwrite(STDOUT, sprintf(
        "Wrote %d decision rows across %d quotes and %d strategies (Margin defender now has a seeded version 2).\n",
        $written,
        count($quoteIds),
        count($strategyLookup['versionIds']),
    ));
    $summary = $connection->fetchAllAssociative(
        'SELECT COALESCE(LOWER(HEX(strategy_version_id)), \'(unattributed)\') AS strategy_version_id,'
        . ' COUNT(*) AS row_count, COUNT(DISTINCT quote_id) AS quotes'
        . ' FROM merchant_quote_agent_decision GROUP BY strategy_version_id ORDER BY strategy_version_id',
    );
    foreach ($summary as $row) {
        fwrite(STDOUT, sprintf(
            "  strategy_version_id=%s rows=%s quotes=%s\n",
            $row['strategy_version_id'],
            $row['row_count'],
            $row['quotes'],
        ));
    }

    $kernel->shutdown();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
