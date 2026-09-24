<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Create, merge and delete mirror SwagCommercial's
 * StorefrontQuoteDraftVersionManager: createVersion() off a live context,
 * merge() in system scope, and delete as "delete the quote in the version's
 * context, then the version row". All three run in system scope because the
 * version is the agent's working copy, not anybody's edit.
 */
final readonly class QuoteDraftVersions implements QuoteDraftVersionsInterface
{
    public const VERSION_NAME = 'merchant-quote-agent-draft';

    /**
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quotes
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $versions
     */
    public function __construct(
        private EntityRepository $quotes,
        private EntityRepository $versions,
        private ContextBoundGateways $gateways,
    ) {}

    #[\Override]
    public function create(string $quoteId): string
    {
        return $this->quotes->createVersion($quoteId, AgentContext::create(), self::VERSION_NAME);
    }

    #[\Override]
    public function exists(string $versionId): bool
    {
        return (
            Uuid::isValid($versionId)
            && $this->versions->searchIds(new Criteria([$versionId]), Context::createDefaultContext())->firstId()
            !== null
        );
    }

    #[\Override]
    public function gateway(string $versionId): QuoteGatewayInterface
    {
        $gateway = $this->gateways->forContext(AgentContext::forVersion(self::draft($versionId)));

        if ($gateway === null) {
            throw DraftVersionUnavailable::forVersion($versionId);
        }

        return $gateway;
    }

    #[\Override]
    public function merge(string $versionId): void
    {
        $this->quotes->merge(self::draft($versionId), AgentContext::create());
    }

    #[\Override]
    public function delete(string $quoteId, string $versionId): void
    {
        $draft = self::draft($versionId);
        $this->quotes->delete([['id' => $quoteId]], AgentContext::forVersion($draft));
        $this->versions->delete([['id' => $draft]], Context::createDefaultContext());
    }

    /**
     * Mirrors StorefrontQuoteDraftVersionManager::assertDraftVersionId(). A
     * gateway bound to the live version writes the proposal straight onto the
     * buyer-visible quote, one bound to the snapshot lane rewrites
     * SwagCommercial's "last sent" copy, a delete in the live version's
     * context deletes the live quote, and a merge of the snapshot lane replays
     * that copy over it. Our ids only ever come from create(), but they are
     * stored and read back.
     *
     * @throws NotADraftVersion
     */
    private static function draft(string $versionId): string
    {
        if (
            !Uuid::isValid($versionId)
            || $versionId === Defaults::LIVE_VERSION
            || $versionId === QuoteVersionResolver::SNAPSHOT_VERSION_ID
        ) {
            throw NotADraftVersion::forId($versionId);
        }

        return $versionId;
    }
}
