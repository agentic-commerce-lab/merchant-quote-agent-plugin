<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Command;

use MerchantQuoteAgentPlugin\Audit\DecisionEraserInterface;
use MerchantQuoteAgentPlugin\Audit\Erasure;
use MerchantQuoteAgentPlugin\Command\DecisionForgetCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The guard rails around an irreversible answer to an erasure request.
 * DecisionEraserTest owns what is actually cleared.
 */
#[CoversClass(DecisionForgetCommand::class)]
final class DecisionForgetCommandTest extends TestCase
{
    private const CUSTOMER = '0123456789abcdef0123456789abcdef';

    public function testItRefusesSomethingThatIsNotACustomerId(): void
    {
        $eraser = self::eraser();
        $tester = new CommandTester(new DecisionForgetCommand($eraser));

        $exit = $tester->execute(['customerId' => 'anna.mueller@acme.example']);

        self::assertSame(Command::INVALID, $exit);
        self::assertSame(0, $eraser->calls, 'Nothing may be cleared on the strength of a typo.');
    }

    public function testAnAnsweredNoLeavesEverythingStanding(): void
    {
        // Irreversible, so the default answer is no and the records stay.
        $eraser = self::eraser();
        $command = new DecisionForgetCommand($eraser);
        $tester = new CommandTester($command);
        $tester->setInputs(['no']);

        $exit = $tester->execute(['customerId' => self::CUSTOMER]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(0, $eraser->calls);
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
    }

    public function testForceSkipsTheQuestionAndReportsWhatItTouched(): void
    {
        $eraser = self::eraser(changed: 3);
        $tester = new CommandTester(new DecisionForgetCommand($eraser));

        $exit = $tester->execute(['customerId' => self::CUSTOMER, '--force' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(1, $eraser->calls);
        self::assertSame(self::CUSTOMER, $eraser->forgot);
        self::assertStringContainsString('3 decision record(s)', $tester->getDisplay());
        self::assertStringContainsString('2 trace event(s)', $tester->getDisplay());
    }

    public function testACustomerWithNoRecordsIsAnAnswerNotAFailure(): void
    {
        // A merchant answering an erasure request has to be able to say "there
        // was nothing about you here", so zero exits successfully and says so.
        $eraser = self::eraser(changed: 0);
        $tester = new CommandTester(new DecisionForgetCommand($eraser));

        $exit = $tester->execute(['customerId' => self::CUSTOMER, '--force' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('0 decision record(s)', $tester->getDisplay());
    }

    /** @return DecisionEraserInterface&object{calls: int, forgot: ?string} */
    private static function eraser(int $changed = 1): DecisionEraserInterface
    {
        return new class($changed) implements DecisionEraserInterface {
            public int $calls = 0;

            public ?string $forgot = null;

            public function __construct(
                private readonly int $changed,
            ) {}

            #[\Override]
            public function forget(string $customerId, ?Context $context = null): Erasure
            {
                ++$this->calls;
                $this->forgot = $customerId;

                return new Erasure($this->changed, 2);
            }
        };
    }
}
