<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use Override;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Merchant-side quote agent: reacts to B2B quote lifecycle events, negotiates
 * within merchant-defined limits, and escalates anything outside them.
 *
 * Requires SwagCommercial (B2B quote management) and the Agentic Commerce
 * plugin — the latter is what imports the UCP SDK's routes into Shopware, so
 * without it there is no UCP surface for this plugin to extend.
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

    /** @throws \Random\RandomException */
    #[Override]
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->generateSigningKey();
    }

    /** @throws \Random\RandomException */
    #[Override]
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        // Also here, so a shop that updates into this version gets a key
        // without being reinstalled.
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

    /** @throws \Random\RandomException */
    private function generateSigningKey(): void
    {
        $keys = $this->container?->get(A2cnKeyStore::class);
        if ($keys instanceof A2cnKeyStore) {
            $keys->generateIfAbsent();
        }
    }
}
