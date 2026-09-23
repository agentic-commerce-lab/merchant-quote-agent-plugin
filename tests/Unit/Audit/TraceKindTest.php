<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use PHPUnit\Framework\TestCase;

final class TraceKindTest extends TestCase
{
    public function testEveryKindDeclaresAUniqueNonEmptyMetaAllowlist(): void
    {
        foreach (TraceKind::cases() as $kind) {
            $keys = $kind->metaKeys();

            self::assertNotSame([], $keys, $kind->value . ' declares no meta keys.');
            self::assertSame(array_values(array_unique($keys)), $keys, $kind->value . ' declares a key twice.');
            self::assertNotContains(
                'truncated',
                $keys,
                'truncated is added by TraceDraft when a cut happens; declaring it would make it always present.',
            );
        }
    }

    public function testTheModelCallAllowlistIsTheSpecsList(): void
    {
        self::assertSame(
            [
                'purpose',
                'requestedModel',
                'servedModel',
                'host',
                'status',
                'httpStatus',
                'latencyMs',
                'promptTokens',
                'completionTokens',
                'cachedTokens',
                'reasoningTokens',
                'finishReason',
                'retries',
                'errorClass',
            ],
            TraceKind::ModelCall->metaKeys(),
        );
    }
}
