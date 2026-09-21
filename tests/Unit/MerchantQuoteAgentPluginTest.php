<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * uninstall()'s data-removal branch, without a Shopware kernel: Plugin's own
 * constructor and setContainer() are plain PHP (verified by reflection —
 * Plugin::__construct(bool $active, string $basePath, ?string $projectDir)),
 * Plugin::uninstall() itself is an empty no-op body, and UninstallContext's
 * constructor only needs a Context value object and a mockable
 * MigrationCollection. So a stub container standing in for
 * Connection/SystemConfigService is enough to exercise both branches.
 */
final class MerchantQuoteAgentPluginTest extends TestCase
{
    /**
     * Every table the plugin creates, not only the A2CN ones. #59: the
     * decision table used to survive a merchant's "remove all data", taking
     * its buyer comments and customer ids with it.
     *
     * UninstallDropsEveryTableTest is what keeps this list honest against
     * `src/Migration`; this test is what proves uninstall actually issues the
     * statements.
     */
    private const TABLES = [
        'merchant_quote_agent_a2cn_receipt',
        'merchant_quote_agent_a2cn_violation',
        'merchant_quote_agent_a2cn_act',
        'merchant_quote_agent_decision',
        'merchant_quote_agent_pending_authorization',
        'merchant_quote_agent_strategy_assignment',
        'merchant_quote_agent_strategy_version',
        'merchant_quote_agent_strategy',
    ];

    public function testUninstallDropsEveryPluginTableAndDeletesTheSigningKeyWhenDataIsRemoved(): void
    {
        $connection = $this->createMock(Connection::class);
        $dropped = [];
        $connection
            ->expects(self::exactly(\count(self::TABLES)))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$dropped): int {
                $dropped[] = $sql;

                return 0;
            });

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects(self::once())->method('delete')->with(A2cnKeyStore::CONFIG_KEY);

        self::plugin($connection, $systemConfig)->uninstall(self::context(keepUserData: false));

        foreach (self::TABLES as $table) {
            self::assertNotEmpty(
                array_filter($dropped, static fn(string $sql): bool => str_contains($sql, $table)),
                \sprintf('Expected a DROP TABLE statement for `%s`, got: %s', $table, implode(' | ', $dropped)),
            );
        }
    }

    public function testUninstallTouchesNeitherTableNorKeyWhenUserDataIsKept(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects(self::never())->method('delete');

        self::plugin($connection, $systemConfig)->uninstall(self::context(keepUserData: true));
    }

    private static function plugin(Connection $connection, SystemConfigService $systemConfig): MerchantQuoteAgentPlugin
    {
        $plugin = new MerchantQuoteAgentPlugin(active: true, basePath: __DIR__);
        $plugin->setContainer(self::container($connection, $systemConfig));

        return $plugin;
    }

    private static function container(Connection $connection, SystemConfigService $systemConfig): ContainerInterface
    {
        $container = self::createStub(ContainerInterface::class);
        $container
            ->method('get')
            ->willReturnCallback(static fn(string $id): ?object => match ($id) {
                Connection::class => $connection,
                SystemConfigService::class => $systemConfig,
                default => null,
            });

        return $container;
    }

    private static function context(bool $keepUserData): UninstallContext
    {
        // The `plugin` argument is only read back via getPlugin(), which
        // uninstall() never calls — a fresh, uncontainer-ed instance is fine.
        $plugin = new MerchantQuoteAgentPlugin(active: true, basePath: __DIR__);

        return new UninstallContext(
            $plugin,
            Context::createDefaultContext(),
            '6.7.0.0',
            '1.0.0',
            self::createStub(MigrationCollection::class),
            $keepUserData,
        );
    }
}
