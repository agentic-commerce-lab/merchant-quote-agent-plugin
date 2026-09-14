<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Http\A2cnNotFoundController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

final class A2cnNotFoundControllerTest extends TestCase
{
    public function testAnUnimplementedProtocolPathAnswers404AsA2cnJson(): void
    {
        // Not the storefront's HTML error page under HTTP 200, which a client
        // cannot distinguish from a successful call.
        $response = (new A2cnNotFoundController())->notFound();

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/a2cn+json', $response->headers->get('Content-Type'));
        self::assertSame('{"status":"not_found"}', $response->getContent());
    }

    public function testTheCatchAllSortsBehindEveryRealRoute(): void
    {
        // The matcher takes the first route that matches, so a catch-all at
        // the default priority would swallow the whole module. This is the
        // property the class is only safe because of.
        self::assertLessThan(0, self::route()->priority);
    }

    public function testTheCatchAllCoversTheEndpointRootAndEverythingUnderIt(): void
    {
        $route = self::route();

        self::assertSame('/a2cn/{trail}', $route->getPath());
        self::assertSame('.*', $route->getRequirements()['trail'] ?? null);
        self::assertSame('', $route->getDefaults()['trail'] ?? null);
    }

    public function testItAnswersWritesAndNotOnlyReads(): void
    {
        // A buyer POSTing an act to a path we do not serve deserves the same
        // machine-readable refusal as one reading from it.
        self::assertContains('POST', self::route()->getMethods());
    }

    private static function route(): Route
    {
        $method = new \ReflectionMethod(A2cnNotFoundController::class, 'notFound');
        $attribute = $method->getAttributes(Route::class)[0] ?? null;
        self::assertNotNull($attribute);

        return $attribute->newInstance();
    }
}
