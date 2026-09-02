<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthMetadata;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenResponse;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\RequestContext;

/**
 * THE ONE double for Agentic Commerce's identity-linking capability, shared by
 * every test on the grant path.
 *
 * It exists as one class, and it replicates AC's real first statement, for a
 * specific reason: a double that merely records what it was handed let a
 * feature-breaking bug through every task review. AC's
 * `IdentityLinkingCapability::authorize()` begins with
 * `CapabilityGuard::assertEnabled($context, DESCRIPTOR_IDENTITY_LINKING, …)`,
 * which delegates to `UcpCapabilityCatalog::isEnabled()`:
 *
 *     if (null === $runtimeConfiguration) { return false; }
 *
 *     return in_array($descriptorName, $runtimeConfiguration->enabledCapabilities, true);
 *
 * A double without that guard passes happily on a context whose
 * `runtimeConfiguration` is null — which the real AC refuses on every grant,
 * with a message blaming the sales-channel configuration. Two green suites, one
 * dead feature. So {@see guarded()} throws exactly where AC throws.
 *
 * Note the difference from the SDK's own
 * `RuntimeConfiguration::isCapabilityEnabled()`, which treats an EMPTY
 * `enabledCapabilities` as "everything is enabled". AC does not go through
 * that method; its catalogue requires the descriptor to be listed. This
 * replicates AC, because AC is what runs in production.
 */
final class IdentityLinkingCapabilityFixture
{
    /** `Swag\AgenticCommerce\Ucp\Capability\UcpCapabilityCatalog::DESCRIPTOR_IDENTITY_LINKING`. */
    public const DESCRIPTOR = 'dev.ucp.common.identity_linking';

    public const REDIRECT_TO = 'https://agent.example/callback?code=granted';

    private function __construct() {}

    /** What AgentConsentController's RequestRuntimeConfigurationReader yields on a correctly configured channel. */
    public static function runtimeConfiguration(): RuntimeConfiguration
    {
        return new RuntimeConfiguration('2026-04-08', 'https://shop.example', enabledCapabilities: [self::DESCRIPTOR]);
    }

    /** A channel with identity linking switched off — AC's own refusal, not a broken context. */
    public static function capabilityDisabledConfiguration(): RuntimeConfiguration
    {
        return new RuntimeConfiguration(
            '2026-04-08',
            'https://shop.example',
            enabledCapabilities: [
                'dev.ucp.shopping.cart',
            ],
        );
    }

    /**
     * A recording double that enforces AC's capability guard before recording.
     *
     * `$authorizeResult` defaults to a well-formed result; pass `[]` or an
     * empty `redirect_to` to exercise the completer's contract check.
     * `$refusal` makes authorize() throw a UcpException the way AC does when
     * one of its own binding rules refuses, so the caller's handling of an
     * authoritative refusal can be tested.
     *
     * @param array<string, mixed>|null $authorizeResult
     *
     * @return IdentityLinkingCapabilityInterface&object{received: ?OAuthAuthorizationRequest, receivedContext: ?RequestContext}
     */
    public static function guarded(?array $authorizeResult = null, ?\Throwable $refusal = null): object
    {
        return new class($authorizeResult ?? ['redirect_to' => self::REDIRECT_TO], $refusal) implements
            IdentityLinkingCapabilityInterface {
            public ?OAuthAuthorizationRequest $received = null;

            public ?RequestContext $receivedContext = null;

            /** @param array<string, mixed> $authorizeResult */
            public function __construct(
                private readonly array $authorizeResult,
                private readonly ?\Throwable $refusal,
            ) {}

            public function describe(): CapabilityDescriptor
            {
                throw new \LogicException('Not needed by this double.');
            }

            public function getMetadata(RequestContext $context): OAuthMetadata
            {
                throw new \LogicException('Not needed by this double.');
            }

            /** @return array<string, mixed> */
            public function authorize(OAuthAuthorizationRequest $request, RequestContext $context): array
            {
                // AC's first statement, replicated. Everything below it is
                // unreachable in production until this passes.
                $enabled =
                    $context->runtimeConfiguration !== null
                    && \in_array(
                        IdentityLinkingCapabilityFixture::DESCRIPTOR,
                        $context->runtimeConfiguration->enabledCapabilities,
                        true,
                    );

                if (!$enabled) {
                    throw new UnsupportedCapabilityException(
                        'Identity linking capability is disabled for this sales channel.',
                    );
                }

                $this->received = $request;
                $this->receivedContext = $context;

                if ($this->refusal !== null) {
                    throw $this->refusal;
                }

                return $this->authorizeResult;
            }

            public function issueToken(OAuthTokenRequest $request, RequestContext $context): OAuthTokenResponse
            {
                throw new \LogicException('Not needed by this double.');
            }
        };
    }
}
