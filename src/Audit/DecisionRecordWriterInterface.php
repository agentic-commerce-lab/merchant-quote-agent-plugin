<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * The seam that keeps DecisionRecorder plain PHP: the recorder accumulates,
 * this persists, and only the implementation touches Shopware.
 */
interface DecisionRecordWriterInterface
{
    public function write(DecisionDraft $draft): void;
}
