<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\StructuralAsks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `InterpretedAsk::hasNoAsk()` lists the extraction's fields one by one, and
 * its own docblock states the stakes: missing even one of them turns a real
 * ask back into a silent grant — or, since #177, into silence.
 *
 * A list is only correct on the day it is written. `PriceAsk::$targetTotal`
 * arrived with #164/#167, three days before that list existed; the next field
 * to arrive will not announce itself, and the failure mode is invisible —
 * no error, no escalation, no reply, just a buyer question the agent decided
 * was nothing.
 *
 * So this walks the shapes by reflection instead of repeating the list: every
 * constructor parameter of PriceAsk and StructuralAsks, populated alone, must
 * make `hasNoAsk()` false. A new field with no line in `hasNoAsk()` fails
 * here; a new field of a type this test cannot populate fails here too,
 * deliberately, rather than passing by skipping itself.
 */
final class NoAskFieldCoverageTest extends TestCase
{
    /** @param class-string<PriceAsk|StructuralAsks> $shape */
    #[DataProvider('statedFields')]
    public function testEveryFieldOfTheExtractionShapeCountsAsAnAsk(string $shape, string $field, mixed $value): void
    {
        $populated = new $shape(...[$field => $value]);
        $interpretation = $populated instanceof PriceAsk
            ? new CommentInterpretation(price: $populated)
            : new CommentInterpretation(structural: $populated);

        self::assertFalse(
            (new InterpretedAsk($interpretation, 'hash'))->hasNoAsk(),
            sprintf(
                '%s::$%s carries a buyer ask that InterpretedAsk::hasNoAsk() does not read, so a comment '
                . 'holding only that ask ends the pass as NothingToDo: no offer, no escalation, no reply. '
                . 'Add it to the list there.',
                $shape,
                $field,
            ),
        );
    }

    public function testStatedMeansAskedForRatherThanMerelyPresent(): void
    {
        // The two edges the loop above cannot reach, because it populates
        // every field with a value that plainly means "asked for".
        //
        // `false` is the model saying no. An extraction that answers "did the
        // buyer ask for your best price?" with no is the "Nice, thanks!"
        // shape, not an ask, and treating it as one puts the unsolicited
        // offer of #177 straight back.
        self::assertTrue(
            (new InterpretedAsk(
                new CommentInterpretation(price: new PriceAsk(bestPriceRequested: false)),
                'hash',
            ))->hasNoAsk(),
        );

        // `0` is a number the buyer named. Same distinction NegotiationAsks
        // draws for `requested_net_days: 0` and `shipping_cost_net: 0`: a
        // stated zero is an ask to answer — with a hold, since #175 — and
        // never an absent one to pass over in silence.
        self::assertFalse(
            (new InterpretedAsk(
                new CommentInterpretation(price: new PriceAsk(additionalDiscountPercent: 0.0)),
                'hash',
            ))->hasNoAsk(),
        );
    }

    /** @return iterable<string, array{class-string, string, mixed}> */
    public static function statedFields(): iterable
    {
        foreach ([PriceAsk::class, StructuralAsks::class] as $shape) {
            foreach ((new \ReflectionClass($shape))->getConstructor()?->getParameters() ?? [] as $parameter) {
                yield $shape . '::$' . $parameter->getName() => [
                    $shape,
                    $parameter->getName(),
                    self::stated($parameter),
                ];
            }
        }
    }

    /**
     * A value that means the buyer asked for this field — never the `false`
     * and `null` that mean the model found nothing, which is the distinction
     * `NegotiationAsks::hasAny()` draws and `hasNoAsk()` inherits.
     */
    private static function stated(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

        return match (true) {
            $name === 'float' => 5.0,
            $name === 'int' => 5,
            $name === 'bool' => true,
            $name === 'string' => '2026-12-01',
            $parameter->getName() === 'lineChanges' => [
                new InterpretedLineChange(lineItemId: 'line-1', targetUnitPrice: 95.0),
            ],
            $parameter->getName() === 'addProducts' => ['prod-1'],
            default => self::fail(sprintf(
                'No stated value is defined for %s $%s. Add one here, then check that '
                . 'InterpretedAsk::hasNoAsk() reads the field — this test exists because a field it does '
                . 'not read is a buyer ask answered with silence.',
                $name === '' ? 'the new parameter' : $name,
                $parameter->getName(),
            )),
        };
    }
}
