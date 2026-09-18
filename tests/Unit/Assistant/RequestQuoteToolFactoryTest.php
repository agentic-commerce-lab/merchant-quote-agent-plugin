<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\AssistantAskStamp;
use MerchantQuoteAgentPlugin\Assistant\RequestQuoteTool;
use MerchantQuoteAgentPlugin\Assistant\RequestQuoteToolFactory;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Validation;

final class RequestQuoteToolFactoryTest extends TestCase
{
    public function testNoMainRequestYieldsNull(): void
    {
        $factory = $this->factory(requestStack: new RequestStack(), toggle: true, gatewayAvailable: true);

        self::assertNull($factory->create($this->toolContext()));
    }

    public function testAnUnavailableGatewayYieldsNull(): void
    {
        $factory = $this->factory(
            requestStack: $this->requestStackWithContext($this->salesChannelContext(customer: new CustomerEntity())),
            toggle: true,
            gatewayAvailable: false,
        );

        self::assertNull($factory->create($this->toolContext()));
    }

    public function testTheToggleBeingOffYieldsNull(): void
    {
        $factory = $this->factory(
            requestStack: $this->requestStackWithContext($this->salesChannelContext(customer: new CustomerEntity())),
            toggle: false,
            gatewayAvailable: true,
        );

        self::assertNull($factory->create($this->toolContext()));
    }

    public function testAGuestYieldsNull(): void
    {
        $factory = $this->factory(
            requestStack: $this->requestStackWithContext($this->salesChannelContext(customer: null)),
            toggle: true,
            gatewayAvailable: true,
        );

        self::assertNull($factory->create($this->toolContext()));
    }

    public function testEverySignalAllowedConstructsTheTool(): void
    {
        $factory = $this->factory(
            requestStack: $this->requestStackWithContext($this->salesChannelContext(customer: new CustomerEntity())),
            toggle: true,
            gatewayAvailable: true,
        );

        self::assertInstanceOf(RequestQuoteTool::class, $factory->create($this->toolContext()));
    }

    private function factory(RequestStack $requestStack, bool $toggle, bool $gatewayAvailable): RequestQuoteToolFactory
    {
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn($gatewayAvailable);

        return new RequestQuoteToolFactory(
            $requestStack,
            $this->settings($toggle),
            new AssistantAskStamp(new NullLogger()),
            $gateway,
        );
    }

    private function settings(bool $toggle): QuoteAgentSettingsReader
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->willReturnCallback(static fn(string $key): mixed => str_ends_with(
            $key,
            'assistantQuoteRequests',
        )
                ? $toggle
                : null);

        return new QuoteAgentSettingsReader(
            $config,
            new QuoteAgentSettingsFactory(
                Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            ),
            $this->createMock(StrategyResolver::class),
        );
    }

    private function requestStackWithContext(SalesChannelContext $context): RequestStack
    {
        $request = new Request();
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $context);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function salesChannelContext(?CustomerEntity $customer): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getSalesChannelId')->willReturn('sales-channel-1');

        return $context;
    }

    private function toolContext(): ToolContext
    {
        return new ToolContext(new TraceRecorder(), new AssistantConfig());
    }
}
