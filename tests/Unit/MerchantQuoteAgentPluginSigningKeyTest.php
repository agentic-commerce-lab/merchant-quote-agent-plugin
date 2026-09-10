<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Stringable;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Which lifecycle hooks reach for the A2CN signing key, and what happens when
 * they cannot get it. Split from MerchantQuoteAgentPluginTest, which keeps the
 * uninstall branches: the two halves share no helper, and together they were
 * over the per-class method gate.
 */
final class MerchantQuoteAgentPluginSigningKeyTest extends TestCase
{
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
     * with a container that has the service and refuses to hand it over.
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
     * A shop with no UCP surface has no evidence layer, so `A2cnKeyStore` is
     * not a service id at all — and that is a configuration, not a fault.
     * Before the has() guard this logged "A2CN signing key generation failed"
     * on every activate and update such a shop ever ran.
     *
     * Both hooks in one test because both call the same private method and the
     * upgrade path — the one that runs unattended — must not diverge from the
     * activation path here.
     */
    public function testActivateAndUpdateAreSilentWhenTheEvidenceLayerIsNotRegistered(): void
    {
        $logger = self::recordingLogger();
        $container = self::throwingContainer($logger, knowsKeyStore: false);
        $plugin = new MerchantQuoteAgentPlugin(active: true, basePath: __DIR__);
        $plugin->setContainer($container);
        $context = Context::createDefaultContext();
        $migrations = self::createStub(MigrationCollection::class);

        $plugin->activate(new ActivateContext($plugin, $context, '6.7.0.0', '1.0.0', $migrations));
        $plugin->update(new UpdateContext($plugin, $context, '6.7.0.0', '1.0.0', $migrations, '1.1.0'));

        self::assertSame([], $container->requested, 'nothing to generate is not a failure');
        self::assertSame([], $logger->records);
    }

    /**
     * Records which service ids were asked for and refuses every one of them,
     * exactly as the compiled container does for an id it does not know.
     *
     * `$knowsKeyStore` is the one bit that separates the two shops this test
     * has to tell apart, so it is a parameter rather than a second double:
     * true is a shop WITH the evidence layer whose key generation blows up
     * (fail-open), false is a shop with no UCP surface, where the id was never
     * registered and there is nothing to generate. Conflating them is what let
     * a missing service masquerade as an openssl failure.
     *
     * @return ContainerInterface&object{requested: list<string>}
     */
    private static function throwingContainer(
        ?AbstractLogger $logger = null,
        bool $knowsKeyStore = true,
    ): ContainerInterface {
        return new class($logger, $knowsKeyStore) extends Container {
            /** @var list<string> */
            public array $requested = [];

            public function __construct(
                private readonly ?AbstractLogger $logger,
                private readonly bool $knowsKeyStore,
            ) {
                parent::__construct();
            }

            #[\Override]
            public function has(string $id): bool
            {
                if ($id === MerchantQuoteAgentPlugin::LIFECYCLE_LOGGER_ID) {
                    return $this->logger !== null;
                }

                return $this->knowsKeyStore && $id === A2cnKeyStore::class;
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
}
