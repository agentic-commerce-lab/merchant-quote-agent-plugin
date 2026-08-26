<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Profile;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapabilityDescriptor;
use Override;
use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

/**
 * Puts `com.shopware.quote` back into the published discovery profile, with its
 * spec and schema URLs resolved against the shop's base URI.
 *
 * Why this exists: the Agentic Commerce plugin's CapabilityFilteringProfileContributor
 * whitelist-intersects the profile against descriptors listed in its own
 * hardcoded catalog, so a capability it has never heard of is deleted before
 * publication — even though the SDK collected it correctly. Running after that
 * filter (negative priority, see Resources/config/services.php) and re-adding
 * the descriptor is the only way a third plugin can advertise a capability
 * today.
 *
 * The upstream fix is one line in that filter and is tracked in issue #12; this
 * contributor can go once it lands.
 */
final class QuoteCapabilityProfileContributor implements ProfileContributorInterface
{
    #[Override]
    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile
    {
        $capabilities = $profile->capabilities;
        $capabilities[QuoteCapabilityDescriptor::NAME] = [
            QuoteCapabilityDescriptor::resolvedAgainst($input->baseUri),
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
