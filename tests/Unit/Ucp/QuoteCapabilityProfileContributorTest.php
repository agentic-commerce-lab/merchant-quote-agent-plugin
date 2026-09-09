<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp;

use MerchantQuoteAgentPlugin\Ucp\Profile\QuoteCapabilityProfileContributor;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapability;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapabilityDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

#[CoversClass(QuoteCapabilityProfileContributor::class)]
#[CoversClass(QuoteCapabilityDescriptor::class)]
#[CoversClass(QuoteCapability::class)]
final class QuoteCapabilityProfileContributorTest extends TestCase
{
    public function testItReAddsTheQuoteCapabilityAfterItHasBeenFilteredOut(): void
    {
        // What the Agentic Commerce plugin's capability filter leaves behind:
        // our descriptor stripped, everything it owns kept.
        $filtered = new PlatformProfile('1.0', [], ['dev.ucp.shopping.catalog' => []], []);

        $result = (new QuoteCapabilityProfileContributor())->contribute(
            $filtered,
            new ProfileBuildInput('1.0', 'https://shop.example/'),
        );

        self::assertArrayHasKey(QuoteCapabilityDescriptor::NAME, $result->capabilities);
        self::assertArrayHasKey(
            'dev.ucp.shopping.catalog',
            $result->capabilities,
            'must not drop foreign capabilities',
        );
    }

    public function testItResolvesSpecAndSchemaUrlsAgainstTheShopBaseUri(): void
    {
        // Trailing slash on the base URI must not produce a doubled slash.
        $result = (new QuoteCapabilityProfileContributor())->contribute(
            new PlatformProfile('1.0', [], [], []),
            new ProfileBuildInput('1.0', 'https://shop.example/'),
        );

        $descriptor = $result->capabilities[QuoteCapabilityDescriptor::NAME][0];

        self::assertSame('https://shop.example/.well-known/ucp/specs/quote.html', $descriptor->specUrl);
        self::assertSame('https://shop.example/.well-known/ucp/schemas/quote.openapi.json', $descriptor->schemaUrl);
    }

    public function testTheCapabilityDescribesItselfWithPathsBeforeTheBaseUriIsKnown(): void
    {
        $descriptor = (new QuoteCapability())->describe();

        self::assertSame(QuoteCapabilityDescriptor::NAME, $descriptor->name);
        self::assertSame(QuoteCapabilityDescriptor::SPEC_PATH, $descriptor->specUrl);
    }

    public function testItAddsTheDescriptorWhenIdentityLinkingIsExplicitlyEnabled(): void
    {
        $result = (new QuoteCapabilityProfileContributor())->contribute(
            new PlatformProfile('1.0', [], [], []),
            new ProfileBuildInput(
                '1.0',
                'https://shop.example/',
                enabledCapabilities: ['dev.ucp.shopping.cart', 'dev.ucp.common.identity_linking'],
            ),
        );

        self::assertArrayHasKey(QuoteCapabilityDescriptor::NAME, $result->capabilities);
    }

    public function testItAddsTheDescriptorWhenTheEnabledCapabilitiesListIsUnrestricted(): void
    {
        // An empty list means the shop never restricted capabilities at all,
        // not that none are enabled — RuntimeConfiguration::isCapabilityEnabled()
        // treats [] the same way. Getting this backwards would suppress the
        // quote descriptor on every shop that never touched this setting.
        $result = (new QuoteCapabilityProfileContributor())->contribute(
            new PlatformProfile('1.0', [], [], []),
            new ProfileBuildInput('1.0', 'https://shop.example/', enabledCapabilities: []),
        );

        self::assertArrayHasKey(QuoteCapabilityDescriptor::NAME, $result->capabilities);
    }

    public function testItSuppressesTheDescriptorWhenIdentityLinkingIsNotAmongTheRestrictedCapabilities(): void
    {
        // A non-empty list without identity linking means the shop deliberately
        // restricted capabilities and left identity linking out — a buyer agent
        // could never get a token, so advertising the quote capability here
        // would be a dead end.
        $result = (new QuoteCapabilityProfileContributor())->contribute(
            new PlatformProfile('1.0', [], [], []),
            new ProfileBuildInput('1.0', 'https://shop.example/', enabledCapabilities: ['dev.ucp.shopping.cart']),
        );

        self::assertArrayNotHasKey(QuoteCapabilityDescriptor::NAME, $result->capabilities);
    }
}
