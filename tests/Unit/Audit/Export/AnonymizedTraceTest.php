<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedOutsideTrace;
use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedTrace;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Audit\TraceEvent;
use PHPUnit\Framework\TestCase;

final class AnonymizedTraceTest extends TestCase
{
    public function testOutsideHttpSessionIdIsPseudonymizedInMetaAndContent(): void
    {
        $event = self::event();
        $event->id = 'event-1';
        $event->kind = 'http';
        $event->quoteId = 'quote-1';
        $event->meta = ['route' => 'frontend.merchant_quote_agent.a2cn.acts', 'sessionId' => 'session-1'];
        $event->content = ['requestBody' => 'session-1'];
        $pseudonym = new ExportPseudonym('shop-salt');

        $line = AnonymizedOutsideTrace::of($event, $pseudonym, freeText: true);

        self::assertSame($pseudonym->of('session-1'), $line['meta']['sessionId']);
        self::assertSame($pseudonym->of('session-1'), $line['content']['requestBody']);
        self::assertSame($pseudonym->of('quote-1'), $line['quote']);
    }

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

    public function testStoredMetaIsRestrictedToTheKindsExportKeys(): void
    {
        $event = self::event();
        $event->meta = [
            'purpose' => 'extract',
            'buyerEmail' => 'anna@acme.example',
            'truncated' => ['request.messages.0.content'],
        ];

        $line = AnonymizedTrace::of($event, freeText: false);

        self::assertSame(['purpose' => 'extract', 'truncated' => ['request.messages.0.content']], $line['meta']);
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
