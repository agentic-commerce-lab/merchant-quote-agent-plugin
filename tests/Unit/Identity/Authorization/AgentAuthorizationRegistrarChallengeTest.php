<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PayloadFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The PKCE challenge's SHAPE, as distinct from its method (`S256`, pinned in
 * AgentAuthorizationRegistrarTest) and from the redirect rules
 * (AgentAuthorizationRegistrarRedirectTest) — split for mago's
 * too-many-methods ceiling.
 *
 * A challenge that cannot be a base64url SHA-256 digest cannot be answered at
 * the token endpoint. Without this check the mismatch surfaces there, minutes
 * later, after a human has already consented and the handle is spent.
 */
#[CoversClass(AgentAuthorizationRegistrar::class)]
#[CoversClass(PayloadFields::class)]
final class AgentAuthorizationRegistrarChallengeTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    /** @param non-empty-string $because */
    private function assertChallengeRefused(string $codeChallenge, string $because): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();
        $payload = AgentAuthorizationRegistrarFixture::payload();
        $payload['code_challenge'] = $codeChallenge;

        try {
            AgentAuthorizationRegistrarFixture::registrar($store)->register(
                $payload,
                AgentAuthorizationRegistrarFixture::verifiedContext(),
                self::SALES_CHANNEL_ID,
            );
            self::fail($because);
        } catch (ValidationException) {
            self::assertSame([], $store->stored, 'nothing may be persisted: ' . $because);
        }
    }

    public function testItRefusesACodeChallengeOfTheWrongLength(): void
    {
        $this->assertChallengeRefused(
            'challenge-value',
            'a code_challenge that is not 43 characters must not be able to register',
        );
    }

    /** Right length, wrong alphabet: standard base64's `+` and `/` are not base64url. */
    public function testItRefusesACodeChallengeUsingTheStandardBase64Alphabet(): void
    {
        $this->assertChallengeRefused(
            str_replace(['_', '-'], ['/', '+'], AgentAuthorizationRegistrarFixture::CODE_CHALLENGE),
            'a code_challenge in the standard base64 alphabet must not be able to register',
        );
    }

    /** 44 characters: base64 padding is what a `=` at the end means, and S256 challenges carry none. */
    public function testItRefusesAPaddedCodeChallenge(): void
    {
        $this->assertChallengeRefused(
            substr(AgentAuthorizationRegistrarFixture::CODE_CHALLENGE, 0, 42) . '==',
            'a padded code_challenge must not be able to register',
        );
    }

    public function testItAcceptsARealS256Challenge(): void
    {
        $store = AgentAuthorizationRegistrarFixture::store();

        AgentAuthorizationRegistrarFixture::registrar($store)->register(
            AgentAuthorizationRegistrarFixture::payload(),
            AgentAuthorizationRegistrarFixture::verifiedContext(),
            self::SALES_CHANNEL_ID,
        );

        self::assertCount(1, $store->stored);
        self::assertSame(AgentAuthorizationRegistrarFixture::CODE_CHALLENGE, $store->stored[0]->codeChallenge);
    }
}
