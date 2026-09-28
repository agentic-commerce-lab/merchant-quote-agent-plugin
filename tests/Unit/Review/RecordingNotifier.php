<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Servicing\EscalationNotice;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotifierInterface;

final class RecordingNotifier implements EscalationNotifierInterface
{
    /** @var list<EscalationNotice> */
    public array $notices = [];

    #[\Override]
    public function notify(EscalationNotice $notice): void
    {
        $this->notices[] = $notice;
    }
}
