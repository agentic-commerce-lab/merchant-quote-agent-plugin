<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

#[CoversClass(ExportPseudonym::class)]
final class ExportPseudonymTest extends TestCase
{
    public function testTheSameIdPseudonymizesToTheSameStringUnderTheSameSalt(): void
    {
        $pseudonym = new ExportPseudonym('a-salt');

        self::assertSame(
            $pseudonym->of('0191d3d0a0b071bd9c1a0d9d1a3f9f01'),
            $pseudonym->of('0191d3d0a0b071bd9c1a0d9d1a3f9f01'),
        );
    }

    public function testADifferentSaltGivesADifferentPseudonymForTheSameId(): void
    {
        $id = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

        self::assertNotSame((new ExportPseudonym('one'))->of($id), (new ExportPseudonym('two'))->of($id));
    }

    public function testThePseudonymIsNotTheIdAndIsFixedWidthHex(): void
    {
        $id = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';
        $pseudonym = (new ExportPseudonym('a-salt'))->of($id);

        self::assertNotNull($pseudonym);
        self::assertNotSame($id, $pseudonym);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pseudonym);
    }

    public function testAnAbsentIdStaysAbsent(): void
    {
        $pseudonym = new ExportPseudonym('a-salt');

        self::assertNull($pseudonym->of(null));
        self::assertNull($pseudonym->of(''));
    }

    public function testAStoredSaltIsReusedRatherThanReplaced(): void
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with(ExportPseudonym::CONFIG_KEY)->willReturn('the-stored-salt');
        $config->expects(self::never())->method('set');

        self::assertSame(
            (new ExportPseudonym('the-stored-salt'))->of('abc'),
            ExportPseudonym::forShop($config)->of('abc'),
        );
    }

    public function testAMissingSaltIsCreatedAndPersistedOnce(): void
    {
        $written = null;
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with(ExportPseudonym::CONFIG_KEY)->willReturn(null);
        $config
            ->expects(self::once())
            ->method('set')
            ->with(ExportPseudonym::CONFIG_KEY, self::callback(static function (mixed $value) use (&$written): bool {
                $written = $value;

                return \is_string($value) && \strlen($value) === 64;
            }));

        $pseudonym = ExportPseudonym::forShop($config);

        self::assertIsString($written);
        self::assertSame((new ExportPseudonym($written))->of('abc'), $pseudonym->of('abc'));
    }

    public function testMapPairsEachPresentIdWithItsPseudonymAndSkipsTheAbsentOnes(): void
    {
        $pseudonym = new ExportPseudonym('a-fixed-test-salt');

        self::assertSame(
            ['abc' => $pseudonym->of('abc'), 'def' => $pseudonym->of('def')],
            $pseudonym->map(['abc', null, '', 'def']),
        );
    }
}
