<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Every `negotiationStrategy` a merchant typed becomes a strategy they can
 * still select, at the scope they set it.
 *
 * The old `negotiationStrategy` rows are deliberately left in place. Nothing
 * reads them after this; they make a rollback clean.
 *
 * Distinct texts get distinct lineages and identical text shares one, so two
 * channels configured alike stay alike afterwards. Names are numbered because
 * `name` is not unique-constrained and three rows all called "Custom strategy"
 * could not be told apart in the list.
 *
 * The raw `system_config` reads and writes live in NegotiationStrategyTextConfigRows,
 * not here: mago scores cyclomatic complexity per class, and this migration's
 * own orchestration plus that raw SQL together push a single class over the
 * project's threshold of 10.
 */
class Migration1789400002MigrateNegotiationStrategyText extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789400002;
    }

    /**
     * Groups raw `[salesChannelId, text]` pairs into the strategies to create.
     *
     * Static and database-free so the grouping and the naming can be tested
     * directly; the migration below only adds the SQL.
     *
     * @param list<array{0: ?string, 1: string}> $rows
     *
     * @return list<array{name: string, prompt: string, scopes: list<?string>}>
     */
    public static function plan(array $rows): array
    {
        $plan = [];

        foreach ($rows as [$salesChannelId, $text]) {
            $prompt = trim($text);

            if ($prompt === '') {
                continue;
            }

            $index = self::findByPrompt($plan, $prompt);

            if ($index === null) {
                $plan[] = [
                    'name' => self::nameFor(\count($plan) + 1),
                    'prompt' => $prompt,
                    'scopes' => [$salesChannelId],
                ];

                continue;
            }

            $plan[$index]['scopes'][] = $salesChannelId;
        }

        return $plan;
    }

    /**
     * @param list<array{name: string, prompt: string, scopes: list<?string>}> $plan
     */
    private static function findByPrompt(array $plan, string $prompt): ?int
    {
        $index = array_search($prompt, array_column($plan, 'prompt'), true);

        return $index === false ? null : $index;
    }

    private static function nameFor(int $position): string
    {
        return $position === 1 ? 'Custom strategy' : 'Custom strategy ' . $position;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $configRows = new NegotiationStrategyTextConfigRows();

        foreach (self::plan($configRows->existingText($connection)) as $entry) {
            $strategyId = Uuid::randomBytes();

            $connection->executeStatement('INSERT INTO `merchant_quote_agent_strategy` (`id`, `name`, `created_at`)
                 VALUES (:id, :name, :createdAt)', [
                'id' => $strategyId,
                'name' => $entry['name'],
                'createdAt' => $now,
            ]);

            $connection->executeStatement('INSERT INTO `merchant_quote_agent_strategy_version`
                    (`id`, `strategy_id`, `version`, `prompt`, `created_at`)
                 VALUES (:id, :strategyId, 1, :prompt, :createdAt)', [
                'id' => Uuid::randomBytes(),
                'strategyId' => $strategyId,
                'prompt' => $entry['prompt'],
                'createdAt' => $now,
            ]);

            foreach ($entry['scopes'] as $salesChannelId) {
                $configRows->insertStrategyId($connection, $salesChannelId, $strategyId, $now);
            }
        }
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // The old negotiationStrategy rows stay. They are the merchant's text
        // and the clean rollback path.
    }
}
