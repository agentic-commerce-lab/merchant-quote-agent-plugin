<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Audit\TracePayload;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

final class TraceDraftTest extends TestCase
{
    public function testEventsAreNumberedInTheOrderTheyArrive(): void
    {
        $draft = new DecisionDraft();

        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, ['lineCount' => 1], null);
        TraceDraft::appendTo($draft, TraceKind::ReplyGuard, ['accepted' => false], null);

        self::assertSame([0, 1], array_map(static fn(TraceDraft $t): int => $t->position, $draft->trace));
    }

    public function testMetaIsAnAllowlistUndeclaredKeysAreDroppedAndMissingOnesAreNull(): void
    {
        // meta leaves in every export, free text or not. A key nobody
        // declared in TraceKind::metaKeys() must not be able to reach it.
        $draft = new DecisionDraft();

        TraceDraft::appendTo($draft, TraceKind::ReplyGuard, ['accepted' => false, 'buyerSaid' => 'secret'], null);
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, [], null);

        self::assertSame(['accepted' => false], $draft->trace[0]->meta);
        self::assertSame(['lineCount' => null], $draft->trace[1]->meta);
    }

    public function testACutInContentIsReportedInMeta(): void
    {
        $draft = new DecisionDraft();

        TraceDraft::appendTo(
            $draft,
            TraceKind::ReplyGuard,
            ['accepted' => false],
            [
                'reworded' => str_repeat('x', TracePayload::MAX_STRING_BYTES + 1),
            ],
        );

        self::assertSame(['accepted' => false, 'truncated' => ['reworded']], $draft->trace[0]->meta);
        self::assertSame(TracePayload::MAX_STRING_BYTES, \strlen($draft->trace[0]->content['reworded'] ?? ''));
    }

    public function testThePayloadCarriesTheDecisionsIdsAndAFreshId(): void
    {
        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        $draft->customerId = Uuid::randomHex();
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, ['lineCount' => 2], ['identity' => []]);

        $payload = $draft->trace[0]->payload($draft);

        self::assertTrue(Uuid::isValid($payload['id']));
        self::assertNotSame($draft->id, $payload['id']);
        self::assertSame($draft->id, $payload['decisionId']);
        self::assertSame($draft->quoteId, $payload['quoteId']);
        self::assertSame($draft->customerId, $payload['customerId']);
        self::assertSame('quote_before', $payload['kind']);
        self::assertSame(0, $payload['position']);
        self::assertInstanceOf(\DateTimeImmutable::class, $payload['occurredAt']);
        self::assertSame(['lineCount' => 2], $payload['meta']);
        self::assertSame(['identity' => []], $payload['content']);
    }
}
