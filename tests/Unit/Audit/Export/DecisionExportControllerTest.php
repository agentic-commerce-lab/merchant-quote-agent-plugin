<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\DecisionExportController;
use MerchantQuoteAgentPlugin\Audit\Export\DecisionExportStream;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The endpoint's refusals, which the dashboard cannot exercise because it
 * computes both dates itself -- and which is exactly why they are tested here:
 * an authenticated admin route is reachable by more than its own button, and
 * an unparseable date must come back as a 400 rather than as a 500 from
 * DateTimeImmutable.
 *
 * Each of these returns before the export stream is touched, so no repository
 * fixture is needed. The success path reads the decision table and belongs to
 * DecisionExportTest, which already pins what a line contains.
 */
#[CoversClass(DecisionExportController::class)]
final class DecisionExportControllerTest extends TestCase
{
    public function testExportRouteRequiresDecisionAndTraceReadPrivileges(): void
    {
        $method = new \ReflectionMethod(DecisionExportController::class, 'export');
        $attributes = $method->getAttributes(Route::class);
        self::assertCount(1, $attributes);

        $route = $attributes[0]->newInstance();
        self::assertSame(
            ['merchant_quote_agent_decision:read', 'merchant_quote_agent_trace:read'],
            $route->getDefaults()['_acl'],
        );
    }

    public function testItRefusesAMissingRange(): void
    {
        $response = $this->controller()->export(new Request(['to' => '2026-10-01']), Context::createDefaultContext());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('required', (string) $response->getContent());
    }

    public function testItRefusesADateItCannotRead(): void
    {
        $request = new Request(['from' => 'the first of never', 'to' => '2026-10-01']);
        $response = $this->controller()->export($request, Context::createDefaultContext());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('could not be read as a date', (string) $response->getContent());
    }

    public function testItRefusesARangeThatRunsBackwards(): void
    {
        $request = new Request(['from' => '2026-10-01', 'to' => '2026-09-01']);
        $response = $this->controller()->export($request, Context::createDefaultContext());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringContainsString('must be after', (string) $response->getContent());
    }

    /**
     * An equal `from` and `to` is the half-open range's empty case, and the
     * reason the guard is `<=` rather than `<`: a day exported as
     * `--from=X --to=X` would silently produce nothing.
     */
    public function testItRefusesAnEmptyRange(): void
    {
        $request = new Request(['from' => '2026-09-01', 'to' => '2026-09-01']);
        $response = $this->controller()->export($request, Context::createDefaultContext());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * The opposite of the command's default, deliberately: only an explicit
     * `comments=0` withholds free text, so a caller that forgets the parameter
     * gets the fuller file the dashboard's primary button promises rather than
     * a quietly thinner one.
     */
    public function testAValidRangeIsAcceptedAndNamesTheFileAfterIt(): void
    {
        $request = new Request(['from' => '2026-09-01', 'to' => '2026-10-01']);
        $response = $this->controller()->export($request, Context::createDefaultContext());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/x-ndjson', $response->headers->get('Content-Type'));
        self::assertSame(
            'attachment; filename="merchant-quote-agent-2026-09-01-to-2026-10-01.jsonl"',
            $response->headers->get('Content-Disposition'),
        );
    }

    private function controller(): DecisionExportController
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with(ExportPseudonym::CONFIG_KEY)->willReturn('a-fixed-test-salt');

        return new DecisionExportController(
            new DecisionExportStream(
                $this->createMock(EntityRepository::class),
                $this->createMock(EntityRepository::class),
                $config,
            ),
        );
    }
}
