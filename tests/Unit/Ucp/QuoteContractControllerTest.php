<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapabilityDescriptor;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteContractController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The descriptor advertises two absolute URLs on the shop's own domain; if the
 * documents behind them are missing or unparseable, discovery is broken for
 * every agent that reads it.
 */
#[CoversClass(QuoteContractController::class)]
final class QuoteContractControllerTest extends TestCase
{
    public function testItServesTheAdvertisedOpenApiContract(): void
    {
        $response = $this->controller()->schema();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $schema */
        $schema = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('3.1.0', $schema['openapi']);
        self::assertSame(QuoteCapabilityDescriptor::NAME, $schema['info']['title']);
        self::assertSame(QuoteCapabilityDescriptor::VERSION, $schema['info']['version']);
        self::assertArrayHasKey('/ucp/quotes', $schema['paths']);
    }

    public function testItServesTheAdvertisedProseSpecification(): void
    {
        $response = $this->controller()->spec();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString(QuoteCapabilityDescriptor::NAME, (string) $response->getContent());
    }

    public function testItReportsNotFoundRatherThanLeakingAFilesystemErrorWhenADocumentIsMissing(): void
    {
        $controller = new QuoteContractController('/nonexistent/quote.openapi.json', '/nonexistent/quote.html');

        self::assertSame(Response::HTTP_NOT_FOUND, $controller->schema()->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->spec()->getStatusCode());
    }

    /**
     * The paths the container injects, taken from the same place
     * Resources/config/services.php takes them.
     */
    private function controller(): QuoteContractController
    {
        $schema = \dirname(__DIR__, 3) . '/src/Resources/schema/quote.openapi.json';
        $spec = \dirname(__DIR__, 3) . '/src/Resources/schema/quote.spec.html';

        return new QuoteContractController($schema, $spec);
    }
}
