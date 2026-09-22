<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;
use Shopware\Core\Framework\Adapter\Twig\NamespaceHierarchy\NamespaceHierarchyBuilder;
use Shopware\Storefront\Theme\Twig\ThemeNamespaceHierarchyBuilder;
use Twig\Environment;

/**
 * The banner is the only disclosure surface that reaches the released
 * SwagCommercial lane, and its whole delivery depends on this plugin sorting
 * ahead of SwagCommercial in the Twig namespace hierarchy. The unit test
 * pins the priority VALUE; only this one proves the value actually wins
 * against the SwagCommercial the shop has installed.
 */
final class StorefrontDisclosureBannerTest extends IntegrationTestCase
{
    public function testThisPluginResolvesAheadOfSwagCommercialInTheTemplateHierarchy(): void
    {
        // ThemeNamespaceHierarchyBuilder is one link in the same chain
        // NamespaceHierarchyBuilder::buildHierarchy() folds. It caches which
        // theme is active in a plain property, set from KernelEvents::REQUEST,
        // and cleared only on kernel.terminate. A prior test in this same
        // process that dispatched a real HTTP request (->handle() without a
        // matching ->terminate()) leaves that cache populated, and once it is
        // non-empty buildNamespaceHierarchy() stops doing the priority-based
        // fold entirely and rebuilds the hierarchy via theme inheritance
        // instead -- a different algorithm this plugin does not control.
        // Resetting it here is what keeps this assertion about
        // getTemplatePriority(), regardless of what ran before it in the
        // suite.
        static::getContainer()->get(ThemeNamespaceHierarchyBuilder::class)->reset();

        $hierarchy = static::getContainer()->get(NamespaceHierarchyBuilder::class)->buildHierarchy();

        $namespaces = array_keys($hierarchy);
        $ours = array_search('MerchantQuoteAgentPlugin', $namespaces, true);
        $theirs = array_search('QuoteManagement', $namespaces, true);

        self::assertIsInt($ours, 'This plugin registers no storefront templates at all.');
        self::assertIsInt($theirs, 'SwagCommercial QuoteManagement is not loaded in this shop.');
        self::assertLessThan(
            $theirs,
            $ours,
            'SwagCommercial resolves first, so its quote detail page wins and the banner never renders.',
        );
    }

    public function testTheExtendedBlockExistsOnSwagCommercialsResolvedTemplate(): void
    {
        // The previous version of this template extended
        // page_account_quote_details_banner, a block that only exists on
        // SwagCommercial trunk -- every released version this plugin
        // supports (v6.7.2.0 through v6.7.12.0) has no such block, and Twig
        // silently no-ops an override of a block the parent never declares:
        // no error, no warning, no render. Nothing in the previous test
        // suite would have caught that, because none of it loaded the real
        // parent template source.
        //
        // The block name is read out of OUR template rather than typed a
        // second time here, the same way testTheMarkerKeyMatchesTheLiteralInTheTemplate
        // below derives the marker key instead of restating it: a literal
        // typed independently in this file would not notice if the .twig's
        // own {% block %} line changed (e.g. back to the trunk-only name) --
        // it would just keep confirming that some block by that old name
        // still exists on the parent, which is not the question this test
        // exists to answer.
        $template = file_get_contents(
            \dirname(__DIR__, 2) . '/src/Resources/views/storefront/page/account/quote-detail/index.html.twig',
        );
        self::assertIsString($template);

        $matched = preg_match('/\{%-?\s*block\s+(\w+)/', $template, $matches);
        self::assertSame(1, $matched, 'This template declares no Twig block; nothing to check against the parent.');
        $blockName = $matches[1];

        // Resolving '@QuoteManagement/...' through the actual Twig loader,
        // the same way `sw_extends` resolves it at render time, is what
        // would have caught the original defect: it reads whatever
        // SwagCommercial this shop has installed, not a path this plugin
        // assumes.
        $parentSource = static::getContainer()
            ->get(Environment::class)
            ->getLoader()
            ->getSourceContext('@QuoteManagement/storefront/page/account/quote-detail/index.html.twig')
            ->getCode();

        // \b rather than a hardcoded trailing space: tolerant of a harmless
        // upstream reformat (extra whitespace, a `-%}` whitespace-control
        // tag) while still refusing to match a longer block name that merely
        // starts with the same identifier.
        self::assertMatchesRegularExpression(
            '/\{%-?\s*block\s+' . preg_quote($blockName, '/') . '\b/',
            $parentSource,
            \sprintf(
                "SwagCommercial no longer declares '%s'; this plugin extends a block that does not exist "
                . 'and its banner silently stops rendering.',
                $blockName,
            ),
        );
    }

    public function testTheMarkerKeyMatchesTheLiteralInTheTemplate(): void
    {
        // The template cannot import the constant, so this asserts the two
        // halves of that contract against each other rather than trusting a
        // comment.
        $template = file_get_contents(
            \dirname(__DIR__, 2) . '/src/Resources/views/storefront/page/account/quote-detail/index.html.twig',
        );

        self::assertIsString($template);
        self::assertStringContainsString('page.quote.customFields.' . AgentDisclosure::MARKER_KEY, $template);
    }
}
