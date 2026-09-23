<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use PHPUnit\Framework\TestCase;

final class AgentContextVersionTest extends TestCase
{
    public function testAVersionedAgentContextKeepsTheAgentMarker(): void
    {
        // createWithVersionId() drops states, so a naive re-version would make
        // the servicing trigger read our own draft writes as someone else's.
        $context = AgentContext::forVersion('0190aaaa0000700080000000000000aa');

        self::assertSame('0190aaaa0000700080000000000000aa', $context->getVersionId());
        self::assertTrue($context->hasState(AgentContext::STATE));
    }
}
