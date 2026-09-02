<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use MerchantQuoteAgentPlugin\Identity\AgentAdmittingRuntimeConfigurationResolver;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/**
 * The runtime hooks only work while they are actually the services the container
 * hands out. Both are attached to definitions owned by other packages, so a
 * rename or a competing definition would otherwise degrade silently.
 */
final class AgentAccessWiringTest extends IntegrationTestCase
{
    private const AGENT_URI = 'https://agent.example/.well-known/ucp';

    private ?string $restoreSalesChannelId = null;

    private mixed $restoreFlagValue = null;

    private bool $pushedRequest = false;

    protected function tearDown(): void
    {
        if ($this->restoreSalesChannelId !== null) {
            static::getContainer()
                ->get(SystemConfigService::class)
                ->set(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, $this->restoreFlagValue, $this->restoreSalesChannelId);
        }

        if ($this->pushedRequest) {
            static::getContainer()->get(RequestStack::class)->pop();
        }

        parent::tearDown();
    }

    public function testOurDecoratorIsWhatTheContainerResolvesForTheRuntimeConfiguration(): void
    {
        $resolver = static::getContainer()->get(RuntimeConfigurationResolverInterface::class);

        self::assertInstanceOf(
            AgentAdmittingRuntimeConfigurationResolver::class,
            $resolver,
            'the Agentic Commerce plugin no longer aliases this interface, or another decoration replaced ours',
        );
    }

    public function testOurFactoryBuildsTheValidatorTheContainerResolves(): void
    {
        $validator = static::getContainer()->get(UrlSafetyValidator::class);

        self::assertInstanceOf(
            UrlSafetyValidator::class,
            $validator,
            'nothing defines this service under this id any more',
        );

        // With no allow-any-agent channel in scope, our factory must reproduce
        // the bundle's own behaviour: a host nobody allowlisted stays rejected.
        $this->expectException(ValidationException::class);
        $validator->assertAllowed(self::AGENT_URI);
    }

    /**
     * The one behaviour only OUR definition of this SDK id can produce: a
     * validator that widens for the request in scope. A test that resolved
     * {@see \MerchantQuoteAgentPlugin\Identity\AgentProfileHostValidatorFactory}
     * directly would still pass even if a competing definition had won the
     * SDK's service id, so this goes through that id, exactly as
     * HttpAgentProfileFetcher does. UrlSafetyValidator exposes no getters, so
     * `allowedHosts` is read via reflection, the same way the factory's own
     * unit test does.
     */
    public function testOurFactoryWidensTheValidatorForAnAllowAnyAgentChannel(): void
    {
        $domain = $this->anyStorefrontDomain();
        $host = (string) parse_url($domain['url'], \PHP_URL_HOST);

        $systemConfig = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $systemConfig);

        $this->restoreSalesChannelId = $domain['sales_channel_id'];
        $this->restoreFlagValue = $systemConfig->get(
            AgentAccessFlags::ALLOW_ANY_AGENT_KEY,
            $domain['sales_channel_id'],
        );
        $systemConfig->set(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, true, $domain['sales_channel_id']);

        $requestStack = static::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $request = Request::create('https://' . $host . '/ucp/quotes');
        $request->headers->set('UCP-Agent', 'manual/1.0; profile="' . self::AGENT_URI . '"');
        $requestStack->push($request);
        $this->pushedRequest = true;

        // Non-shared (services.php), so this rebuilds from the request just
        // pushed rather than returning a cached instance from another test.
        $validator = static::getContainer()->get(UrlSafetyValidator::class);
        self::assertInstanceOf(UrlSafetyValidator::class, $validator);

        $property = new \ReflectionProperty(UrlSafetyValidator::class, 'allowedHosts');
        self::assertContains('agent.example', $property->getValue($validator));
    }

    /**
     * @return array{id: string, url: string, sales_channel_id: string, language_id: string, currency_id: string}
     */
    private function anyStorefrontDomain(): array
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $row = $connection->fetchAssociative(
            'SELECT LOWER(HEX(d.id)) AS id, d.url, LOWER(HEX(d.sales_channel_id)) AS sales_channel_id,'
            . ' LOWER(HEX(d.language_id)) AS language_id, LOWER(HEX(d.currency_id)) AS currency_id'
            . ' FROM sales_channel_domain d'
            . ' INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1'
            . ' WHERE d.url LIKE "http%"'
            . ' AND EXISTS (SELECT 1 FROM customer c WHERE c.sales_channel_id = s.id AND c.active = 1)'
            . ' ORDER BY d.url LIMIT 1',
        );

        self::assertIsArray(
            $row,
            'the shop has no active sales-channel domain with an absolute URL whose channel has an active customer',
        );

        /** @var array{id: string, url: string, sales_channel_id: string, language_id: string, currency_id: string} $row */
        return $row;
    }
}
