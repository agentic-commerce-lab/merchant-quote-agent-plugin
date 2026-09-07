<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin;

use Shopware\Core\Framework\Plugin;

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
    #[\Override]
    public function executeComposerCommands(): bool
    {
        return true;
    }
}
