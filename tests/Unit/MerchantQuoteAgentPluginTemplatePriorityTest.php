<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use PHPUnit\Framework\TestCase;

/**
 * The storefront banner extends a SwagCommercial template, which only works
 * while this bundle sorts AHEAD of SwagCommercial in the Twig namespace
 * hierarchy.
 *
 * BundleHierarchyBuilder sorts on getTemplatePriority(), lower first, with a
 * stable asort. Both plugins default to 0, so the tie falls through to bundle
 * registration order, which DbalKernelPluginLoader takes from
 * `ORDER BY installed_at`. At the default this feature works or not depending
 * on which plugin a shop installed first — green here, silently dead there.
 * A negative priority is what removes the shop's install history from the
 * answer.
 */
final class MerchantQuoteAgentPluginTemplatePriorityTest extends TestCase
{
    public function testThisPluginOutranksPluginsThatTookTheDefaultPriority(): void
    {
        $plugin = new MerchantQuoteAgentPlugin(true, __DIR__);

        self::assertLessThan(0, $plugin->getTemplatePriority());
    }
}
