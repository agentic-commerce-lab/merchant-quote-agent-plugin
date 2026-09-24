<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedTrace;
use MerchantQuoteAgentPlugin\Audit\TraceEvent;
use PHPUnit\Framework\TestCase;

final class AnonymizedTraceTest extends TestCase
{
    public function testWithoutFreeTextOnlyTheKindTheTimeAndTheMetaLeave(): void
    {
        self::assertSame(
            [
                'kind' => 'model_call',
                'occurredAt' => '2031-05-05T12:00:00.123+00:00',
                'meta' => ['purpose' => 'extract'],
            ],
            AnonymizedTrace::of(self::event(), freeText: false),
        );
    }

    public function testWithFreeTextTheContentLeavesToo(): void
    {
        $line = AnonymizedTrace::of(self::event(), freeText: true);

        self::assertSame(['request' => ['messages' => [['content' => 'anna@acme.example']]]], $line['content']);
    }

    public function testTheDecisionsOwnIdsInsideContentLeaveAsTheirPseudonyms(): void
    {
        // A quote snapshot and a prompt carry the raw quote, customer and
        // channel ids. The export promises those only ever leave pseudonymized,
        // free text or not, so they are swapped inside content too.
        $event = self::event();
        $event->content = ['identity' => ['quoteId' => 'aaaa', 'customerId' => 'bbbb'], 'note' => 'quote aaaa'];

        $line = AnonymizedTrace::of($event, freeText: true, pseudonyms: ['aaaa' => 'p-quote', 'bbbb' => 'p-cust']);

        self::assertSame(
            ['identity' => ['quoteId' => 'p-quote', 'customerId' => 'p-cust'], 'note' => 'quote p-quote'],
            $line['content'],
        );
    }

    private static function event(): TraceEvent
    {
        $event = new TraceEvent();
        $event->kind = 'model_call';
        $event->occurredAt = new \DateTimeImmutable('2031-05-05T12:00:00.123+00:00');
        $event->meta = ['purpose' => 'extract'];
        $event->content = ['request' => ['messages' => [['content' => 'anna@acme.example']]]];

        return $event;
    }
}
