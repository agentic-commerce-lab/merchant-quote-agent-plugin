<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentConsentController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pure static delegates (agentHost/scopeList/denialUrl) — split out of
 * AgentConsentControllerTest (which covers grant()) to stay under mago's
 * too-many-methods ceiling.
 */
#[CoversClass(AgentConsentController::class)]
final class AgentConsentControllerPresentationTest extends TestCase
{
    public function testItNamesTheAgentByHostRatherThanTheFullProfileUri(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp?run=1788265660',
            [],
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read dev.ucp.shopping.cart:manage',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertSame('agent.example', AgentConsentController::agentHost($pending));
        self::assertSame(
            ['dev.ucp.shopping.order:read', 'dev.ucp.shopping.cart:manage'],
            AgentConsentController::scopeList($pending),
        );
    }

    public function testAnEmptyScopeListsNothingRatherThanOneBlankEntry(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback',
            '',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertSame([], AgentConsentController::scopeList($pending));
    }

    public function testDenialRedirectsToTheStoredRedirectUriWithTheOriginalState(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback',
            '',
            'state value/with?chars',
            'challenge-value',
            'S256',
        );

        $url = AgentConsentController::denialUrl($pending);

        self::assertStringStartsWith('https://agent.example/callback?', $url);
        self::assertStringContainsString('error=access_denied', $url);
        self::assertStringContainsString('state=' . urlencode('state value/with?chars'), $url);
    }

    public function testDenialAppendsToARedirectUriThatAlreadyHasAQuery(): void
    {
        $pending = new PendingAuthorization(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f01',
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback?existing=1',
            '',
            'state-value',
            'challenge-value',
            'S256',
        );

        self::assertStringContainsString('?existing=1&', AgentConsentController::denialUrl($pending));
    }
}
