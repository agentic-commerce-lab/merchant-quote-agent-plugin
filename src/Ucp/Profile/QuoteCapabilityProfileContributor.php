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
 *
 * It also has to decide, not just re-add: `com.shopware.quote` is useless to a
 * buyer agent without an identity-linking access token, and the Agentic
 * Commerce plugin's `identity_linking` capability is off by default and set
 * per sales channel. Discovery is the only signal an agent gets — a
 * capability listed there but untokenable is a dead end the agent has no way
 * to diagnose; it will sign a request, get refused, and see a consent-page
 * error ("authorization link expired") that names none of this. Advertising
 * nothing is honest; advertising a capability nobody can reach is worse than
 * silence. This only affects what buyer agents see in discovery — the
 * merchant-side servicing loop never needs a buyer token and is untouched.
 */
final class QuoteCapabilityProfileContributor implements ProfileContributorInterface
{
    /**
     * The descriptor name Agentic Commerce's `identity_linking` capability is
     * published under. Not `identity_linking` itself — that is the config
     * key; the plugin maps it to this before the profile is built. Verified
     * against a live shop's discovery document, where it appears alongside
     * `dev.ucp.shopping.cart` and friends. A string literal rather than an
     * import: this plugin does not name Agentic Commerce classes (ADR 0001),
     * and a descriptor name isn't a class anyway.
     */
    private const IDENTITY_LINKING_CAPABILITY = 'dev.ucp.common.identity_linking';

    #[Override]
    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile
    {
        if (!$this->identityLinkingEnabled($input)) {
            return $profile;
        }

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

    /**
     * Mirrors `RuntimeConfiguration::isCapabilityEnabled()`: an empty list
     * means the shop has not restricted capabilities at all, not that none
     * are enabled. Treating empty as "off" would suppress the quote
     * descriptor on every shop that never touched capability restriction —
     * a far bigger regression than the one this fixes.
     */
    private function identityLinkingEnabled(ProfileBuildInput $input): bool
    {
        return (
            $input->enabledCapabilities === []
            || \in_array(self::IDENTITY_LINKING_CAPABILITY, $input->enabledCapabilities, strict: true)
        );
    }
}
