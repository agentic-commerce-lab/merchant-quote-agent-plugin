<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Protocol\Http\A2cnDiscoveryController;
use MerchantQuoteAgentPlugin\Ucp\Profile\A2cnMandateProfileContributor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

/**
 * Lives alongside the rest of the Protocol tests (not tests/Unit/Ucp, where
 * QuoteCapabilityProfileContributorTest sits) — this task's touched-files
 * list confines new tests to tests/Unit/Protocol/.
 */
#[CoversClass(A2cnMandateProfileContributor::class)]
final class A2cnMandateProfileContributorTest extends TestCase
{
    public function testItAddsTheMandateCapabilityWithoutDroppingOthers(): void
    {
        $profile = new PlatformProfile('1.0', [], ['dev.ucp.shopping.catalog' => []], []);

        $result = (new A2cnMandateProfileContributor())->contribute(
            $profile,
            new ProfileBuildInput('1.0', 'https://shop.example'),
        );

        self::assertArrayHasKey(A2cnMandateProfileContributor::CAPABILITY, $result->capabilities);
        self::assertArrayHasKey(
            'dev.ucp.shopping.catalog',
            $result->capabilities,
            'must not drop foreign capabilities',
        );
    }

    public function testItResolvesTheDiscoveryUrlAgainstTheRequestedBaseUri(): void
    {
        // Trailing slash on the base URI must not produce a doubled slash.
        $result = (new A2cnMandateProfileContributor())->contribute(
            new PlatformProfile('1.0', [], [], []),
            new ProfileBuildInput('1.0', 'https://shop.example/'),
        );

        $descriptor = $result->capabilities[A2cnMandateProfileContributor::CAPABILITY][0];
        $expected = 'https://shop.example' . A2cnDiscoveryController::DISCOVERY_PATH;

        self::assertSame($expected, $descriptor->specUrl);
        self::assertSame($expected, $descriptor->schemaUrl);
        self::assertSame($expected, $descriptor->config['discovery_url']);
    }
}
