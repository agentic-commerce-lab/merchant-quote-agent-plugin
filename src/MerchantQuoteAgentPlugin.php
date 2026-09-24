<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Migration\Migration1789500001SeedEscalationMailAndFlow as EscalationMailSeed;
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
 * @mago-expect analysis:missing-constructor
 * Symfony's Bundle declares $container/$name as typed properties without
 * defaults and initialises them outside a constructor (setContainer, getName).
 * Shopware's Plugin sets $path via its own constructor. Nothing for us to add.
 *
 * @mago-expect lint:too-many-methods
 * Six of the eleven are framework hooks Shopware calls on the Plugin instance
 * itself — executeComposerCommands, getTemplatePriority, configureRoutes,
 * activate, update, uninstall — so none of them can move off this class
 * without ceasing to be called. The other five are private helpers for two
 * lifecycle jobs: uninstall cleanup (dropPluginTables,
 * deleteSeededMailAndFlow, deleteSigningKey) and signing-key generation
 * (generateSigningKey, logKeyGenerationFailure). Those five ARE a cohesion
 * smell and the right fix is to extract them into their own collaborators;
 * that is a refactor of lifecycle code this change does not otherwise touch,
 * so it is deliberately not bundled here. Revisit when either lifecycle job
 * grows again.
 *
 * @mago-expect lint:cyclomatic-complexity
 * configureRoutes gates SwagCommercial review and UCP routes separately so
 * an inactive optional plugin contributes no routes.
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
     * Wins the Twig namespace hierarchy against SwagCommercial, whose
     * quote detail page this plugin's storefront banner extends.
     *
     * Lower is higher precedence. Both plugins would otherwise sit at the
     * default 0, and BundleHierarchyBuilder's stable sort would break that tie
     * on bundle registration order — which DbalKernelPluginLoader takes from
     * `ORDER BY installed_at`. That makes the banner's visibility depend on
     * which plugin the merchant happened to install first. -1 is the smallest
     * value that removes the shop's install history from the answer while
     * still leaving room for a theme or a later extension to outrank us.
     */
    #[Override]
    public function getTemplatePriority(): int
    {
        return -1;
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

        if (CommercialAvailability::isRegistered($this->container)) {
            $routes->import($this->getPath() . '/Review/DraftReviewController.php', 'attribute');
        }

        if (UcpAvailability::isRegistered($this->container)) {
            AgentFacingRoutes::import($routes, $this->getPath(), $this->container);
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

        // The key is deleted independently of the two SQL steps: a merchant
        // who asked to wipe data must not keep a live private key in
        // system_config just because the container could not hand back a
        // Connection. The two SQL steps share one, because neither can run
        // without it and a second lookup would only be a second way to
        // disagree about that.
        $connection = $this->container?->get(Connection::class);
        if ($connection instanceof Connection) {
            $this->dropPluginTables($connection);
            $this->deleteSeededMailAndFlow($connection);
        }

        $this->deleteSigningKey();
    }

    /**
     * Every table this plugin creates, dropped when the merchant asked to
     * remove the data.
     *
     * Core expects exactly this: PluginLifecycleService's own comment reads
     * "plugin->uninstall() will remove the tables etc of the plugin". Nothing
     * else drops them, so a table missing from this list survives a wipe.
     *
     * #59: only the three A2CN tables were dropped, leaving the decision table
     * behind. That table is the reason the issue is filed as a data-protection
     * problem rather than untidiness -- it holds `buyer_ask` (the buyer's own
     * words, verbatim, since #177), `reply_to_buyer`, `interpreted_asks` and
     * `customer_id`, all tied to an identifiable buyer. A merchant who ticks "remove all data permanently"
     * and silently keeps a negotiation history has been told something untrue,
     * and stops treating it as data they hold. Partial deletion is worse than
     * none, because it is invisible.
     *
     * A merchant who wants the audit log kept across a reinstall has the
     * "keep data" option for exactly that.
     *
     * The list is the create side of `src/Migration` -- keep the two in step
     * when a migration adds a table. `IF EXISTS` keeps this safe on a shop
     * whose migrations never ran, and on one where a table arrived in a later
     * release than the plugin version being removed.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function dropPluginTables(Connection $connection): void
    {
        foreach ([
            'merchant_quote_agent_a2cn_receipt',
            'merchant_quote_agent_a2cn_violation',
            'merchant_quote_agent_a2cn_act',
            'merchant_quote_agent_decision',
            'merchant_quote_agent_pending_authorization',
            'merchant_quote_agent_strategy_assignment',
            'merchant_quote_agent_strategy_version',
            'merchant_quote_agent_strategy',
        ] as $table) {
            $connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }

    /**
     * The row-level counterpart to dropPluginTables(), for the four rows
     * Migration1789500001SeedEscalationMailAndFlow seeds into core's shared
     * `flow` / `mail_template` tables (#170).
     *
     * Those tables are the shop's, not ours, so there is nothing to DROP. Left
     * alone, though, the seeded rows outlive a "remove all data" uninstall: a
     * flow listed in Flow Builder wired to an event name that can no longer
     * fire, and a mail template in Settings whose plugin is gone. No buyer or
     * merchant data is in them (static Twig markup only), so this is
     * untidiness rather than the #59 class of problem — but "remove all data"
     * should still mean it, and only this plugin knows those ids.
     *
     * Two rules make deleting in a shared table safe, and both are why this
     * cannot just mirror dropPluginTables()' unconditional DROP:
     *
     * 1. **Only a row the merchant never touched.** `updated_at IS NULL` is
     *    the whole test. The seed writes `created_at` and nothing else, and
     *    every write through the DAL — renaming the flow, editing the
     *    template, or just flipping the flow's toggle on in the list — sets
     *    `updated_at`. So a merchant who so much as enabled the flow keeps it:
     *    it stopped being our seeded default and became their configuration
     *    the moment they adopted it, and a stale row they can delete in two
     *    clicks is a smaller harm than silently deleting work they did. A
     *    column we misread only ever leaves a row behind, never removes one.
     *
     * 2. **Never a row something else still points at.** The FKs make this
     *    load-bearing, not defensive: `flow_sequence.flow_id` and
     *    `mail_template_translation.mail_template_id` are ON DELETE CASCADE
     *    (so those rows need no statement of their own, and neither do the
     *    two `*_translation` tables or `mail_template_sales_channel`), but
     *    `mail_template.mail_template_type_id` is ON DELETE **SET NULL** —
     *    dropping the type out from under a template a merchant built on it
     *    would quietly untype their template instead of failing. Hence the
     *    NOT EXISTS guards, and hence the order: the flow goes first, so that
     *    by the time the template is considered, the only `flow_sequence`
     *    rows still naming it are ones we are not entitled to break.
     *
     * The net effect on an untouched install is all four rows gone; on an
     * edited one, exactly the edited parts survive.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function deleteSeededMailAndFlow(Connection $connection): void
    {
        // Cascades the seeded flow_sequence row with it.
        $connection->executeStatement('DELETE FROM `flow` WHERE `id` = :id AND `updated_at` IS NULL', [
            'id' => hex2bin(EscalationMailSeed::FLOW_ID),
        ]);

        // LIKE, not JSON_SEARCH: `config` is only CHECK-constrained to valid
        // JSON, and JSON_SEARCH raises on a row that slipped past that, which
        // would abort the whole uninstall over someone else's malformed flow.
        // A 32-char hex id gives a substring match nothing else plausibly
        // hits, and a false positive only keeps the template — the safe way
        // to be wrong.
        $connection->executeStatement('DELETE FROM `mail_template`
              WHERE `id` = :id
                AND `updated_at` IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM `flow_sequence` WHERE `config` LIKE :reference
                )', [
            'id' => hex2bin(EscalationMailSeed::MAIL_TEMPLATE_ID),
            'reference' => '%' . EscalationMailSeed::MAIL_TEMPLATE_ID . '%',
        ]);

        // Two names for one value: a repeated `:id` would lean on DBAL's
        // duplicate-placeholder expansion for no gain.
        $connection->executeStatement('DELETE FROM `mail_template_type`
              WHERE `id` = :id
                AND `updated_at` IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM `mail_template` WHERE `mail_template_type_id` = :typeId
                )', [
            'id' => hex2bin(EscalationMailSeed::MAIL_TEMPLATE_TYPE_ID),
            'typeId' => hex2bin(EscalationMailSeed::MAIL_TEMPLATE_TYPE_ID),
        ]);
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
