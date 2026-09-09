<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/** The quote state-machine actions an agent servicing pass drives. */
enum QuoteTransition: string
{
    case Process = 'process';
    case Sent = 'sent';
    case Decline = 'decline';
    case RequestChange = 'request_change';

    /**
     * The only exit to `replied` from a renegotiation state: `reopen` on
     * released SwagCommercial (≤6.7.12), `change_requested` on trunk. Both
     * state machines carry it under this same name, which is what makes it
     * the shared action ReplyComposer can reach for regardless of which
     * machine this shop runs.
     */
    case AdminResend = 'admin_resend';
}
