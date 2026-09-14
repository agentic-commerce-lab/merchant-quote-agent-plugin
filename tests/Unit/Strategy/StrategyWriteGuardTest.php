<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Strategy\BuiltInStrategies;
use MerchantQuoteAgentPlugin\Strategy\StrategyWriteGuard;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;

/**
 * The guard is the only thing standing between an admin API token and the two
 * invariants this feature's audit trail rests on. The administration is not in
 * that path, so a UI-only guard would not hold.
 *
 * WriteCommand has no getDefinition() (it consumes the EntityDefinition in its
 * constructor and exposes getEntityName() instead), so the command mock stubs
 * getEntityName() directly rather than mocking an EntityDefinition too.
 */
final class StrategyWriteGuardTest extends TestCase
{
    public function testAVersionUpdateIsRejected(): void
    {
        $this->expectViolation(
            'merchant_quote_agent_strategy_version',
            UpdateCommand::class,
            bin2hex(random_bytes(16)),
        );
    }

    public function testAVersionDeleteIsRejected(): void
    {
        $this->expectViolation(
            'merchant_quote_agent_strategy_version',
            DeleteCommand::class,
            bin2hex(random_bytes(16)),
        );
    }

    public function testABuiltInStrategyUpdateIsRejected(): void
    {
        $this->expectViolation('merchant_quote_agent_strategy', UpdateCommand::class, BuiltInStrategies::FAST_CLOSE);
    }

    public function testABuiltInStrategyDeleteIsRejected(): void
    {
        $this->expectViolation(
            'merchant_quote_agent_strategy',
            DeleteCommand::class,
            BuiltInStrategies::MARGIN_DEFENDER,
        );
    }

    public function testACustomStrategyUpdateIsAllowed(): void
    {
        $event = $this->event('merchant_quote_agent_strategy', UpdateCommand::class, bin2hex(random_bytes(16)));

        (new StrategyWriteGuard())->preValidate($event);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    public function testAVersionInsertIsAllowed(): void
    {
        $event = $this->event('merchant_quote_agent_strategy_version', InsertCommand::class, bin2hex(random_bytes(16)));

        (new StrategyWriteGuard())->preValidate($event);

        self::assertCount(0, $event->getExceptions()->getExceptions());
    }

    /** @param class-string $commandClass */
    private function expectViolation(string $entity, string $commandClass, string $id): void
    {
        $event = $this->event($entity, $commandClass, $id);

        (new StrategyWriteGuard())->preValidate($event);

        self::assertNotCount(
            0,
            $event->getExceptions()->getExceptions(),
            $commandClass . ' on ' . $entity . ' should have been rejected.',
        );
    }

    /** @param class-string $commandClass */
    private function event(string $entity, string $commandClass, string $id): PreWriteValidationEvent
    {
        $command = $this->createMock($commandClass);
        $command->method('getEntityName')->willReturn($entity);
        $command->method('getPrimaryKey')->willReturn(['id' => hex2bin($id)]);

        return new PreWriteValidationEvent(
            WriteContext::createFromContext(Context::createDefaultContext()),
            [$command],
        );
    }
}
