<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ReplyTemplate;
use MerchantQuoteAgentPlugin\Negotiation\RewordingGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a rewording is allowed to say.
 *
 * The guard's two failure modes are not symmetric. Letting an invented term
 * through puts a commitment in front of a buyer that OfferApplier never wrote
 * and AskGate would have escalated. Rejecting a good rewording costs a plainer
 * sentence -- but it costs it EVERY time, silently, with every gate still
 * green, which is how a guard turns a feature off without anyone noticing.
 * So the accepting table below is as load-bearing as the rejecting one.
 */
final class RewordingGuardTest extends TestCase
{
    private const PERCENT = 5.0;

    private const TOTAL = 950.0;

    private static function validUntil(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(NegotiationFixture::EXPIRES);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptableReasonings(): iterable
    {
        yield 'the template itself' => [
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11.',
        ];
        yield 'a warm opener and a sign-off' => [
            'Thank you for coming back to us. We can bring this quote down by 5% to 950.00 EUR. '
                . 'The offer is valid until 2026-09-11. Do let us know if you would like to proceed.',
        ];
        yield 'five sentences exactly, the prompt ceiling' => [
            'Thanks for your patience. We reviewed the quote with our team. We can bring it down by 5% '
                . 'to 950.00 EUR. That offer is valid until 2026-09-11. We hope this works for you.',
        ];
        yield 'the percentage written with the trailing zeros a model adds' => [
            'We can reduce this quote by 5.00% to 950.00 EUR, valid until 2026-09-11.',
        ];
        yield 'the same figure restated' => [
            'A 5% reduction brings the quote to 950.00 EUR. That is 5% off, valid until 2026-09-11.',
        ];
        yield 'currency code ahead of the amount' => [
            'We can bring this quote down by 5% to EUR 950.00. The offer is valid until 2026-09-11.',
        ];
        yield 'a formal German tone that still carries the ASCII figures' => [
            'Gerne kommen wir Ihnen entgegen: Wir reduzieren dieses Angebot um 5% auf 950.00 EUR. '
                . 'Das Angebot ist gültig bis 2026-09-11.',
        ];
        yield 'an exclamation and a question, still under the cap' => [
            'Good news! We can bring this quote down by 5% to 950.00 EUR. '
                . 'The offer is valid until 2026-09-11 — shall we proceed?',
        ];
        yield 'a sentence that mentions the invoice without promising anything' => [
            'We can bring this quote down by 5% to 950.00 EUR. Your invoice will show the new total. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a word that merely starts like a concession' => [
            // `warrant` as a stem would reject this; the list carries
            // `warranty`/`warranties` instead precisely so it does not.
            'We can bring this quote down by 5% to 950.00 EUR. We hope this warrants your approval. '
                . 'The offer is valid until 2026-09-11.',
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function rejectableReasonings(): iterable
    {
        yield 'the concession from the issue' => [
            'We can bring this quote down by 5% to 950.00 EUR and we will also include free shipping. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'payment terms nobody authorised' => [
            'We can bring this quote down by 5% to 950.00 EUR on Net 90 terms. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a delivery promise' => [
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11 '
                . 'and delivery is on us.',
        ];
        yield 'an invented second date' => [
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11, '
                . 'and we can hold it again until 2026-12-31.',
        ];
        yield 'an invented validity window' => [
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 2026-09-11, '
                . 'which is 14 days from today.',
        ];
        yield 'an invented quantity written with a separator' => [
            // The separator rule below normalises a grouped figure before
            // comparing it; this pins that it normalises rather than blanket
            // accepts -- 1200 is not a figure the template wrote.
            'We can bring this quote down by 5% to 950.00 EUR. Order 1,200 more units and we can do better. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'an invented quantity' => [
            'We can bring this quote down by 5% to 950.00 EUR. Order 20 more units and we can do better. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a deposit' => [
            'We can bring this quote down by 5% to 950.00 EUR against a deposit. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a warranty' => [
            'We can bring this quote down by 5% to 950.00 EUR, warranty included. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a concession in the plural, which a bare word boundary missed' => [
            'We can bring this quote down by 5% to 950.00 EUR. We can also split this into installments. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'plural payments' => [
            'We can bring this quote down by 5% to 950.00 EUR. Flexible payments are available. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'plural deposits' => [
            'We can bring this quote down by 5% to 950.00 EUR. No deposits required. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'a y-to-ies plural the suffix rule cannot reach' => [
            'We can bring this quote down by 5% to 950.00 EUR. Extended warranties included. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'deliveries, the other y-to-ies plural' => [
            'We can bring this quote down by 5% to 950.00 EUR. Deliveries are on us. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'shipments, which shipping does not reach' => [
            'We can bring this quote down by 5% to 950.00 EUR. Shipments are free of charge. '
                . 'The offer is valid until 2026-09-11.',
        ];
        yield 'six sentences' => [
            'Hello. Thank you for your patience. We looked at this again. We can bring it down by 5% '
                . 'to 950.00 EUR. It is valid until 2026-09-11. Let us know.',
        ];
        yield 'the total dropped' => ['We can bring this quote down by 5%, valid until 2026-09-11.'];
        yield 'the date dropped' => ['We can bring this quote down by 5% to 950.00 EUR.'];
        yield 'the percentage dropped' => ['We can bring this quote to 950.00 EUR, valid until 2026-09-11.'];
        yield 'the percentage dropped, surviving only inside the total' => [
            // Passes TODAY: str_contains($reworded, '5') is satisfied by
            // 950.00, so the old guard could not see the missing reduction.
            'We can bring this quote to 950.00 EUR, valid until 2026-09-11.',
        ];
        yield 'empty' => [''];
        yield 'the date localised instead of kept verbatim' => [
            // The reply prompt requires "dates as YYYY-MM-DD". A date in any
            // other format is a figure the template did not write, and the
            // ISO one it did write is then missing -- which is what the old
            // str_contains() guard demanded too, so this is not new strictness.
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until 11.09.2026.',
        ];
    }

    #[DataProvider('acceptableReasonings')]
    public function testAFaithfulRewordingReachesTheBuyer(string $reworded): void
    {
        self::assertNull(
            RewordingGuard::unsafeBecause($reworded, self::PERCENT, self::TOTAL, self::validUntil()),
            'This rewording adds nothing, so rejecting it silently disables rewording for this tone.',
        );
    }

    #[DataProvider('rejectableReasonings')]
    public function testARewordingThatAddsOrDropsAFactIsRejected(string $reworded): void
    {
        self::assertNotNull(
            RewordingGuard::unsafeBecause($reworded, self::PERCENT, self::TOTAL, self::validUntil()),
            'This rewording would put something in front of a buyer that nothing downstream can honour.',
        );
    }

    /**
     * The one hole the figure check cannot close, pinned so nobody mistakes
     * it for covered.
     *
     * An invented figure that happens to equal an authorised one is invisible
     * to a membership check. Counting occurrences would close it and would
     * reject 'the same figure restated' above, which is a rewording a model
     * produces constantly -- so this is accepted deliberately rather than
     * overlooked.
     */
    public function testAnInventedFigureThatEqualsAnAuthorisedOneIsNotCaught(): void
    {
        self::assertNull(
            RewordingGuard::unsafeBecause(
                'We can bring this quote down by 5% to 950.00 EUR. Order 5 more units and we will do better. '
                . 'Valid until 2026-09-11.',
                self::PERCENT,
                self::TOTAL,
                self::validUntil(),
            ),
            'If this starts rejecting, the guard grew a rule -- check it did not also start rejecting '
            . 'a rewording that simply restates the percentage.',
        );
    }

    /**
     * A total of 1000 or more, which the table above never reaches.
     *
     * `money()` writes `9500.00` and a model handed that writes `9,500.00`
     * back -- ordinary money formatting, not an invented figure. Rejecting it
     * costs nothing visible: the template ships, every gate stays green, and
     * rewording is simply off for every quote a B2B buyer actually sends.
     * TOTAL above is 950.00, which is the only reason the table never saw it.
     */
    public function testAGroupedThousandsSeparatorIsTheSameTotal(): void
    {
        self::assertNull(
            RewordingGuard::unsafeBecause(
                'We can bring this quote down by 5% to 9,500.00 EUR. The offer is valid until 2026-09-11.',
                self::PERCENT,
                9500.0,
                self::validUntil(),
            ),
            'A thousands separator is a spelling of the authorised total, not a figure nobody authorised.',
        );
    }

    /**
     * The other direction: the grouped form also satisfies the backwards
     * check, so the total does not read as dropped either.
     */
    public function testAGroupedTotalDoesNotReadAsADroppedFact(): void
    {
        $reason = RewordingGuard::unsafeBecause(
            'A 5% reduction takes this to 9,500.00 EUR, valid until 2026-09-11.',
            self::PERCENT,
            9500.0,
            self::validUntil(),
        );

        self::assertNull($reason, (string) $reason);
    }

    /** The reason is what makes an over-firing guard greppable rather than invisible. */
    public function testTheReasonNamesTheRuleThatFired(): void
    {
        $reason = RewordingGuard::unsafeBecause(
            'We can bring this quote down by 5% to 950.00 EUR with free shipping. '
            . 'The offer is valid until 2026-09-11.',
            self::PERCENT,
            self::TOTAL,
            self::validUntil(),
        );

        self::assertNotNull($reason);
        self::assertStringContainsString('shipping', $reason);
    }

    /**
     * A per-line concession carries no discountPercent at all, so 0% is a real
     * production case -- and `0` is a substring of `950.00`, which is the
     * substring hole from the other side. The template must accept itself.
     */
    public function testAZeroPercentReductionStillAcceptsItsOwnTemplate(): void
    {
        $template = ReplyTemplate::compose(0.0, 950.0, 'EUR', self::validUntil());

        self::assertNull(RewordingGuard::unsafeBecause($template, 0.0, 950.0, self::validUntil()));
    }
}
