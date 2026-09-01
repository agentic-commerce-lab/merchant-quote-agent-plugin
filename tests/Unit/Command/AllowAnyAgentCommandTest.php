<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Command\AllowAnyAgentCommand;
use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AllowAnyAgentCommand::class)]
final class AllowAnyAgentCommandTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    public function testItListsEveryChannelsStateWithoutArguments(): void
    {
        $tester = new CommandTester($this->command(storedFlag: true));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $output = $tester->getDisplay();
        self::assertStringContainsString('Storefront', $output);
        self::assertStringContainsString(self::SALES_CHANNEL_ID, $output);
        self::assertStringContainsString('on', $output);
    }

    public function testItTurnsTheFlagOnForOneChannelAndWarns(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config
            ->expects(self::once())
            ->method('set')
            ->with(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, true, self::SALES_CHANNEL_ID);

        $tester = new CommandTester($this->command(storedFlag: false, systemConfig: $config));
        $tester->execute(['salesChannelId' => self::SALES_CHANNEL_ID, '--on' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('any agent', $tester->getDisplay());
    }

    public function testItTurnsTheFlagOff(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config
            ->expects(self::once())
            ->method('set')
            ->with(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, false, self::SALES_CHANNEL_ID);

        $tester = new CommandTester($this->command(storedFlag: true, systemConfig: $config));
        $tester->execute(['salesChannelId' => self::SALES_CHANNEL_ID, '--off' => true]);

        $tester->assertCommandIsSuccessful();
    }

    public function testItRefusesAnUnknownSalesChannel(): void
    {
        $tester = new CommandTester($this->command(storedFlag: false));

        $tester->execute(['salesChannelId' => 'ffffffffffffffffffffffffffffffff', '--on' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unknown sales channel', $tester->getDisplay());
    }

    public function testItRefusesBothSwitchesAtOnce(): void
    {
        $tester = new CommandTester($this->command(storedFlag: false));

        $tester->execute(['salesChannelId' => self::SALES_CHANNEL_ID, '--on' => true, '--off' => true]);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function testItRefusesAChannelIdWithoutASwitch(): void
    {
        $tester = new CommandTester($this->command(storedFlag: false));

        $tester->execute(['salesChannelId' => self::SALES_CHANNEL_ID]);

        self::assertSame(1, $tester->getStatusCode());
    }

    private function command(bool $storedFlag, ?SystemConfigService $systemConfig = null): AllowAnyAgentCommand
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([
                ['id' => self::SALES_CHANNEL_ID, 'name' => 'Storefront'],
            ]);

        $config = $systemConfig ?? $this->createMock(SystemConfigService::class);
        if ($systemConfig === null) {
            $config->method('get')->willReturn($storedFlag);
        }

        return new AllowAnyAgentCommand($connection, $config, new AgentAccessFlags($config));
    }
}
