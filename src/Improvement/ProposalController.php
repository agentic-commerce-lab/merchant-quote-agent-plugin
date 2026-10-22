<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Strategy\VersionStatus;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * How a human turns a nightly proposal into (or out of) the negotiation path.
 *
 * The number a version is accepted under is assigned HERE, inside one
 * transaction, with the target row and then the whole lineage locked via
 * `SELECT ... FOR UPDATE` -- never computed by the browser. Two accepts in
 * the same minute, or an accept racing a manual edit of the same strategy,
 * would otherwise both read the same MAX(version) and collide on
 * `uniq.mqasv.strategy_version`, surfacing to the merchant as a DAL exception
 * on a button that should simply have worked. The row lock makes the
 * read-then-write atomic; the lineage lock (keyed on `strategy_id`, not just
 * the one row) extends that to a sibling proposal accepted concurrently.
 *
 * The actual status/version write goes through `EntityRepository::update()`,
 * not a raw UPDATE on the same Connection, so StrategyWriteGuard's
 * `PreWriteValidationEvent` subscriber is what validates the transition --
 * the one place that rule lives, per its own docblock. Nesting that DAL write
 * inside this class's own `Connection::transactional()` call works because
 * both resolve the same underlying connection from the container: the write
 * commits (or rolls back) as part of the very transaction holding the lock.
 */
final readonly class ProposalController
{
    private const TABLE = 'merchant_quote_agent_strategy_version';

    public function __construct(
        private Connection $connection,
        private EntityRepository $versions,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \Throwable
     */
    #[Route(
        path: '/api/_action/merchant-quote-agent/proposal/{versionId}/accept',
        name: 'api.action.merchant_quote_agent.proposal_accept',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_strategy_version:update']],
        methods: ['POST'],
    )]
    public function accept(string $versionId, Context $context): Response
    {
        return $this->transition($versionId, VersionStatus::Active, $context);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \Throwable
     */
    #[Route(
        path: '/api/_action/merchant-quote-agent/proposal/{versionId}/reject',
        name: 'api.action.merchant_quote_agent.proposal_reject',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_strategy_version:update']],
        methods: ['POST'],
    )]
    public function reject(string $versionId, Context $context): Response
    {
        return $this->transition($versionId, VersionStatus::Rejected, $context);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \Throwable
     */
    private function transition(string $versionId, VersionStatus $target, Context $context): Response
    {
        if (!Uuid::isValid($versionId)) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $response = $this->connection->transactional(
            /** @throws \Doctrine\DBAL\Exception */
            fn(): Response => $this->lockAndDecide($versionId, $target, $context),
        );

        \assert($response instanceof Response, description: 'transactional() returns whatever the closure returns.');

        return $response;
    }

    /** @throws \Doctrine\DBAL\Exception */
    private function lockAndDecide(string $versionId, VersionStatus $target, Context $context): Response
    {
        $row = $this->lockedRow($versionId);

        if ($row === null) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        if ($row['status'] !== VersionStatus::Proposed->value) {
            return new Response(status: Response::HTTP_CONFLICT);
        }

        $this->versions->update([$this->payload($versionId, $row['strategy_id'], $target)], $context);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{status: string, strategy_id: string}|null
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function lockedRow(string $versionId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT `status`, `strategy_id` FROM `'
        . self::TABLE
        . '` WHERE `id` = :id FOR UPDATE', ['id' => Uuid::fromHexToBytes($versionId)]);

        /** @var array{status: string, strategy_id: string}|false $row */
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function payload(string $versionId, string $strategyId, VersionStatus $target): array
    {
        $payload = [
            'id' => $versionId,
            'status' => $target->value,
            'decidedAt' => new \DateTimeImmutable(),
        ];

        if ($target === VersionStatus::Active) {
            $payload['version'] = $this->nextVersion($strategyId);
        }

        return $payload;
    }

    /**
     * Locks the whole lineage (every row sharing `strategyId`), not just the
     * one being accepted -- see the class docblock for why.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function nextVersion(string $strategyId): int
    {
        $max = $this->connection->fetchOne('SELECT MAX(`version`) FROM `'
        . self::TABLE
        . '` WHERE `strategy_id` = :strategyId FOR UPDATE', ['strategyId' => $strategyId]);

        return (int) $max + 1;
    }
}
