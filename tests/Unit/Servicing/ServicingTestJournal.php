<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\ServicingJournal;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeTraceWriter;
use Psr\Log\NullLogger;

final class ServicingTestJournal
{
    public static function create(): ServicingJournal
    {
        return new ServicingJournal(new NullLogger(), new FakeTraceWriter());
    }
}
