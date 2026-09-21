<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Improvement\TallyingDecisionWriter;
use PHPUnit\Framework\TestCase;

final class TallyingDecisionWriterTest extends TestCase
{
    public function testItSumsTokensAndPersistsNothing(): void
    {
        $writer = new TallyingDecisionWriter();

        $writer->write($this->draftWith(promptTokens: 100, completionTokens: 20));
        $writer->write($this->draftWith(promptTokens: 30, completionTokens: null));

        self::assertSame(130, $writer->promptTokens);
        self::assertSame(20, $writer->completionTokens);
    }

    private function draftWith(?int $promptTokens, ?int $completionTokens): DecisionDraft
    {
        $draft = new DecisionDraft();
        $draft->promptTokens = $promptTokens;
        $draft->completionTokens = $completionTokens;

        return $draft;
    }
}
