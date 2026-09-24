<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;

/**
 * Settles a Draft Mode pass at finish(): a pass that drafted something for the
 * buyer waits for the merchant, anything else keeps no draft — its version is
 * deleted by DraftModePipeline, so the row must not point at it.
 */
final class DraftOutcome
{
    private function __construct() {}

    public static function applyTo(DecisionDraft $draft, ?NegotiationPass $pass, ?\Throwable $error): void
    {
        if ($draft->reviewFingerprint === null) {
            return;
        }

        if ($error === null && $pass !== null && ReviewStatus::awaitsReview($pass->outcome)) {
            $draft->reviewStatus = ReviewStatus::Pending->value;

            return;
        }

        $draft->draftVersionId = null;
        $draft->reviewFingerprint = null;
    }
}
