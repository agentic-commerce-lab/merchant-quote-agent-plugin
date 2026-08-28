<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class QuoteAgentSettingsFactoryTest extends TestCase
{
    /**
     * @mago-expect lint:no-literal-password
     *
     * `sk-test` is a fixture credential for a factory that never talks to a
     * real API, not a real secret.
     *
     * @param array<string, mixed> $overrides
     */
    private static function build(array $overrides = []): mixed
    {
        $raw = [
            'enabled' => true,
            'rulesOnlyMode' => false,
            'llmApiKey' => 'sk-test',
            'llmBaseUrl' => 'https://api.openai.com/v1',
            'negotiationStrategy' => 'open at 2%',
            'maxDiscountPercent' => 12.0,
            'counterOfferMaxPercent' => 18.0,
            'maxQuoteValueNet' => 50_000.0,
            'maxQuoteValueCurrency' => 'EUR',
            'validityDays' => 14,
            'replyTone' => 'formal',
            'deliveryFreeShippingAboveNet' => 500.0,
            'deliveryMaxShippingWaiverNet' => 80.0,
            'deliveryExpeditedAllowed' => true,
            'deliveryCommittedLeadTimeDaysMin' => 3,
            'paymentAllowedTerms' => ['net_30', 'net_60'],
            'paymentMaxNetDays' => 60,
            'paymentMinDepositPercent' => 10.0,
            'bundleVolumeTiers' => "10:5\n50:7.5",
        ];

        $factory = new QuoteAgentSettingsFactory(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );

        return $factory->fromValues([...$raw, ...$overrides]);
    }

    public function testAFullConfigurationMapsOntoTheWholePolicyGraph(): void
    {
        $settings = self::build();

        self::assertNotNull($settings);
        self::assertSame(12.0, $settings->policy->price->maxDiscountPercent);
        self::assertSame(18.0, $settings->policy->price->counterOfferMaxPercent);
        self::assertSame(50_000.0, $settings->policy->price->valueCeiling?->net);
        self::assertSame('EUR', $settings->policy->price->valueCeiling?->currencyIso);
        self::assertSame(14, $settings->policy->price->validityDays);
        self::assertSame('formal', $settings->policy->price->replyTone);
        self::assertSame(500.0, $settings->policy->delivery?->freeShippingAboveNet);
        self::assertSame(3, $settings->policy->delivery?->committedLeadTimeDaysMin);
        self::assertSame(60, $settings->policy->payment?->maxNetDays);
        self::assertCount(2, $settings->policy->payment->allowedTerms ?? []);
        self::assertCount(2, $settings->policy->bundle->volumeTiers ?? []);
        self::assertSame(50, $settings->policy->bundle?->volumeTiers[1]->minQty);
        self::assertSame('sk-test', $settings->llm?->apiKey);
        self::assertSame('open at 2%', $settings->strategyPrompt);
    }

    public function testADisabledChannelReturnsNullAndIsNeverValidated(): void
    {
        // Every other field is garbage. A paused agent does not complain about
        // its own configuration.
        self::assertNull(self::build([
            'enabled' => false,
            'maxDiscountPercent' => 500.0,
            'llmApiKey' => '',
            'bundleVolumeTiers' => 'nonsense',
        ]));
    }

    public function testABlankSubPolicySectionBecomesNullRatherThanAnAllNullObject(): void
    {
        $settings = self::build([
            'deliveryFreeShippingAboveNet' => null,
            'deliveryMaxShippingWaiverNet' => null,
            'deliveryExpeditedAllowed' => false,
            'deliveryCommittedLeadTimeDaysMin' => null,
            'paymentAllowedTerms' => [],
            'paymentMaxNetDays' => null,
            'paymentMinDepositPercent' => null,
            'bundleVolumeTiers' => '',
        ]);

        self::assertNotNull($settings);
        self::assertNull(
            $settings->policy->delivery,
            'A blank delivery section must escalate as "no delivery policy configured".',
        );
        self::assertNull($settings->policy->payment);
        self::assertNull($settings->policy->bundle);
    }

    public function testAnUntouchedInstallEscalatesEverythingRatherThanFailing(): void
    {
        $settings = self::build([
            'maxDiscountPercent' => null,
            'counterOfferMaxPercent' => null,
            'maxQuoteValueNet' => null,
            'maxQuoteValueCurrency' => null,
            'validityDays' => null,
            'replyTone' => null,
        ]);

        self::assertNotNull($settings);
        self::assertSame(0.0, $settings->policy->price->maxDiscountPercent);
        self::assertNull($settings->policy->price->valueCeiling);
        self::assertSame(0, $settings->policy->price->validityDays);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'discount cap over 100' => [['maxDiscountPercent' => 150.0], 'price.maxDiscountPercent'];
        yield 'negative lead time' => [['deliveryCommittedLeadTimeDaysMin' => -1], 'delivery.committedLeadTimeDaysMin'];
        yield 'bad ceiling currency' => [['maxQuoteValueCurrency' => 'NOPE'], 'price.valueCeiling.currencyIso'];
        yield 'tier percent over 100' => [['bundleVolumeTiers' => '10:150'], 'bundle.volumeTiers[0].discountPercent'];
        yield 'malformed tier line' => [['bundleVolumeTiers' => "10:5\nbroken"], 'line 2'];
        yield 'unknown payment term' => [['paymentAllowedTerms' => ['net_45']], 'PaymentPolicy'];
        yield 'ceiling wrong type' => [['maxQuoteValueNet' => '50000'], 'maxQuoteValueNet'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefusedWholeAndNamesTheField(array $overrides, string $expected): void
    {
        try {
            self::build($overrides);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        try {
            self::build(['maxDiscountPercent' => 150.0, 'deliveryCommittedLeadTimeDaysMin' => -1]);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertCount(2, $e->problems);
        }

        // Cross-mechanism: the volume-tier parser and the validator collect
        // independently, and neither short-circuits the other.
        try {
            self::build(['bundleVolumeTiers' => 'broken', 'maxDiscountPercent' => 150.0]);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertCount(2, $e->problems);
        }
    }

    /** @mago-expect lint:no-literal-password */
    public function testAnEnabledChannelWithoutAKeyIsAMisconfiguration(): void
    {
        try {
            self::build(['llmApiKey' => '   ']);
            self::fail('An empty API key was accepted, which is the silent fallback #5 removes.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString('API key', $e->getMessage());
        }
    }

    /** @mago-expect lint:no-literal-password */
    public function testRulesOnlyModeIsTheOneStateThatNeedsNoKey(): void
    {
        // The key is deliberately left set: rules-only must win even when a
        // merchant forgets to clear it, not just when the key is also blank.
        $settings = self::build(['llmApiKey' => 'sk-test', 'rulesOnlyMode' => true]);

        self::assertNotNull($settings);
        self::assertTrue($settings->rulesOnly);
        self::assertNull(
            $settings->llm,
            'Rules-only carries no model access, so nothing downstream can call a model by accident.',
        );
    }
}
