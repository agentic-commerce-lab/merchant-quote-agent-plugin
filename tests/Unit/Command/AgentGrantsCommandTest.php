<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Command;

use MerchantQuoteAgentPlugin\Command\AgentGrantsCommand;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentGrantReaderInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AgentGrantsCommand::class)]
final class AgentGrantsCommandTest extends TestCase
{
    public function testRevokePassesTheExactCustomerAndClientIdAndReportsTheCount(): void
    {
        $reader = $this->fakeReader(revokeReturn: 2);
        $tester = new CommandTester(new AgentGrantsCommand($reader));

        $status = $tester->execute(['customerId' => 'cust-1', '--revoke' => 'client-1']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('cust-1', $reader->revokedCustomerId);
        self::assertSame('client-1', $reader->revokedClientId);
        self::assertStringContainsString('[OK] Revoked 2 grant(s) for client-1.', $tester->getDisplay());
    }

    public function testRevokingNothingWarnsInsteadOfClaimingSuccess(): void
    {
        $reader = $this->fakeReader(revokeReturn: 0);
        $tester = new CommandTester(new AgentGrantsCommand($reader));

        $status = $tester->execute(['customerId' => 'cust-1', '--revoke' => 'client-1']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('[WARNING] Revoked 0 grant(s) for client-1.', $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    public function testRevokeWithoutACustomerIdIsRefusedAndNeverCallsRevoke(): void
    {
        $reader = $this->fakeReader(revokeReturn: 5);
        $tester = new CommandTester(new AgentGrantsCommand($reader));

        $status = $tester->execute(['--revoke' => 'client-1']);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Revoking needs a customer id', $tester->getDisplay());
        self::assertNull($reader->revokedCustomerId);
        self::assertNull($reader->revokedClientId);
    }

    public function testAnEmptyGrantListSaysSoRatherThanRenderingAnEmptyTable(): void
    {
        $reader = $this->fakeReader(grants: []);
        $tester = new CommandTester(new AgentGrantsCommand($reader));

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('No grants.', $tester->getDisplay());
        self::assertSame(null, $reader->requestedCustomerId);
    }

    public function testListingRendersTheGrantsIdentifyingFields(): void
    {
        $reader = $this->fakeReader(grants: [[
            'customer_id' => 'cust-1',
            'client_id' => 'https://agent.example/.well-known/ucp',
            'scope' => 'dev.ucp.shopping.order:read',
            'expires_at' => 1788300000,
            'revoked' => true,
        ]]);
        $tester = new CommandTester(new AgentGrantsCommand($reader));

        $status = $tester->execute(['customerId' => 'cust-1']);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('cust-1', $display);
        self::assertStringContainsString('https://agent.example/.well-known/ucp', $display);
        self::assertStringContainsString('dev.ucp.shopping.order:read', $display);
        self::assertStringContainsString('yes', $display);
        self::assertSame('cust-1', $reader->requestedCustomerId);
    }

    /**
     * @param list<array{customer_id: string, client_id: string, scope: string, expires_at: int, revoked: bool}> $grants
     */
    private function fakeReader(array $grants = [], int $revokeReturn = 0): AgentGrantReaderInterface
    {
        return new class($grants, $revokeReturn) implements AgentGrantReaderInterface {
            public ?string $requestedCustomerId = null;

            public ?string $revokedCustomerId = null;

            public ?string $revokedClientId = null;

            /**
             * @param list<array{customer_id: string, client_id: string, scope: string, expires_at: int, revoked: bool}> $grants
             */
            public function __construct(
                private readonly array $grants,
                private readonly int $revokeReturn,
            ) {}

            #[Override]
            public function grantsFor(?string $customerId): array
            {
                $this->requestedCustomerId = $customerId;

                return $this->grants;
            }

            #[Override]
            public function revoke(string $customerId, string $clientId): int
            {
                $this->revokedCustomerId = $customerId;
                $this->revokedClientId = $clientId;

                return $this->revokeReturn;
            }
        };
    }
}
