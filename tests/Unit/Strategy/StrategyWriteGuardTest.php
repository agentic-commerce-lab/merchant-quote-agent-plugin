<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Strategy\BuiltInStrategies;
use MerchantQuoteAgentPlugin\Strategy\StrategyWriteGuard;
use MerchantQuoteAgentPlugin\Strategy\VersionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;

/**
 * The guard is the only thing standing between an admin API token and the two
 * (now three, see StrategyWriteGuard) invariants this feature's audit trail
 * rests on. The administration is not in that path, so a UI-only guard would
 * not hold.
 *
 * WriteCommand has no getDefinition() (it consumes the EntityDefinition in its
 * constructor and exposes getEntityName() instead), so the command mock stubs
 * getEntityName(), getPrimaryKey() and getPayload() directly rather than
 * mocking an EntityDefinition too.
 */
final class StrategyWriteGuardTest extends TestCase
{
    private const VERSION_ID = 'aaaabbbbccccddddeeeeffff00001111';

    /** The invariants that predate Task 3: version rows and built-ins never move. */
    #[DataProvider('preExistingInvariantCases')]
    public function testPreExistingInvariantsStillHold(
        string $entity,
        string $commandClass,
        string $id,
        bool $expectRejected,
    ): void {
        $event = $this->event($entity, $commandClass, $id, []);

        $this->guard()->preValidate($event);

        $exceptionCount = \count($event->getExceptions()->getExceptions());
        self::assertSame($expectRejected, $exceptionCount > 0);
    }

    /** @return iterable<string, array{string, class-string, string, bool}> */
    public static function preExistingInvariantCases(): iterable
    {
        yield 'version update' => [
            'merchant_quote_agent_strategy_version',
            UpdateCommand::class,
            bin2hex(random_bytes(16)),
            true,
        ];
        yield 'version delete' => [
            'merchant_quote_agent_strategy_version',
            DeleteCommand::class,
            bin2hex(random_bytes(16)),
            true,
        ];
        yield 'built-in update' => [
            'merchant_quote_agent_strategy',
            UpdateCommand::class,
            BuiltInStrategies::FAST_CLOSE,
            true,
        ];
        yield 'built-in delete' => [
            'merchant_quote_agent_strategy',
            DeleteCommand::class,
            BuiltInStrategies::MARGIN_DEFENDER,
            true,
        ];
        yield 'custom strategy update' => [
            'merchant_quote_agent_strategy',
            UpdateCommand::class,
            bin2hex(random_bytes(16)),
            false,
        ];
        yield 'version insert' => [
            'merchant_quote_agent_strategy_version',
            InsertCommand::class,
            bin2hex(random_bytes(16)),
            false,
        ];
    }

    public function testItAdmitsAcceptingAProposal(): void
    {
        $event = $this->event('merchant_quote_agent_strategy_version', UpdateCommand::class, self::VERSION_ID, [
            'status' => VersionStatus::Active->value,
            'version' => 4,
            'decided_at' => '2026-09-21 03:00:00.000',
        ]);

        $this->guard(VersionStatus::Proposed)->preValidate($event);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testItRefusesEditingAProposalsPrompt(): void
    {
        $event = $this->event('merchant_quote_agent_strategy_version', UpdateCommand::class, self::VERSION_ID, [
            'prompt' => 'something else',
        ]);

        $this->guard(VersionStatus::Proposed)->preValidate($event);

        self::assertCount(1, $event->getExceptions()->getExceptions());
    }

    public function testItRefusesAnyUpdateToAnActiveRow(): void
    {
        $event = $this->event('merchant_quote_agent_strategy_version', UpdateCommand::class, self::VERSION_ID, [
            'status' => VersionStatus::Rejected->value,
        ]);

        $this->guard(VersionStatus::Active)->preValidate($event);

        self::assertCount(1, $event->getExceptions()->getExceptions());
    }

    public function testItRefusesReturningARejectedRowToProposed(): void
    {
        $event = $this->event('merchant_quote_agent_strategy_version', UpdateCommand::class, self::VERSION_ID, [
            'status' => VersionStatus::Proposed->value,
        ]);

        $this->guard(VersionStatus::Rejected)->preValidate($event);

        self::assertCount(1, $event->getExceptions()->getExceptions());
    }

    /**
     * @param class-string $commandClass
     * @param array<string, mixed> $payload
     */
    private function event(string $entity, string $commandClass, string $id, array $payload): PreWriteValidationEvent
    {
        $command = $this->createMock($commandClass);
        $command->method('getEntityName')->willReturn($entity);
        $command->method('getPrimaryKey')->willReturn(['id' => hex2bin($id)]);
        $command->method('getPayload')->willReturn($payload);

        return new PreWriteValidationEvent(
            WriteContext::createFromContext(Context::createDefaultContext()),
            [$command],
        );
    }

    /**
     * A guard whose connection reports self::VERSION_ID at the given status, or
     * finds no known row at all when $currentStatus is omitted -- every id the
     * pre-existing-invariant cases use is then a stranger to it.
     */
    private function guard(?VersionStatus $currentStatus = null): StrategyWriteGuard
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllKeyValue')
            ->willReturn($currentStatus === null ? [] : [self::VERSION_ID => $currentStatus->value]);

        return new StrategyWriteGuard($connection);
    }
}
