<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

/**
 * Who this installation is, in A2CN terms.
 *
 * One key, many domains: the DID is derived from the domain a request arrived
 * on, the kid and key material do not change with it.
 */
final readonly class A2cnIdentity
{
    public const AGENT_ID = 'merchant-quote-agent';

    /** The one deal type this agent is authorized for. */
    public const DEAL_TYPES = ['goods_procurement'];

    /**
     * Honest self-declaration: the act chain and the end-of-session records are
     * served per session, not just a discovery document.
     */
    public const CONFORMANCE_LEVEL = 'acts';

    public function __construct(
        public string $did,
        public string $verificationMethod,
        public string $organizationName,
        public string $agentId = self::AGENT_ID,
    ) {}

    public static function forHost(string $host, string $kid, string $organizationName): self
    {
        // Both identity paths funnel through here — the emitter's
        // SalesChannelHostReader and the controller's Request::getHttpHost() —
        // so the root dot of a fully qualified name is stripped here, once.
        // getHttpHost() lower-cases and drops a default port but leaves that
        // dot, so a request to `shop.example.` would otherwise publish a
        // did:web the shop's own acts never name.
        //
        // did:web uses ':' as its own segment separator, so a port has to be
        // percent-encoded or the authority would be read as a path.
        $authority = str_replace(':', '%3A', rtrim($host, '.'));
        $did = 'did:web:' . $authority;

        return new self($did, $did . '#' . $kid, $organizationName);
    }
}
