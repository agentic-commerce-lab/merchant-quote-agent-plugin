<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Stringable;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

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
    private const TABLES = [
        'merchant_quote_agent_a2cn_receipt',
        'merchant_quote_agent_a2cn_violation',
        'merchant_quote_agent_a2cn_act',
    ];

    public function testUninstallDropsTheEvidenceTablesAndDeletesTheSigningKeyWhenDataIsRemoved(): void
    {
        $connection = $this->createMock(Connection::class);
        $dropped = [];
        $connection
            ->expects(self::exactly(3))
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

    /**
     * The install-time regression: during install() the plugin is by
     * definition inactive, so KernelPluginLoader::getBundles() — which yields
     * only ACTIVE plugin instances — never loaded our services.php, and
     * A2cnKeyStore is not a service id in that container at all. `?->` guards
     * a NULL container, not a MISSING service, so Container::get() throws
     * ServiceNotFoundException, PluginLifecycleService::installPlugin()
     * rethrows it, and because the throw lands before runMigrations() the
     * evidence tables are never created either. install() therefore asks the
     * container for nothing; the key is generated in activate(), where the
     * container has been rebuilt with our services in it.
     */
    public function testInstallAsksTheContainerForNothing(): void
    {
        $container = self::throwingContainer();
        $plugin = new MerchantQuoteAgentPlugin(active: false, basePath: __DIR__);
        $plugin->setContainer($container);

        $plugin->install(
            new InstallContext(
                $plugin,
                Context::createDefaultContext(),
                '6.7.0.0',
                '1.0.0',
                self::createStub(MigrationCollection::class),
            ),
        );

        self::assertSame([], $container->requested, 'install() must not resolve any service');
    }

    /**
     * Ucp\Sdk\Internal\Security\DefaultSigningKeyManager::generate() throws
     * Ucp\Sdk\Exception\SignatureException on an openssl failure, and a shop
     * whose key generation fails must still activate: a plugin that cannot
     * activate cannot service quotes at all, whereas a missing key only costs
     * the evidence layer — A2cnKeyStore::current() reports it loudly wherever
     * a key is actually needed (503 on the discovery routes). Modelled here
     * with a container that throws, which covers both a failed generate() and
     * a service id that is somehow absent.
     */
    public function testActivateSurvivesAFailureToGenerateTheSigningKey(): void
    {
        $logger = self::recordingLogger();
        $container = self::throwingContainer($logger);
        $plugin = new MerchantQuoteAgentPlugin(active: true, basePath: __DIR__);
        $plugin->setContainer($container);

        $plugin->activate(
            new ActivateContext(
                $plugin,
                Context::createDefaultContext(),
                '6.7.0.0',
                '1.0.0',
                self::createStub(MigrationCollection::class),
            ),
        );

        self::assertSame([A2cnKeyStore::class], $container->requested);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertInstanceOf(\Throwable::class, $logger->records[0]['exception']);
    }

    /**
     * The upgrade gap this test pins: PluginLifecycleService::updatePlugin()
     * calls update() on the live plugin instance and never deactivates or
     * reactivates a plugin that is already active, so activate() does NOT run
     * on a `plugin:update`. A shop that upgraded into the version introducing
     * the evidence layer therefore stayed keyless — observed on a live
     * installation — until it was deactivated and activated by hand.
     * generateIfAbsent() is what makes generating here safe: a shop that
     * already holds a key is untouched, so no upgrade rotates one.
     *
     * Asserted against the same throwing container as activate(), because
     * fail-open matters more on this path, not less: updatePlugin() responds
     * to a throwing update() by DEACTIVATING the plugin, so an openssl
     * failure allowed to propagate would take all quote servicing off a shop
     * that merely ran an upgrade.
     */
    public function testUpdateGeneratesTheSigningKeyAndSurvivesFailingTo(): void
    {
        $logger = self::recordingLogger();
        $container = self::throwingContainer($logger);
        $plugin = new MerchantQuoteAgentPlugin(active: true, basePath: __DIR__);
        $plugin->setContainer($container);

        $plugin->update(
            new UpdateContext(
                $plugin,
                Context::createDefaultContext(),
                '6.7.0.0',
                '1.0.0',
                self::createStub(MigrationCollection::class),
                '1.1.0',
            ),
        );

        self::assertSame([A2cnKeyStore::class], $container->requested);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertInstanceOf(\Throwable::class, $logger->records[0]['exception']);
    }

    /**
     * Records which service ids were asked for and refuses every one of them,
     * exactly as the compiled container does for an id it does not know.
     *
     * @return ContainerInterface&object{requested: list<string>}
     */
    private static function throwingContainer(?AbstractLogger $logger = null): ContainerInterface
    {
        return new class($logger) extends Container {
            /** @var list<string> */
            public array $requested = [];

            public function __construct(
                private readonly ?AbstractLogger $logger,
            ) {
                parent::__construct();
            }

            #[\Override]
            public function has(string $id): bool
            {
                return $id === MerchantQuoteAgentPlugin::LIFECYCLE_LOGGER_ID && $this->logger !== null;
            }

            #[\Override]
            public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
            {
                if ($id === MerchantQuoteAgentPlugin::LIFECYCLE_LOGGER_ID && $this->logger !== null) {
                    return $this->logger;
                }

                $this->requested[] = $id;

                throw new ServiceNotFoundException($id);
            }
        };
    }

    /** @return AbstractLogger&object{records: list<array{level: string, exception: mixed}>} */
    private static function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, exception: mixed}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param array<array-key, mixed> $context
             */
            #[\Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'exception' => $context['exception'] ?? null];
            }
        };
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
