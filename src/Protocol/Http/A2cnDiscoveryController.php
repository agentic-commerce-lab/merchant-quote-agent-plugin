<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnKeyStore;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
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
 * AgentFacingRoutes) — the mandate does not need the quote backend to be
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

    private const SESSION_PREFIX = '/a2cn';

    /** Acts are read from the session, never posted to the counterparty's endpoint. */
    private const DELIVERY = 'pull';

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

        return JsonEnvelope::cached(self::discoveryDocument(
            $identity,
            rtrim($request->getSchemeAndHttpHost(), characters: '/'),
            new \DateTimeImmutable(),
        ));
    }

    /**
     * The thirteen fields the spec enumerates, plus `delivery`. `verification_method` is what
     * lets a buyer agent pick a key without parsing the DID document at all;
     * `endpoint` is the session base `{base}/a2cn` every session-scoped URL
     * below is built from, while `.well-known` discovery documents remain
     * served at the domain root.
     *
     * The time arrives as a parameter, as everywhere else in this module —
     * `mandate()` is the other clock boundary, and both live in this
     * controller rather than in the units it calls.
     *
     * @return array<string, mixed>
     */
    private static function discoveryDocument(A2cnIdentity $identity, string $base, \DateTimeImmutable $at): array
    {
        $sessions = $base . self::SESSION_PREFIX;

        return [
            'a2cn_version' => self::A2CN_VERSION,
            'agent_id' => $identity->agentId,
            'did' => $identity->did,
            'verification_method' => $identity->verificationMethod,
            'mandate_methods' => [SellerMandateFactory::MANDATE_TYPE],
            'authorized_deal_types' => A2cnIdentity::DEAL_TYPES,
            'conformance_level' => A2cnIdentity::CONFORMANCE_LEVEL,
            // Named exactly as the signed mandate names it
            // (`principal_organization` there, `organization.name` here, per
            // the spec), so the two documents cannot disagree about who this
            // installation says it is.
            'organization' => ['name' => $identity->organizationName],
            // The base every SESSION url is built from, which is not the
            // host the well-known documents are served on: A2CN's client
            // reads discovery at the domain root and then builds
            // `{endpoint}/sessions/...`, so the prefix belongs here and
            // nowhere else. Serving those routes at the domain root instead
            // would claim `/sessions` on the merchant's storefront.
            'endpoint' => $sessions,
            'updated_at' => ProtocolTimestamp::of($at),
            'mandate_url' => $base . self::MANDATE_PATH,
            'records_url' => $sessions . '/records/{session_id}',
            'messages_url' => $sessions . '/sessions/{session_id}/messages',
            // NOT a spec field, and named as plainly as possible so it reads
            // as the proposal it is. A2CN's reference client POSTs the acts it
            // receives to the endpoint its counterparty advertises; this agent
            // never pushes, and a buyer that expected delivery would wait
            // forever with nothing in this document to tell it otherwise. An
            // unknown member costs a conformant client nothing, and we will
            // rename or drop it the moment the working group settles on a
            // spelling.
            'delivery' => self::DELIVERY,
        ];
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
