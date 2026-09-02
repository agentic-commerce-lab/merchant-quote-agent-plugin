<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The marker is what makes "ask once" true. If it read as set when it is not,
 * an ambiguity escalates without the buyer ever being asked; if it read as
 * unset when it is set, a talkative buyer collects one question per comment.
 */
final class ClarificationMarkerTest extends TestCase
{
    public function testItIsOnlyAlreadyAskedWhenTheMarkerIsPresentAndTrue(): void
    {
        self::assertFalse(ClarificationMarker::alreadyAsked(NegotiationFixture::snapshot()));

        $asked = NegotiationFixture::withCustomFields(NegotiationFixture::snapshot(), [
            ClarificationMarker::MARKER_KEY => true,
        ]);
        self::assertTrue(ClarificationMarker::alreadyAsked($asked));

        // A released marker is written as null, and an unrelated key must not
        // read as asked.
        $released = NegotiationFixture::withCustomFields(NegotiationFixture::snapshot(), [
            ClarificationMarker::MARKER_KEY => null,
            'other_key' => true,
        ]);
        self::assertFalse(ClarificationMarker::alreadyAsked($released));
    }

    public function testOnlyAnAnsweringPassReleasesTheMarker(): void
    {
        self::assertSame(
            [ClarificationMarker::MARKER_KEY => null],
            ClarificationMarker::releaseFor(NegotiationOutcome::Offered),
        );
        self::assertSame(
            [ClarificationMarker::MARKER_KEY => null],
            ClarificationMarker::releaseFor(NegotiationOutcome::Countered),
        );

        // The pass that wrote the marker must not erase it, or the next buyer
        // comment is asked the same question again instead of escalating.
        self::assertSame([], ClarificationMarker::releaseFor(NegotiationOutcome::Clarified));
        self::assertSame([], ClarificationMarker::releaseFor(NegotiationOutcome::Escalated));
        self::assertSame([], ClarificationMarker::releaseFor(NegotiationOutcome::NothingToDo));
    }

    public function testTheSetFragmentWritesTheMarker(): void
    {
        self::assertSame([ClarificationMarker::MARKER_KEY => true], ClarificationMarker::set());
    }
}
