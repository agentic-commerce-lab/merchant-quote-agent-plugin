<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;
use Shopware\Core\Framework\Adapter\Twig\NamespaceHierarchy\NamespaceHierarchyBuilder;

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
