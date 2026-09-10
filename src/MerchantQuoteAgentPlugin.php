<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Ucp\AgentFacingRoutes;
use MerchantQuoteAgentPlugin\Ucp\UcpAvailability;
use Override;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Merchant-side quote agent: reacts to B2B quote lifecycle events, negotiates
 * within merchant-defined limits, and escalates anything outside them.
 *
 * Requires SwagCommercial (B2B quote management) for the quote entities it
 * services. The Agentic Commerce plugin is optional: it is what imports the UCP
 * SDK's routes into Shopware, so it decides whether an agent can request a
 * quote of its own accord. Without it, a buyer's hand-made quote is serviced,
 * decided, escalated and audited exactly the same — the UCP endpoints, identity
 * linking and the Agent Access settings page are simply not registered. See
 * MerchantQuoteAgentPlugin\Ucp\UcpAvailability and ADR 0001.
 *
 * Services are loaded from Resources/config/services.php by Bundle::build().
 *
 * @mago-expect analysis:missing-constructor
 * Symfony's Bundle declares $container/$name as typed properties without
 * defaults and initialises them outside a constructor (setContainer, getName).
 * Shopware's Plugin sets $path via its own constructor. Nothing for us to add.
 */
class MerchantQuoteAgentPlugin extends Plugin
{
    /**
     * Lets Shopware resolve this plugin's Composer requirements itself.
     *
     * The README's `composer require` is the step that is easy to skip, and
     * skipping it is silent: the plugin activates and then throws `Class
     * "CuyZ\Valinor\MapperBuilder" not found` on every servicing pass. Core
     * already has the remedy — `PluginLifecycleService` runs `composer require
     * <name>:<version> --update-with-dependencies` on install, update and
     * uninstall — but gates it on this method, and `Plugin`'s default is false.
     * Overriding it is the whole fix: a shop that installs from the
     * administration gets the dependencies it needs without a shell.
     *
     * Core's own `--no-scripts` is what makes this safe rather than a second
     * way to break the shop. Flex applies recipes from
     * `ScriptEvents::POST_INSTALL_CMD` / `POST_UPDATE_CMD`, which that flag
     * suppresses, so this path cannot write the `config/packages/ai_generic_platform.yaml`
     * the README warns about — the file that takes an installation down at
     * container build. A later CLI `composer install`/`update` in the shop
     * still can, because a recipe skipped this way is not recorded as applied
     * in `symfony.lock`, so the README's `rm` stays the shop's business.
     *
     * Ignored in cluster mode (`shopware.deployment.cluster_setup`), where the
     * build, not the running shop, owns the lock file. That is core's rule and
     * the right one: nothing here needs to be conditional on it.
     */
    #[Override]
    public function executeComposerCommands(): bool
    {
        return true;
    }

    /**
     * `logger` is a private alias in the compiled container, so services.php
     * re-exposes it under this public id — the only way a lifecycle hook,
     * which runs outside any request and holds nothing but the container, can
     * log through PSR-3 at all.
     */
    public const LIFECYCLE_LOGGER_ID = 'merchant_quote_agent.lifecycle_logger';

    /**
     * The agent-facing routes, imported only where the UCP SDK bundle is.
     *
     * They live here rather than in Resources/config/routes.php because that
     * file is handed a RoutingConfigurator and nothing else, and the gate needs
     * the container's bundle list — the classpath cannot answer the question
     * (see UcpAvailability). Here `$this->container` is the booted container,
     * which is exactly the one whose services these routes resolve against, so
     * the router and the service graph cannot disagree.
     *
     * Ungated, they 500 rather than 404 on a shop without the plugin: the
     * controllers behind them are not registered there. Which routes those are
     * lives in AgentFacingRoutes, next to the gate list it mirrors.
     */
    #[Override]
    public function configureRoutes(RoutingConfigurator $routes, string $environment): void
    {
        parent::configureRoutes($routes, $environment);

        if (UcpAvailability::isRegistered($this->container)) {
            AgentFacingRoutes::import($routes, $this->getPath());
        }
    }

    /**
     * Key generation happens HERE and deliberately not in install(): during
     * install() the plugin is by definition inactive, so
     * KernelPluginLoader::getBundles() — which yields only active plugin
     * instances — never loaded our services.php, and A2cnKeyStore is not a
     * service id in that container. `?->` guards a null container, not a
     * missing service, so Container::get() would throw
     * ServiceNotFoundException, PluginLifecycleService::installPlugin() would
     * rethrow, and because that lands before runMigrations() the evidence
     * tables would never be created either. The container is rebuilt before
     * activate() runs — which is why the live shop works — and a plugin that
     * is installed but never activated needs no signing key.
     *
     * A `plugin:update` does not come through here — see update() below.
     */
    #[Override]
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->generateSigningKey();
    }

    /**
     * The other half of that story, and the reason activate() alone was not
     * enough: PluginLifecycleService::updatePlugin() calls update() on the
     * live plugin instance and never deactivates or reactivates a plugin that
     * is already active, so a shop upgrading into the version that introduced
     * the evidence layer got no key at all until someone deactivated and
     * activated it by hand. Observed on a live installation, not deduced.
     *
     * Safe to repeat on every upgrade because generateIfAbsent() leaves an
     * existing key alone: no upgrade ever rotates one out from under the acts
     * already signed with it.
     *
     * Fail-open matters more here than in activate(): updatePlugin() responds
     * to a throwing update() by DEACTIVATING the plugin, so an openssl
     * failure allowed to propagate would take all quote servicing off a shop
     * that merely ran an upgrade.
     */
    #[Override]
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->generateSigningKey();
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Independent of one another: neither may skip because the other's
        // service failed to resolve. A merchant who asked to wipe data must
        // not keep a live private key in system_config just because the
        // table drop below could not fetch a Connection, or vice versa.
        $this->dropEvidenceTables();
        $this->deleteSigningKey();
    }

    /** @throws \Doctrine\DBAL\Exception */
    private function dropEvidenceTables(): void
    {
        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        // #59 records that uninstall used to leave the decision table
        // behind. All three A2CN evidence tables are dropped here so this
        // does not become a fourth instance of that bug.
        foreach ([
            'merchant_quote_agent_a2cn_receipt',
            'merchant_quote_agent_a2cn_violation',
            'merchant_quote_agent_a2cn_act',
        ] as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }

    /**
     * A merchant who uninstalls to remove data must not keep a live private
     * key in system_config: left behind, a later reinstall's
     * generateIfAbsent() would find and reuse it instead of rotating.
     */
    private function deleteSigningKey(): void
    {
        $systemConfig = $this->container?->get(SystemConfigService::class);
        if ($systemConfig instanceof SystemConfigService) {
            $systemConfig->delete(A2cnKeyStore::CONFIG_KEY);
        }
    }

    /**
     * Fail-open, per the module's promise that evidence never blocks commerce
     * (spec acceptance criterion 7): `DefaultSigningKeyManager::generate()`
     * throws `SignatureException` on an `openssl_*` failure, and an activation
     * that dies there would take all quote servicing with it. A2cnKeyStore's
     * `current()` throws MissingSigningKey loudly wherever a key is actually
     * needed, and the discovery routes turn that into a 503, so failing soft
     * here loses nothing but the key itself.
     */
    private function generateSigningKey(): void
    {
        // has() before get(): on a shop with no UCP surface the whole evidence
        // layer is ungenerated (see UcpAvailability), so this id does not
        // exist. get() would throw ServiceNotFoundException, the catch below
        // would swallow it, and every activate and update on such a shop would
        // log "A2CN signing key generation failed" — an error about a feature
        // that shop deliberately does not have. Nothing to generate is not a
        // failure. Installing Agentic Commerce later brings the layer back, and
        // update()/activate() generate the key then.
        $container = $this->container;
        if ($container?->has(A2cnKeyStore::class) !== true) {
            return;
        }

        try {
            $keys = $container->get(A2cnKeyStore::class);
            if ($keys instanceof A2cnKeyStore) {
                $keys->generateIfAbsent();
            }
        } catch (\Throwable $error) {
            $this->logKeyGenerationFailure($error);
        }
    }

    private function logKeyGenerationFailure(\Throwable $error): void
    {
        // has() before get(): this method exists precisely because a service
        // id can be absent, and a logger fetch that throws would defeat the
        // catch that called us.
        $container = $this->container;
        $logger = $container?->has(self::LIFECYCLE_LOGGER_ID) === true
            ? $container->get(self::LIFECYCLE_LOGGER_ID)
            : null;

        if ($logger instanceof LoggerInterface) {
            $logger->error('A2CN signing key generation failed; the plugin is active without one.', [
                'exception' => $error,
            ]);
        }
    }
}
