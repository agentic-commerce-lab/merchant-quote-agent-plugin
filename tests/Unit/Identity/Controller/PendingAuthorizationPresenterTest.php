<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Controller\PendingAuthorizationPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The read-only projections a consent page and an OAuth denial are built
 * from: agentHost/scopeList/denialUrl.
 *
 * These used to be reached through static pass-throughs on
 * AgentConsentController; those were a leftover from the split that created
 * this class and had no callers in `src/`, so they are gone and the tests
 * call the presenter directly.
 */
#[CoversClass(PendingAuthorizationPresenter::class)]
final class PendingAuthorizationPresenterTest extends TestCase
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

        self::assertSame('agent.example', PendingAuthorizationPresenter::agentHost($pending));
        self::assertSame(
            ['dev.ucp.shopping.order:read', 'dev.ucp.shopping.cart:manage'],
            PendingAuthorizationPresenter::scopeList($pending),
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

        self::assertSame([], PendingAuthorizationPresenter::scopeList($pending));
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

        $url = PendingAuthorizationPresenter::denialUrl($pending);

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

        self::assertStringContainsString('?existing=1&', PendingAuthorizationPresenter::denialUrl($pending));
    }
}
