<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The three `/.well-known/` A2CN discovery documents: the agent's own
 * discovery document, its did:web document, and its signed seller mandate.
 *
 * Every response resolves the publishing identity from the REQUEST's host
 * (`$request->getHttpHost()`) and sales-channel id
 * (`PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID`), not from a fixed
 * configuration value — one installation answers on every storefront domain
 * it serves, and each domain must publish a did:web document that matches
 * the host it was fetched from. `getHttpHost()`, not `getHost()`: Symfony's
 * `Request::getHost()` always strips the port, while `SalesChannelHostReader`
 * (which builds the identity the emitter signs acts under) keeps a
 * non-default one — using `getHost()` here would publish a did:web document
 * naming a different DID than the one this installation's acts are actually
 * signed under, so a conformant counterparty resolving the act's
 * `sender_verification_method` would reject the document outright.
 *
 * These documents change only when the merchant reconfigures (a new key, a
 * new organization name, a new negotiation policy), so — unlike the act
 * chain and the records, which are derived per request and marked
 * `no-store` — they carry `Cache-Control: public, max-age=300`.
 *
 * A missing signing key answers `503 {"status":"signing_key_missing"}`
 * rather than a stack trace: a shop that never generated a key should say
 * so, not 500. Registered OUTSIDE the CommercialAvailability gate (see
 * routes.php) — the mandate does not need the quote backend to be
 * publishable.
 *
 * Identity resolution (shared by all three routes) and mandate assembly
 * (settings lookup, building and signing) are split out to
 * MandateDocumentResponder — a real seam, and what keeps this class under
 * the per-class cyclomatic-complexity gate.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final readonly class A2cnDiscoveryController
{
    public const DISCOVERY_PATH = '/.well-known/a2cn-agent';

    public const DID_PATH = '/.well-known/did.json';

    public const MANDATE_PATH = '/.well-known/a2cn-seller-mandate';

    private const A2CN_VERSION = '0.2';

    public function __construct(
        private A2cnIdentityResolver $identities,
        private A2cnKeyStore $keys,
        private MandateDocumentResponder $mandateDocument,
    ) {}

    #[Route(path: self::DISCOVERY_PATH, name: 'frontend.merchant_quote_agent.a2cn.discovery', methods: ['GET'])]
    public function discovery(Request $request): JsonResponse
    {
        $identity = $this->resolveIdentity($request);
        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        $base = rtrim($request->getSchemeAndHttpHost(), characters: '/');

        return JsonEnvelope::cached([
            'a2cn_version' => self::A2CN_VERSION,
            'agent_id' => $identity->agentId,
            'did' => $identity->did,
            'mandate_methods' => [SellerMandateFactory::MANDATE_TYPE],
            'conformance_level' => A2cnIdentity::CONFORMANCE_LEVEL,
            'mandate_url' => $base . self::MANDATE_PATH,
            'records_url' => $base . '/a2cn/records/{session_id}',
        ]);
    }

    #[Route(path: self::DID_PATH, name: 'frontend.merchant_quote_agent.a2cn.did', methods: ['GET'])]
    public function didDocument(Request $request): JsonResponse
    {
        $identity = $this->resolveIdentity($request);
        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        return JsonEnvelope::cached([
            '@context' => ['https://www.w3.org/ns/did/v1'],
            'id' => $identity->did,
            'verificationMethod' => [[
                'id' => $identity->verificationMethod,
                'type' => 'JsonWebKey2020',
                'controller' => $identity->did,
                'publicKeyJwk' => $this->keys->publicJwk($this->keys->current()),
            ]],
            'authentication' => [$identity->verificationMethod],
            'assertionMethod' => [$identity->verificationMethod],
        ]);
    }

    #[Route(path: self::MANDATE_PATH, name: 'frontend.merchant_quote_agent.a2cn.mandate', methods: ['GET'])]
    public function mandate(Request $request): JsonResponse
    {
        $identity = $this->resolveIdentity($request);
        if ($identity instanceof JsonResponse) {
            return $identity;
        }

        return $this->mandateDocument->respond($identity, self::salesChannelId($request), new \DateTimeImmutable());
    }

    private function resolveIdentity(Request $request): A2cnIdentity|JsonResponse
    {
        try {
            return $this->identities->forHost($request->getHttpHost(), self::salesChannelId($request));
        } catch (MissingSigningKey) {
            return JsonEnvelope::noStore(['status' => 'signing_key_missing'], 503);
        } catch (\Doctrine\DBAL\Exception) {
            // Same posture as MissingSigningKey: a database hiccup resolving
            // this installation's own identity must not surface as a 500.
            return JsonEnvelope::noStore(['status' => 'identity_unavailable'], 503);
        }
    }

    private static function salesChannelId(Request $request): ?string
    {
        $value = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);

        return \is_string($value) ? $value : null;
    }
}
