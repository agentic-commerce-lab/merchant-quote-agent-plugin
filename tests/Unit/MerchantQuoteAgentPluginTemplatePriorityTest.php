<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use PHPUnit\Framework\TestCase;

/**
 * The storefront banner and the agent-name attribute extend a SwagCommercial
 * template, which only works while this bundle sorts AHEAD of SwagCommercial
 * in the hierarchy a themed storefront request renders with.
 *
 * ThemeInheritanceBuilder arsort()s on getTemplatePriority(): higher first.
 * SwagCommercial's bundles take the default 0, and a tie would fall through
 * to bundle registration order (`ORDER BY installed_at`), so this feature
 * would work or not depending on which plugin a shop installed first. A
 * positive priority is what removes the shop's install history from the
 * answer. StorefrontDisclosureBannerTest proves it against the installed
 * SwagCommercial; this pins the value.
 */
final class MerchantQuoteAgentPluginTemplatePriorityTest extends TestCase
{
    public function testThisPluginOutranksPluginsThatTookTheDefaultPriority(): void
    {
        $plugin = new MerchantQuoteAgentPlugin(true, __DIR__);

        self::assertGreaterThan(0, $plugin->getTemplatePriority());
    }
}
