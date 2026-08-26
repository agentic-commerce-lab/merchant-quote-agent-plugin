<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentAsk;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;
use MerchantQuoteAgentPlugin\Policy\PaymentDecider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentDeciderTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testMatchesTsFixture(string $description, array $input, array $expected): void
    {
        $ask = ArrayMapper::mapObject(PaymentAsk::class, $input['ask']);
        $policy = ArrayMapper::mapObject(PaymentPolicy::class, $input['policy']);

        $decision = (new PaymentDecider())->decide($ask, $policy);

        self::assertSame($expected['band'], $decision->band->value, $description);
        self::assertSame($expected['grantedTerm'] ?? null, $decision->grantedTerm?->value, $description);
        self::assertSame($expected['grantedNetDays'] ?? null, $decision->grantedNetDays, $description);
        self::assertEquals($expected['grantedDepositPercent'] ?? null, $decision->grantedDepositPercent, $description);
    }

    public static function fixtures(): iterable
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/Policy/payment-decision.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($cases as $case) {
            yield $case['description'] => [$case['description'], $case['input'], $case['expected']];
        }
    }
}
