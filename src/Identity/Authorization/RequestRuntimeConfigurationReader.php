<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/**
 * The one place a Symfony request is turned into the SDK's own
 * `HttpRequest`, so the SDK can resolve the sales channel's runtime
 * configuration for it.
 *
 * Consent needs this because the `RequestContext` it hands Agentic Commerce
 * must carry a `RuntimeConfiguration`: `IdentityLinkingCapability::authorize()`
 * opens with `CapabilityGuard::assertEnabled()`, which reads
 * `$context->runtimeConfiguration` and treats `null` as "the capability is
 * disabled". A context built without one is refused on every grant, with a
 * message that blames the sales-channel configuration rather than the missing
 * field — so this is not an optional extra, it is what makes the grant path
 * work at all. `AgentConsentControllerTest` keeps that honest: its
 * identity-linking double replicates that guard.
 *
 * The adaptation mirrors the SDK bundle's own
 * `OAuthController::publicContext()` (method, absolute URI, lowercased
 * headers, ksorted stringified query, empty body) rather than inventing a
 * narrower one. Agentic Commerce's resolver happens to read only
 * `absoluteUri` today — it resolves the sales-channel domain from it — but the
 * interface is the seam, so filling it the way the SDK does keeps any other
 * implementation working. The body is deliberately empty, exactly as the SDK
 * passes it: `Request::getContent()` on an already-read form POST is a
 * needless way to fail, and no resolver reads it.
 *
 * The class exists so this adaptation lives in ONE place. Only
 * AgentConsentController holds a Symfony request; the collaborators it threads
 * the result through stay framework-free, which is what lets them move
 * upstream unchanged.
 */
final readonly class RequestRuntimeConfigurationReader
{
    public function __construct(
        private RuntimeConfigurationResolverInterface $resolver,
    ) {}

    /** @throws \JsonException */
    public function forRequest(Request $request): RuntimeConfiguration
    {
        $query = $request->query->all();
        ksort($query);

        return $this->resolver->resolve(
            new HttpRequest(
                $request->getMethod(),
                $request->getUri(),
                self::headers($request),
                array_map(static fn(mixed $value): string => \is_scalar($value)
                    ? (string) $value
                    : json_encode($value, \JSON_THROW_ON_ERROR), $query),
                '',
            ),
        );
    }

    /** @return array<string, string> */
    private static function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', array_map(
                static fn(?string $value): string => $value ?? '',
                $values,
            ));
        }

        return $headers;
    }
}
