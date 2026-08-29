<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriterInterface;

/** Captures what the recorder hands over, and can be told to fail. */
final class FakeDecisionWriter implements DecisionRecordWriterInterface
{
    /** @var list<DecisionDraft> */
    public array $drafts = [];

    public ?\Throwable $throws = null;

    #[\Override]
    public function write(DecisionDraft $draft): void
    {
        $this->drafts[] = $draft;

        if ($this->throws !== null) {
            throw $this->throws;
        }
    }
}
