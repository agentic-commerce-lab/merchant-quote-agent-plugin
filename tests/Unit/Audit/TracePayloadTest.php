<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TracePayload;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use PHPUnit\Framework\TestCase;

final class TracePayloadTest extends TestCase
{
    public function testAnObjectTreeBecomesPlainArraysWithEnumsAsTheirValues(): void
    {
        $value = new class {
            public Band $band = Band::Grant;

            public array $lines = [['qty' => 2]];
        };

        self::assertSame(['band' => 'grant', 'lines' => [['qty' => 2]]], TracePayload::of($value));
    }

    public function testInvalidUtf8IsSubstitutedRatherThanThrown(): void
    {
        // A provider error body is raw bytes. Recording it must never throw
        // into the pass, and the DAL's own JSON encode would.
        $out = TracePayload::of(['body' => "ok \xB1\x31 end"]);

        self::assertIsString($out['body']);
        self::assertStringContainsString("\u{FFFD}", $out['body']);
    }

    public function testAThrowingSerializerYieldsAnEmptyPayloadRatherThanThrowing(): void
    {
        // json_encode propagates an exception from jsonSerialize() even with
        // JSON_PARTIAL_OUTPUT_ON_ERROR, and of() runs mid-pass.
        $boom = new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): never
            {
                throw new \RuntimeException('serializer failed');
            }
        };

        self::assertSame([], TracePayload::of(['a' => $boom]));
    }

    public function testAFloatWithAZeroFractionStaysAFloat(): void
    {
        // The DAL's own Json::encode preserves it; a round trip that did not
        // would turn a 950.0 total into int 950.
        self::assertSame(['totalNet' => 950.0], TracePayload::of(['totalNet' => 950.0]));
    }

    public function testAStringOverTheCapIsCutAtACharacterBoundaryAndItsPathReported(): void
    {
        $long = str_repeat('ä', TracePayload::MAX_STRING_BYTES); // 2 bytes each, so 2x the cap
        [$capped, $cut] = TracePayload::capped(['request' => ['messages' => [['content' => $long]]], 'short' => 'x']);

        $kept = $capped['request']['messages'][0]['content'];
        self::assertLessThanOrEqual(TracePayload::MAX_STRING_BYTES, \strlen($kept));
        self::assertTrue(mb_check_encoding($kept, 'UTF-8'), 'The cut split a multibyte character.');
        self::assertSame('x', $capped['short']);
        self::assertSame(['request.messages.0.content'], $cut);
    }

    public function testNothingUnderTheCapIsTouched(): void
    {
        $value = ['a' => 'short', 'b' => [1, 2.5, true, null]];

        self::assertSame([$value, []], TracePayload::capped($value));
    }
}
