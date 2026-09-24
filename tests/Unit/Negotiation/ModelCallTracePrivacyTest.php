<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ModelCallPurpose;
use MerchantQuoteAgentPlugin\Negotiation\ModelCallTrace;
use PHPUnit\Framework\TestCase;

final class ModelCallTracePrivacyTest extends TestCase
{
    public function testAnUnknownProviderFinishReasonStaysBehindTheContentGate(): void
    {
        $rawReason = 'Contact anna@acme.example about this quote';
        $trace = new ModelCallTrace(ModelCallPurpose::Reply, NegotiationFixture::modelAccess(), [], 1, []);
        [$meta, $content] = $trace->answered(
            200,
            [
                'choices' => [['message' => ['content' => 'Hello'], 'finish_reason' => $rawReason]],
            ],
            null,
        );

        self::assertNull($meta['finishReason']);
        self::assertSame($rawReason, $content['response']['choices'][0]['finish_reason']);
    }
}
