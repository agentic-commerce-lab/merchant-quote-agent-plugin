<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Data;

/**
 * Why a quote was queued for servicing. Exists for log and dead-letter triage,
 * not for behaviour: the handler re-reads the quote and decides from its state,
 * so nothing downstream branches on this. If #18 ever wants to branch on it,
 * that is a behavioural dependency and the enum needs designing rather than
 * extending.
 */
enum ServicingTriggerReason: string
{
    case StateEntered = 'state_entered';
    case CommentWritten = 'comment_written';
}
