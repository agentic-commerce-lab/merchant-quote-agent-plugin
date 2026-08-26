<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryAsk;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryPolicy;
use MerchantQuoteAgentPlugin\Policy\DeliveryDecider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryDeciderTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testMatchesTsFixture(string $description, array $input, array $expected): void
    {
        $ask = ArrayMapper::mapObject(DeliveryAsk::class, $input['ask']);
        $policy = $input['policy'] === null ? null : ArrayMapper::mapObject(DeliveryPolicy::class, $input['policy']);

        $decision = (new DeliveryDecider())->decide($ask, $policy, (float) $input['orderTotalNet']);

        self::assertSame($expected['band'], $decision->band->value, $description);
        self::assertSame($expected['freeShippingGranted'] ?? null, $decision->freeShippingGranted, $description);
        self::assertSame($expected['expeditedGranted'] ?? null, $decision->expeditedGranted, $description);
        self::assertSame($expected['committedLeadTimeDays'] ?? null, $decision->committedLeadTimeDays, $description);
    }

    public static function fixtures(): iterable
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/Policy/delivery-decision.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($cases as $case) {
            yield $case['description'] => [$case['description'], $case['input'], $case['expected']];
        }
    }
}
