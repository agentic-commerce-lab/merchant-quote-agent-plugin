<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
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
    private const QUOTE_DETAIL = '@QuoteManagement/storefront/page/account/quote-detail/index.html.twig';

    public function testTheQuoteDetailPageRendersThroughThisPluginsTemplateOnAThemedStorefront(): void
    {
        // QA-06. Every storefront page renders with a theme active, and then
        // ThemeNamespaceHierarchyBuilder rebuilds the hierarchy through
        // ThemeInheritanceBuilder, which arsort()s the plugins: the HIGHER
        // getTemplatePriority() resolves first. That is the opposite of
        // BundleHierarchyBuilder's "lower wins", which is all the previous
        // version of this test checked (it reset the theme builder first). At
        // -1 the sw_extends chain ran OrganizationUnit, OrderApproval,
        // QuoteManagement and stopped there, below which this plugin sat, so
        // neither the banner nor the agent-name attribute ever rendered.
        //
        // The theme builder holds the active theme in a property that
        // KernelEvents::REQUEST fills; setting it is what a storefront request
        // does, and resetting both services afterwards keeps the rest of the
        // suite on the hierarchy it expects.
        $themeBuilder = static::getContainer()->get(ThemeNamespaceHierarchyBuilder::class);
        $finder = static::getContainer()->get(TemplateFinder::class);
        (new \ReflectionProperty($themeBuilder, 'themes'))->setValue($themeBuilder, ['Storefront' => true]);
        $finder->reset();

        try {
            $chain = self::extendsChain($finder, self::QUOTE_DETAIL);
        } finally {
            $themeBuilder->reset();
            $finder->reset();
        }

        self::assertContains(
            '@MerchantQuoteAgentPlugin/storefront/page/account/quote-detail/index.html.twig',
            $chain,
            'The themed sw_extends chain for the quote detail page never reaches this plugin: '
                . implode(' → ', $chain),
        );
    }

    /**
     * The templates `sw_extends` walks for $template, first to last, the way
     * the storefront renders it: each step resolves the same path again from
     * the template before it, until it reaches SwagCommercial's original.
     *
     * @return list<string>
     */
    private static function extendsChain(TemplateFinder $finder, string $template): array
    {
        $chain = [];
        $current = $finder->find($template);

        while (!\in_array($current, $chain, true)) {
            $chain[] = $current;

            if (str_starts_with($current, '@QuoteManagement/')) {
                break;
            }

            $current = $finder->find($template, false, $current);
        }

        return $chain;
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
