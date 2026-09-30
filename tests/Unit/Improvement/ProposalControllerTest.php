<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Improvement\ProposalController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;

/**
 * The controller has two collaborators -- Connection for the row lock and the
 * max-version read, EntityRepository for the guarded write -- not the single
 * "connection" the task brief's illustrative test sketch showed. A DAL write
 * is what makes StrategyWriteGuard run at all (it subscribes to
 * PreWriteValidationEvent, which only a repository write dispatches; a raw
 * UPDATE on the same Connection would bypass the guard entirely), so the
 * second collaborator is not optional. See the task-12 report for why this
 * departs from the brief's literal constructor signature.
 *
 * `Connection::transactional()` is stubbed to just invoke its closure
 * synchronously -- a unit test has no real InnoDB to hand out row locks, so it
 * can prove the controller CONSTRUCTS the locking queries and reacts to their
 * result correctly, not that the lock itself prevents a concurrent race. That
 * needs a live kernel against a real table, which this suite (unit only, see
 * phpunit.xml.dist) cannot provide.
 */
final class ProposalControllerTest extends TestCase
{
    private const VERSION_ID = 'aaaabbbbccccddddeeeeffff00001111';

    private const STRATEGY_ID = '11112222333344445555666677778888';

    public function testAcceptingAssignsTheNextVersionNumber(): void
    {
        $connection = $this->connectionWhereMaxVersionIs(7);
        $versions = $this->createMock(EntityRepository::class);

        $captured = null;
        $versions
            ->expects(self::once())
            ->method('update')
            ->willReturnCallback(function (array $data) use (&$captured): EntityWrittenContainerEvent {
                $captured = $data[0];

                return $this->createMock(EntityWrittenContainerEvent::class);
            });

        $controller = new ProposalController($connection, $versions);

        $response = $controller->accept(self::VERSION_ID, Context::createDefaultContext());

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(8, $captured['version']);
        self::assertSame('active', $captured['status']);
    }

    public function testItRefusesARowThatIsNotAProposal(): void
    {
        $versions = $this->createMock(EntityRepository::class);
        $versions->expects(self::never())->method('update');

        $controller = new ProposalController($this->connectionWhereStatusIs('active'), $versions);

        self::assertSame(409, $controller->accept(self::VERSION_ID, Context::createDefaultContext())->getStatusCode());
    }

    public function testAnUnknownIdIsFourOhFour(): void
    {
        $versions = $this->createMock(EntityRepository::class);
        $versions->expects(self::never())->method('update');

        $controller = new ProposalController($this->connectionWithNoSuchRow(), $versions);

        self::assertSame(404, $controller->accept(self::VERSION_ID, Context::createDefaultContext())->getStatusCode());
    }

    public function testRejectingNeedsNoVersionNumber(): void
    {
        $connection = $this->connectionWhereStatusIs('proposed');
        $versions = $this->createMock(EntityRepository::class);

        $captured = null;
        $versions
            ->expects(self::once())
            ->method('update')
            ->willReturnCallback(function (array $data) use (&$captured): EntityWrittenContainerEvent {
                $captured = $data[0];

                return $this->createMock(EntityWrittenContainerEvent::class);
            });

        $controller = new ProposalController($connection, $versions);

        $response = $controller->reject(self::VERSION_ID, Context::createDefaultContext());

        self::assertSame(204, $response->getStatusCode());
        self::assertArrayNotHasKey('version', $captured);
        self::assertSame('rejected', $captured['status']);
    }

    private function connectionWhereMaxVersionIs(int $max): Connection&\PHPUnit\Framework\MockObject\MockObject
    {
        $connection = $this->connectionWhereStatusIs('proposed');
        $connection->method('fetchOne')->willReturn($max);

        return $connection;
    }

    private function connectionWhereStatusIs(string $status): Connection&\PHPUnit\Framework\MockObject\MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn(\Closure $fn): mixed => $fn());
        $connection
            ->method('fetchAssociative')
            ->willReturn([
                'status' => $status,
                'strategy_id' => self::STRATEGY_ID,
            ]);

        return $connection;
    }

    private function connectionWithNoSuchRow(): Connection&\PHPUnit\Framework\MockObject\MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn(\Closure $fn): mixed => $fn());
        $connection->method('fetchAssociative')->willReturn(false);

        return $connection;
    }
}
