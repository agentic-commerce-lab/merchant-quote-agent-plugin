<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy\Data;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteValueCeiling;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The Assert attributes on the policy objects have always been declared and
 * never run. Issue #5 runs them, and a nested constraint only fires when the
 * property holding the object is marked Assert\Valid.
 */
final class NegotiationPolicyValidationTest extends TestCase
{
    public function testAnOutOfRangePriceCapIsReportedAtItsNestedPath(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 150.0, validityDays: 14));

        self::assertSame(['price.maxDiscountPercent'], self::paths(self::validator()->validate($policy)));
    }

    public function testANegativeCeilingIsReportedThroughTwoLevelsOfNesting(): void
    {
        // The deepest nesting left in the policy now that the sub-policies are
        // gone. Drop Assert\Valid from either hop and this path disappears,
        // and the per-currency map means the offending currency is named.
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            valueCeiling: new QuoteValueCeiling(['EUR' => 50_000.0, 'USD' => -1.0]),
            validityDays: 14,
        ));

        self::assertSame(
            ['price.valueCeiling.netByCurrencyIso[USD]'],
            self::paths(self::validator()->validate($policy)),
        );
    }

    public function testAValidPolicyReportsNothing(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            valueCeiling: new QuoteValueCeiling(['EUR' => 50_000.0]),
            validityDays: 14,
        ));

        self::assertSame([], self::paths(self::validator()->validate($policy)));
    }

    public function testAnUnsetValidityIsRejectedRatherThanReadAsZeroDays(): void
    {
        // #57. `0` is what every "nobody set this" path produces — an absent
        // array key, a cleared admin field, this constructor's own default —
        // and an offer valid for zero days is one sent already expired. The
        // constraint is the only thing standing between those three paths and
        // a buyer being told an offer is valid until today.
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0));

        self::assertSame(['price.validityDays'], self::paths(self::validator()->validate($policy)));
    }

    public function testANegativeMinimumMarginIsRejected(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            validityDays: 14,
            minMarginPercent: -1.0,
        ));

        self::assertSame(['price.minMarginPercent'], self::paths(self::validator()->validate($policy)));
    }

    public function testAZeroMinimumMarginIsValidAndMeansNeverBelowCost(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            validityDays: 14,
            minMarginPercent: 0.0,
        ));

        self::assertSame([], self::paths(self::validator()->validate($policy)));
    }

    public function testTighteningTheDiscountCapKeepsTheMarginAndTheRounding(): void
    {
        // CappedAuthority rebuilds the limits through withMaxDiscountPercent()
        // on every round where the buyer asks for less than the cap. Dropping
        // either field there would switch it off on exactly those rounds.
        $limits = new QuoteLimits(
            maxDiscountPercent: 15.0,
            validityDays: 14,
            minMarginPercent: 10.0,
            roundingMode: RoundingMode::QuoteTotal,
            roundingStep: 10.0,
        );
        $tightened = $limits->withMaxDiscountPercent(5.0);

        self::assertSame(10.0, $tightened->minMarginPercent);
        self::assertSame(10.0, $tightened->stepFor(RoundingMode::QuoteTotal));
    }

    public function testANegativeRoundingStepIsRejected(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            validityDays: 14,
            roundingMode: RoundingMode::DiscountPercent,
            roundingStep: -0.5,
        ));

        self::assertSame(['price.roundingStep'], self::paths(self::validator()->validate($policy)));
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @param \Symfony\Component\Validator\ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface> $violations
     *
     * @return list<string>
     */
    private static function paths(iterable $violations): array
    {
        $paths = [];

        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
