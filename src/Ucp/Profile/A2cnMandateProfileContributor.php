<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Profile;

use MerchantQuoteAgentPlugin\Protocol\Http\A2cnDiscoveryController;
use Override;
use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

/**
 * Advertises the merchant's signed A2CN seller mandate in the UCP discovery
 * document, so a buyer agent that starts at /.well-known/ucp can discover it,
 * fetch it, and verify it — without the mandate having to live in Shopware.
 *
 * The mandate itself is created, signed, and served by this plugin's own
 * A2cnDiscoveryController; this contributor only publishes a pointer to that
 * URL, resolved against the requested sales-channel domain the same way
 * QuoteCapabilityDescriptor::resolvedAgainst() resolves `com.shopware.quote`'s
 * URLs — `$input->baseUri` already IS that domain, so no separate domain
 * lookup is needed here.
 *
 * Because the SDK's capability filter runs first, this contributor is
 * registered with a lower priority so its addition survives (see
 * services.php) — the same posture as QuoteCapabilityProfileContributor.
 */
final class A2cnMandateProfileContributor implements ProfileContributorInterface
{
    public const CAPABILITY = 'com.a2cn.negotiation-mandate';

    private const CAPABILITY_VERSION = '0.2';

    #[Override]
    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile
    {
        $discoveryUrl = rtrim($input->baseUri, characters: '/') . A2cnDiscoveryController::DISCOVERY_PATH;

        $capabilities = $profile->capabilities;
        $capabilities[self::CAPABILITY] = [
            new CapabilityDescriptor(
                self::CAPABILITY,
                self::CAPABILITY_VERSION,
                $discoveryUrl,
                $discoveryUrl,
                [],
                ['discovery_url' => $discoveryUrl],
            ),
        ];

        return new PlatformProfile(
            $profile->version,
            $profile->services,
            $capabilities,
            $profile->paymentHandlers,
            $profile->signingKeys,
            $profile->supportedVersions,
        );
    }
}
