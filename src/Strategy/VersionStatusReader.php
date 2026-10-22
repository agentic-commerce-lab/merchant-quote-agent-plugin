<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\WriteCommand;

/**
 * The current `status` of every version row a write touches, in one query.
 *
 * StrategyWriteGuard runs once per write, not once per row, so looking a
 * status up per command would be a write amplifier -- this collects every
 * version-entity id first and reads them back in a single indexed IN.
 */
final class VersionStatusReader
{
    private const ENTITY = 'merchant_quote_agent_strategy_version';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @param list<WriteCommand> $commands
     *
     * @return array<string, string> hex id => current status
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function forCommands(array $commands): array
    {
        $ids = $this->versionIds($commands);

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> */
        return $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(`id`)), `status` FROM `merchant_quote_agent_strategy_version` WHERE `id` IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::BINARY],
        );
    }

    /**
     * @param array<string, string> $statuses as returned by forCommands()
     */
    public static function statusOf(WriteCommand $command, array $statuses): ?string
    {
        $id = $command->getPrimaryKey()['id'] ?? null;

        if (!\is_string($id)) {
            return null;
        }

        return $statuses[bin2hex($id)] ?? null;
    }

    /**
     * @param list<WriteCommand> $commands
     *
     * @return list<string> raw (binary) ids
     */
    private function versionIds(array $commands): array
    {
        $ids = [];

        foreach ($commands as $command) {
            if ($command->getEntityName() !== self::ENTITY) {
                continue;
            }

            $id = $command->getPrimaryKey()['id'] ?? null;

            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
