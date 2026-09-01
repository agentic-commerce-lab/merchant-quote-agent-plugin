<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Identity\AgentCustomerCredential;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

#[CoversClass(AgentCustomerCredential::class)]
final class AgentCustomerCredentialTest extends TestCase
{
    public function testItCarriesTheBearerToken(): void
    {
        $credential = AgentCustomerCredential::fromAccessToken('ucp_at_abc');

        self::assertSame('ucp_at_abc', $credential->accessToken);
    }

    public function testItRejectsAnEmptyToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AgentCustomerCredential::fromAccessToken('');
    }

    public function testItReadsABearerAuthorizationHeader(): void
    {
        $credential = AgentCustomerCredential::fromAuthorizationHeader('Bearer ucp_at_abc');

        self::assertSame('ucp_at_abc', $credential->accessToken);
    }

    #[DataProvider('unusableHeaders')]
    public function testItRefusesAnythingThatIsNotABearerToken(string $header): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        AgentCustomerCredential::fromAuthorizationHeader($header);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableHeaders(): iterable
    {
        yield 'absent' => [''];
        yield 'basic' => ['Basic dXNlcjpwYXNz'];
        yield 'bearer without a token' => ['Bearer '];
        yield 'lowercase scheme' => ['bearer ucp_at_abc'];
    }
}
