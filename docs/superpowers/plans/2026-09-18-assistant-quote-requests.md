# Assistant-Requested Quotes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the shopping-assistant-starter-kit's assistant request a B2B quote from the buyer's cart, in the buyer's own session, and read its status back — optionally, with the starter kit absent changing nothing.

**Architecture:** Two Symfony AI tools contributed on the starter kit's unprivileged `swag_assistant.tool_factory` tier by a factory that is our own DI service, so it can inject `RequestStack` and `BuyerQuoteGatewayInterface` — the seam UCP already uses. Registration is gated on the starter-kit bundle appearing in `kernel.bundles`, so its absence means the classes are never autoloaded. The quote that results is an ordinary hand-made storefront quote, serviced by the loop that already exists.

**Tech Stack:** PHP 8.3, Shopware 6.7.1, Symfony 7.4 DI, Symfony AI Agent `#[AsTool]`, PHPUnit, mago (format + lint + analyze).

**Spec:** `docs/superpowers/specs/2026-09-18-assistant-quote-requests-design.md`

## Global Constraints

- PHP `^8.3`; every new file starts `<?php` + blank line + `declare(strict_types=1);`.
- Classes are `final`; value objects are `final readonly`. Interface implementations carry `#[Override]`.
- **No composer dependency on the starter kit.** The two upstream types are hand-written stubs under `tests/`, autoloaded only for the test suites.
- **Never `class_exists` on the starter kit.** Gate on `kernel.bundles`, per `Ucp\UcpAvailability`.
- Upstream service tag: `swag_assistant.tool_factory`. Upstream interface: `Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface`, method `create(ToolContext $context): ?object`. Upstream context: `Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext`, public `TraceRecorder $trace`, public `AssistantConfig $config` — **do not ask upstream to widen it**, they assert its property list in `ToolAuthorityTest`.
- Upstream bundle name in `kernel.bundles`: `SwagAssistantStarterKit` (from `src/SwagAssistantStarterKit.php`, which does not override `getName()`). Task 7 verifies this against a live shop.
- Config domain prefix and reader live in `src/Config/QuoteAgentSettingsReader.php`. New field default is **off**.
- `CappedAuthority` is **not** touched. An assistant-proposed figure caps exactly like a typed one; narrowing it is a separate spec.
- Run `vendor/bin/mago format && vendor/bin/mago lint && vendor/bin/mago analyze` before each commit; the pre-commit hook runs format + lint on staged files anyway.
- Unit suite: `vendor/bin/phpunit --testsuite unit`. Integration suite needs a live shop: `vendor/bin/phpunit -c phpunit.integration.xml.dist`.

---

### Task 1: Split a quote request's line items into additions and price asks

Pure array logic extracted out of the gateway so it can be tested without a shop, and so Task 2's guard change has something small to sit behind.

**Files:**
- Create: `src/Bridge/RequestedQuoteLines.php`
- Test: `tests/Unit/Bridge/RequestedQuoteLinesTest.php`

**Interfaces:**
- Consumes: `MerchantQuoteAgentPlugin\Bridge\CommercialQuoteLinePricing` (`requireProductId(array, int): string`, `requestedPrice(array, string): ?float`, `assertCanPriceLines(): void`).
- Produces: `RequestedQuoteLines::from(array $lineItems, CommercialQuoteLinePricing $pricing): self` with public `array $additions` (each `array{product_id: string, quantity: int}`) and public `array $requestedPrices` (`array<string, float>`, product id → price). Task 2 consumes both.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteLinePricing;
use MerchantQuoteAgentPlugin\Bridge\RequestedQuoteLines;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The split a quote request makes before it touches a cart: which lines get
 * ADDED, and which only carry a price ask for a line that is already there.
 *
 * The second kind is what the shopping assistant sends. Its buyer filled the
 * cart through the starter kit's `add_to_cart`, so re-sending those lines with
 * a quantity would double every one of them.
 */
final class RequestedQuoteLinesTest extends TestCase
{
    public function testALineWithAQuantityIsAnAddition(): void
    {
        $lines = RequestedQuoteLines::from(
            [['product_id' => 'prod-1', 'quantity' => 3]],
            $this->pricing(),
        );

        self::assertSame([['product_id' => 'prod-1', 'quantity' => 3]], $lines->additions);
        self::assertSame([], $lines->requestedPrices);
    }

    public function testALineWithoutAQuantityIsAPriceAskOnly(): void
    {
        $lines = RequestedQuoteLines::from(
            [['product_id' => 'prod-1', 'requested_unit_price' => '98.00']],
            $this->pricing(),
        );

        self::assertSame([], $lines->additions);
        self::assertSame(['prod-1' => 98.00], $lines->requestedPrices);
    }

    public function testALineCanBothAddAndAsk(): void
    {
        $lines = RequestedQuoteLines::from(
            [['product_id' => 'prod-1', 'quantity' => 2, 'requested_unit_price' => '98.00']],
            $this->pricing(),
        );

        self::assertSame([['product_id' => 'prod-1', 'quantity' => 2]], $lines->additions);
        self::assertSame(['prod-1' => 98.00], $lines->requestedPrices);
    }

    public function testAnEmptyRequestIsLegalAndAddsNothing(): void
    {
        $lines = RequestedQuoteLines::from([], $this->pricing());

        self::assertSame([], $lines->additions);
        self::assertSame([], $lines->requestedPrices);
    }

    /**
     * A quantity that is present must still be a real quantity. Absent means
     * "already in the cart"; zero or negative means the caller is confused,
     * and silently dropping it would quote something the buyer never asked
     * for.
     */
    public function testAZeroQuantityIsRejectedRatherThanTreatedAsAbsent(): void
    {
        $this->expectException(ValidationException::class);

        RequestedQuoteLines::from([['product_id' => 'prod-1', 'quantity' => 0]], $this->pricing());
    }

    public function testALineThatNeitherAddsNorAsksIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        RequestedQuoteLines::from([['product_id' => 'prod-1']], $this->pricing());
    }

    public function testAMissingProductIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        RequestedQuoteLines::from([['quantity' => 1]], $this->pricing());
    }

    private function pricing(): CommercialQuoteLinePricing
    {
        return new CommercialQuoteLinePricing(new \stdClass());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter RequestedQuoteLinesTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Bridge\RequestedQuoteLines" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Ucp\Sdk\Exception\ValidationException;

/**
 * One quote request's line items, split into what to ADD to the cart and what
 * price to ASK for.
 *
 * The split exists because two callers mean two different things by a line
 * item. A buyer agent over UCP arrives with no cart, so every line is an
 * addition. The shopping assistant's buyer already filled the cart through the
 * starter kit's `add_to_cart`, so its lines carry a price ask and nothing to
 * add — re-sending them with a quantity would double every line.
 *
 * A line with no `quantity` is therefore price-only, and a request with no
 * lines at all is legal and means "quote what is already in the cart". What
 * neither may do is be empty of both: the cart emptiness check lives in the
 * gateway, after the additions land, because only there is the answer known.
 */
final readonly class RequestedQuoteLines
{
    /**
     * @param list<array{product_id: string, quantity: int}> $additions
     * @param array<string, float> $requestedPrices
     */
    private function __construct(
        public array $additions,
        public array $requestedPrices,
    ) {}

    /**
     * @param list<array{product_id?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems
     *
     * @throws ValidationException
     */
    public static function from(array $lineItems, CommercialQuoteLinePricing $pricing): self
    {
        $additions = [];
        $requestedPrices = [];

        foreach ($lineItems as $index => $lineItem) {
            /** @mago-expect analysis:possibly-invalid-argument */
            $productId = $pricing->requireProductId($lineItem, $index);

            /** @mago-expect analysis:possibly-invalid-argument */
            $requestedPrice = $pricing->requestedPrice($lineItem, \sprintf(
                '$.line_items[%d].requested_unit_price',
                $index,
            ));

            if (null !== $requestedPrice) {
                $pricing->assertCanPriceLines();
                $requestedPrices[$productId] = $requestedPrice;
            }

            $quantity = $lineItem['quantity'] ?? null;

            // Absent is the assistant's "already in the cart". Present but not
            // a positive integer is a caller that meant something and got it
            // wrong, which is worth an error rather than a silent drop.
            if (null === $quantity) {
                if (null === $requestedPrice) {
                    throw new ValidationException(
                        'A quote line item must add a quantity, ask a price, or both.',
                        [\sprintf('$.line_items[%d] needs quantity or requested_unit_price', $index)],
                    );
                }

                continue;
            }

            if (!\is_int($quantity) || $quantity < 1) {
                throw new ValidationException('Line item quantity must be a positive integer.', [\sprintf(
                    '$.line_items[%d].quantity must be >= 1',
                    $index,
                )]);
            }

            $additions[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return new self($additions, $requestedPrices);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter RequestedQuoteLinesTest`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
vendor/bin/mago format && vendor/bin/mago lint && vendor/bin/mago analyze
git add src/Bridge/RequestedQuoteLines.php tests/Unit/Bridge/RequestedQuoteLinesTest.php
git commit -m "feat(bridge): split quote request lines into additions and asks"
```

---

### Task 2: Let a quote request quote the cart that is already there

**Files:**
- Modify: `src/Bridge/SwagCommercialBuyerQuoteGateway.php:94-152` (the head of `requestQuote()`)
- Test: `tests/Integration/AssistantCartQuoteTest.php`

**Interfaces:**
- Consumes: `RequestedQuoteLines::from()` from Task 1.
- Produces: `BuyerQuoteGatewayInterface::requestQuote()` now accepts `$lineItems === []` when the cart is non-empty. Tasks 4 and 6 rely on that.

- [ ] **Step 1: Write the failing test**

`tests/Integration/AssistantCartQuoteTest.php` — follow `BuyerQuoteFlowTest`'s shape; it extends `IntegrationTestCase` and uses `BuyerQuoteFixture` / `BuyerQuoteContextFixture` to get a logged-in buyer context.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The shopping assistant's request shape: the cart is already full, and the
 * request adds nothing.
 *
 * BuyerQuoteFlowTest covers the UCP shape, where the cart starts empty and
 * every line is an addition. This covers the other one, and the guard that now
 * separates them.
 */
final class AssistantCartQuoteTest extends IntegrationTestCase
{
    public function testAnEmptyLineItemListQuotesTheCartThatIsAlreadyThere(): void
    {
        $context = BuyerQuoteContextFixture::loggedInBuyer(static::getContainer());
        $cartService = static::getContainer()->get(CartService::class);
        self::assertInstanceOf(CartService::class, $cartService);

        $productId = BuyerQuoteFixture::product(static::getContainer());
        $cart = $cartService->getCart($context->getToken(), $context);
        $cartService->add($cart, [BuyerQuoteFixture::lineItem($productId, 4, $context)], $context);

        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        $snapshot = $gateway->requestQuote($context, [], 'Can you do better on these?');

        self::assertCount(1, $snapshot->lineItems);
        self::assertSame(4, $snapshot->lineItems[0]['quantity']);
    }

    public function testAnEmptyCartWithAnEmptyRequestStillFails(): void
    {
        $context = BuyerQuoteContextFixture::loggedInBuyer(static::getContainer());

        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        $this->expectException(ValidationException::class);

        $gateway->requestQuote($context, [], null);
    }

    public function testAPriceOnlyLineAsksWithoutAddingASecondLine(): void
    {
        $context = BuyerQuoteContextFixture::loggedInBuyer(static::getContainer());
        $cartService = static::getContainer()->get(CartService::class);
        self::assertInstanceOf(CartService::class, $cartService);

        $productId = BuyerQuoteFixture::product(static::getContainer());
        $cart = $cartService->getCart($context->getToken(), $context);
        $cartService->add($cart, [BuyerQuoteFixture::lineItem($productId, 2, $context)], $context);

        $gateway = static::getContainer()->get(BuyerQuoteGatewayInterface::class);
        self::assertInstanceOf(BuyerQuoteGatewayInterface::class, $gateway);

        $snapshot = $gateway->requestQuote(
            $context,
            [['product_id' => $productId, 'requested_unit_price' => '98.00']],
            null,
        );

        self::assertCount(1, $snapshot->lineItems);
        self::assertSame(2, $snapshot->lineItems[0]['quantity']);
        self::assertSame(98.00, $snapshot->lineItems[0]['requested_unit_price']);
    }
}
```

If `BuyerQuoteFixture` has no `product()` or `lineItem()` helper with those signatures, add them there rather than inlining DAL writes in this test — `BuyerQuoteFlowTest` already needs the same two things and should use them too.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.integration.xml.dist --filter AssistantCartQuoteTest`
Expected: FAIL — the first and third cases throw `ValidationException` ("A quote request needs at least one line item.") from the old guard.

- [ ] **Step 3: Write minimal implementation**

Replace the body of `requestQuote()` from the emptiness guard through the `cartService->add()` call with:

```php
        $this->access->assertServable();
        $customerId = $this->access->requireCustomerId($context);
        $this->access->assertCustomerHasQuoteFeature($customerId);

        $lines = RequestedQuoteLines::from($lineItems, $this->linePricing);
        $requestedPrices = $lines->requestedPrices;

        /** @var list<LineItem> $items */
        $items = [];
        foreach ($lines->additions as $addition) {
            $items[] = $this->lineItemFactory->create([
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'referencedId' => $addition['product_id'],
                'quantity' => $addition['quantity'],
            ], $context);
        }

        // Deliberately through CartService rather than the item-add route directly:
        // the commercial quote route reads the cart back through CartService, which
        // caches per context token, so a cart filled around it would look empty to
        // the quote. CartService itself delegates to the Store API item-add route,
        // so the route boundary is still respected.
        $cart = $this->cartService->getCart($context->getToken(), $context);
        if ([] !== $items) {
            $this->cartService->add($cart, $items, $context);
        }

        // The emptiness check moved here from the head of the method, because
        // "did the buyer ask for anything?" is only answerable once the
        // additions have landed. An assistant-sent request adds nothing and
        // quotes a cart the buyer filled through the starter kit's
        // `add_to_cart`; a UCP request adds everything and its old
        // empty-array rejection now fails one step later, identically.
        if (0 === $cart->getLineItems()->count()) {
            throw new ValidationException('A quote request needs a line item or a cart.', [
                '$.line_items must not be empty when the cart is empty',
            ]);
        }
```

Add `use MerchantQuoteAgentPlugin\Bridge\RequestedQuoteLines;` — same namespace, so no import needed; delete any import left unused by the removed loop.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c phpunit.integration.xml.dist --filter 'AssistantCartQuoteTest|BuyerQuoteFlowTest|LegacyBuyerFlowTest'`
Expected: PASS. `BuyerQuoteFlowTest` and `LegacyBuyerFlowTest` are the regression guard — UCP's behaviour must be unchanged.

- [ ] **Step 5: Commit**

```bash
vendor/bin/mago format && vendor/bin/mago lint && vendor/bin/mago analyze
git add src/Bridge/SwagCommercialBuyerQuoteGateway.php tests/Integration/AssistantCartQuoteTest.php tests/Integration/BuyerQuoteFixture.php
git commit -m "feat(bridge): quote the cart a buyer already filled"
```

---

### Task 3: Gate on the starter-kit bundle

**Files:**
- Create: `src/Assistant/AssistantAvailability.php`
- Create: `tests/Stub/SwagAssistantStarterKit/Core/Tool/Factory/ToolFactoryInterface.php`
- Create: `tests/Stub/SwagAssistantStarterKit/Core/Tool/Factory/ToolContext.php`
- Create: `tests/Stub/SwagAssistantStarterKit/Core/Trace/TraceRecorder.php`
- Create: `tests/Stub/SwagAssistantStarterKit/Core/Policy/AssistantConfig.php`
- Modify: `composer.json` (autoload-dev PSR-4 entry for the stubs)
- Test: `tests/Unit/Assistant/AssistantAvailabilityTest.php`

**Interfaces:**
- Produces: `AssistantAvailability::isRegistered(?ContainerInterface $container): bool`, consumed by `services.php` in Task 4.
- Produces: the four stub types under `Swag\AssistantStarterKit\`, which Task 4's factory implements and type-hints against.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\AssistantAvailability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AssistantAvailabilityTest extends TestCase
{
    /**
     * Same four shapes UcpAvailabilityTest checks, and for the same reason —
     * see AssistantAvailability on why this reads the bundle list rather than
     * the classpath.
     */
    public function testRegistrationFollowsTheBundleList(): void
    {
        self::assertFalse(AssistantAvailability::isRegistered(null));

        $withoutParameter = new ContainerBuilder();
        self::assertFalse(AssistantAvailability::isRegistered($withoutParameter));

        $withoutBundle = new ContainerBuilder();
        $withoutBundle->setParameter('kernel.bundles', ['Framework' => 'Shopware\\Core\\Framework\\Framework']);
        self::assertFalse(AssistantAvailability::isRegistered($withoutBundle));

        $withBundle = new ContainerBuilder();
        $withBundle->setParameter('kernel.bundles', [
            'SwagAssistantStarterKit' => 'Swag\\AssistantStarterKit\\SwagAssistantStarterKit',
        ]);
        self::assertTrue(AssistantAvailability::isRegistered($withBundle));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AssistantAvailabilityTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Assistant\AssistantAvailability" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Assistant/AssistantAvailability.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Whether this shop runs the shopping-assistant-starter-kit.
 *
 * That plugin registers the bundle `SwagAssistantStarterKit`, and with it the
 * `swag_assistant.tool_factory` tag and the `ToolFactoryInterface` our factory
 * implements. Without it, nothing this plugin contributes to the assistant is
 * registered — and, because the interface is not in this repo's vendor tree at
 * all, the factory class must never be autoloaded either. Keeping it out of
 * the container is what keeps it off the classpath.
 *
 * `kernel.bundles`, not `class_exists`, for the reasons Ucp\UcpAvailability
 * sets out at length: a vendored plugin's namespace stays in Composer's
 * autoloader after deactivation, and a container rebuild in the same process
 * keeps the boot-time autoloaders. The bundle list has no such lag.
 *
 * Everything else survives its absence: quotes a buyer creates by hand are
 * serviced, escalated and audited exactly as before. Only the two chat tools
 * switch off.
 */
final class AssistantAvailability
{
    /** `Bundle::getName()` is the short class name; the plugin does not override it. */
    private const ASSISTANT_BUNDLE = 'SwagAssistantStarterKit';

    public static function isRegistered(?ContainerInterface $container): bool
    {
        if ($container === null || !$container->hasParameter('kernel.bundles')) {
            return false;
        }

        $bundles = $container->getParameter('kernel.bundles');

        return \is_array($bundles) && \array_key_exists(self::ASSISTANT_BUNDLE, $bundles);
    }
}
```

The four stubs, each a faithful copy of upstream's shape and nothing more. `tests/Stub/SwagAssistantStarterKit/Core/Tool/Factory/ToolFactoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

/**
 * STUB of shopping-assistant-starter-kit's interface, for static analysis and
 * unit tests only. This repo takes no composer dependency on that plugin, so
 * the real interface is absent here and present in any shop that runs it.
 *
 * Nothing but `ToolWiringTest` can tell you this stub still matches upstream.
 * If that test fails, fix this file — do not weaken the test.
 */
interface ToolFactoryInterface
{
    public function create(ToolContext $context): ?object;
}
```

`ToolContext.php`:

```php
<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/** STUB — see ToolFactoryInterface. Upstream asserts this property list in ToolAuthorityTest. */
final readonly class ToolContext
{
    public function __construct(
        public TraceRecorder $trace,
        public AssistantConfig $config,
    ) {}
}
```

`TraceRecorder.php` and `AssistantConfig.php` need only what we call. `TraceRecorder` needs `record(string $name, array $payload): void`; `AssistantConfig` is referenced only as a type. Write both as stub classes with that one method / no members, each carrying the same STUB docblock.

Then add to `composer.json` under `autoload-dev`:

```json
"psr-4": {
    "MerchantQuoteAgentPlugin\\Tests\\": "tests/",
    "Swag\\AssistantStarterKit\\": "tests/Stub/SwagAssistantStarterKit/"
}
```

Keep the existing `MerchantQuoteAgentPlugin\Tests\` entry as it is; add the second key beside it. Run `composer dump-autoload` after.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer dump-autoload && vendor/bin/phpunit --testsuite unit --filter AssistantAvailabilityTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/mago format && vendor/bin/mago lint && vendor/bin/mago analyze
git add src/Assistant tests/Stub tests/Unit/Assistant composer.json composer.lock
git commit -m "feat(assistant): gate on the starter-kit bundle, stub its two types"
```

---

### Task 4: The `request_quote` tool and its factory

**Files:**
- Create: `src/Assistant/RequestQuoteTool.php`
- Create: `src/Assistant/RequestQuoteToolFactory.php`
- Modify: `src/Resources/config/config.xml` (new card + field)
- Modify: `src/Config/QuoteAgentSettingsReader.php` (new key + accessor)
- Modify: `src/Resources/config/services.php` (registration behind both gates)
- Test: `tests/Unit/Assistant/RequestQuoteToolTest.php`, `tests/Unit/Assistant/RequestQuoteToolFactoryTest.php`

**Interfaces:**
- Consumes: `AssistantAvailability::isRegistered()` (Task 3), the stub `ToolFactoryInterface`/`ToolContext` (Task 3), `BuyerQuoteGatewayInterface::requestQuote()` (Task 2).
- Produces: `RequestQuoteTool::__invoke(string $comment, array $targets = [], string $targetSource = 'buyer_stated'): array` returning `array{quote_number: string, state: string, note: string}`. Task 5 stamps from inside it; Task 6 adds a second tool to the same factory.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\RequestQuoteTool;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ValidationException;

final class RequestQuoteToolTest extends TestCase
{
    /**
     * The tool returns the quote's identity and NOTHING about money. The
     * merchant agent replies minutes later, so any figure here would be the
     * buyer's own ask handed back — which is what a model turns into "I got
     * you 12% off".
     */
    public function testTheResultCarriesNoPrices(): void
    {
        $result = $this->tool()->__invoke('Can you do better on these?');

        self::assertSame(['quote_number', 'state', 'note'], array_keys($result));
        self::assertStringNotContainsString('%', $result['note']);
    }

    public function testATargetPriceBecomesAPriceOnlyLine(): void
    {
        $this->tool()->__invoke('98 each would work', [['product_id' => 'prod-1', 'unit_price' => 98.0]]);

        self::assertSame(
            [['product_id' => 'prod-1', 'requested_unit_price' => 98.0]],
            $this->lastLineItems,
        );
    }

    public function testAnInventedTargetSourceIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->tool()->__invoke('cheaper please', [], 'model_decided');
    }

    public function testTheCommentIsBounded(): void
    {
        $this->tool()->__invoke(str_repeat('a', 5_000));

        self::assertSame(2_000, mb_strlen((string) $this->lastComment));
    }
}
```

The two helpers the test above uses — a recording gateway and a snapshot — as real code, at the
bottom of the same class:

```php
    private ?array $lastLineItems = null;

    private ?string $lastComment = null;

    private function tool(): RequestQuoteTool
    {
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(true);
        $gateway->method('requestQuote')->willReturnCallback(
            function (SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot {
                $this->lastLineItems = $lineItems;
                $this->lastComment = $comment;

                return self::snapshot();
            },
        );

        return new RequestQuoteTool($gateway, $this->createMock(SalesChannelContext::class));
    }

    private static function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            id: 'quote-1',
            quoteNumber: 'Q1001',
            state: 'open',
            expirationDate: null,
            currency: 'EUR',
            totalGross: 238.00,
            totalNet: 200.00,
            taxStatus: 'net',
            lineItems: [],
            comments: [],
        );
    }
```

`$this->lastLineItems` / `$this->lastComment` replace the `$gateway->lastLineItems` reads in the two
tests above — adjust those two assertions to read the properties instead.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter RequestQuoteToolTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Assistant\RequestQuoteTool" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Assistant/RequestQuoteTool.php` — the `#[AsTool]` attribute comes from `Symfony\AI\Agent\Toolbox\Attribute\AsTool`, which is in this repo's tree via `symfony/ai-platform`; if it is not, add the same class as a stub under `tests/Stub` and let the live shop supply the real one.

```php
#[AsTool(
    name: 'request_quote',
    description: 'Ask the shop for a quote on everything in the shopper\'s cart. Use when the '
    . 'shopper asks for a discount, a bulk price, or a quote. The shop replies later, not now.',
)]
final class RequestQuoteTool
{
    private const MAX_COMMENT = 2_000;

    private const SOURCES = ['buyer_stated', 'assistant_proposed'];

    /**
     * What the model is told once the quote exists.
     *
     * The prohibition is explicit for the reason the starter kit's EscalateTool
     * documents from six-of-six live runs: given a result and no instruction, a
     * model narrates an outcome. There is no outcome yet — the merchant agent
     * has not looked at this quote — so every such sentence is a false claim
     * about the merchant's operations, made to a customer.
     */
    private const NOTE =
        'Say that the request is with the shop and that they will reply, and give the quote number. '
            . 'Nothing has been decided: do not say a discount was granted, approved, applied or '
            . 'secured, do not predict what the shop will offer, and do not state any price or '
            . 'percentage for this quote.';

    public function __construct(
        private readonly BuyerQuoteGatewayInterface $gateway,
        private readonly SalesChannelContext $context,
    ) {}

    /**
     * @param string $comment The shopper's own words about what they want, in one or two sentences.
     * @param list<array{product_id: string, unit_price: float}> $targets Per-unit prices to ask for.
     * @param string $targetSource Either `buyer_stated` or `assistant_proposed`.
     *
     * @return array{quote_number: string, state: string, note: string}
     */
    public function __invoke(string $comment, array $targets = [], string $targetSource = 'buyer_stated'): array
    {
        if (!\in_array($targetSource, self::SOURCES, true)) {
            throw new ValidationException('Unknown target source.', [
                '$.target_source must be buyer_stated or assistant_proposed',
            ]);
        }

        $lineItems = array_map(
            static fn (array $target): array => [
                'product_id' => $target['product_id'],
                'requested_unit_price' => $target['unit_price'],
            ],
            $targets,
        );

        $snapshot = $this->gateway->requestQuote(
            $this->context,
            $lineItems,
            mb_substr(trim($comment), 0, self::MAX_COMMENT),
        );

        return [
            'quote_number' => $snapshot->quoteNumber,
            'state' => $snapshot->state ?? 'open',
            'note' => self::NOTE,
        ];
    }
}
```

`src/Assistant/RequestQuoteToolFactory.php`:

```php
final readonly class RequestQuoteToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private QuoteAgentSettingsReader $settings,
        private ?BuyerQuoteGatewayInterface $gateway = null,
    ) {}

    /**
     * Null rather than a disabled tool, which is the starter kit's own rule
     * (D6) and ours: a tool that is never constructed never reaches the schema
     * the model sees, so it cannot be talked into using one.
     *
     * The sales-channel context comes from the request rather than from
     * ToolContext, which carries only a trace and the merchant's settings and
     * must stay that way — upstream asserts its property list.
     */
    #[Override]
    public function create(ToolContext $context): ?object
    {
        $request = $this->requestStack->getMainRequest();
        $salesChannelContext = $request?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT);

        if (!$salesChannelContext instanceof SalesChannelContext) {
            return null;
        }

        if (null === $this->gateway || !$this->gateway->isAvailable()) {
            return null;
        }

        if (!$this->settings->assistantQuoteRequests($salesChannelContext->getSalesChannelId())) {
            return null;
        }

        // A guest has no quotes and no B2B employee behind them. Asking the
        // gateway would raise; returning null keeps the tool off the schema,
        // so the model offers a quote only to someone who could get one.
        if (null === $salesChannelContext->getCustomer()) {
            return null;
        }

        return new RequestQuoteTool($this->gateway, $salesChannelContext);
    }
}
```

`config.xml` — a new card before `</config>`:

```xml
    <card>
        <title>Shopping assistant</title>
        <input-field type="bool">
            <name>assistantQuoteRequests</name>
            <label>Let the shopping assistant request quotes</label>
            <defaultValue>false</defaultValue>
            <helpText>When enabled, and the shopping-assistant-starter-kit plugin is installed, the assistant can turn a shopper's cart into a quote request in that shopper's name. Off by default: this acts for the buyer, so it is opted into rather than switched on by installing two plugins.</helpText>
        </input-field>
    </card>
```

`QuoteAgentSettingsReader` — add `'assistantQuoteRequests'` to the key list at line 49 and:

```php
    /** Default off: absent or false both mean the assistant may not act for the buyer. */
    public function assistantQuoteRequests(?string $salesChannelId): bool
    {
        return $this->config->get(self::DOMAIN . 'assistantQuoteRequests', $salesChannelId) === true;
    }
```

`services.php` — a new block, inside the existing `CommercialAvailability::isAvailableByClass()` region (the gateway is only there then), guarded again:

```php
    // The shopping assistant's two tools. Both gates matter: without the
    // starter-kit bundle the tag has no collector and ToolFactoryInterface is
    // not on the classpath at all, so registering the factory would fatal on
    // autoload rather than degrade.
    if (AssistantAvailability::isRegistered($container)) {
        $services->set(RequestQuoteToolFactory::class)
            ->args([
                service('request_stack'),
                service(QuoteAgentSettingsReader::class),
                service(BuyerQuoteGatewayInterface::class)->nullOnInvalid(),
            ])
            ->autoconfigure(false)
            ->tag('swag_assistant.tool_factory');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite unit --filter 'RequestQuoteTool'`
Expected: PASS.

Then write `RequestQuoteToolFactoryTest` covering the four null cases — no main request, gateway unavailable, toggle off, guest — plus the one success case, and run it.

- [ ] **Step 5: Commit**

```bash
vendor/bin/mago format && vendor/bin/mago lint && vendor/bin/mago analyze
git add src/Assistant src/Config/QuoteAgentSettingsReader.php src/Resources/config tests/Unit/Assistant
git commit -m "feat(assistant): contribute a request_quote tool to the starter kit"
```

---

### Task 5: Record who authored the price ask

**Files:**
- Create: `src/Assistant/AssistantAskStamp.php`
- Modify: `src/Assistant/RequestQuoteTool.php` (call the stamp), `src/Assistant/RequestQuoteToolFactory.php` (inject it), `src/Resources/config/services.php`
- Test: `tests/Unit/Assistant/AssistantAskStampTest.php`

**Interfaces:**
- Consumes: `MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface::updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void` and `Bridge\Data\QuoteUpdate` (its `customFields` are shallow-merged by the gateway, never replaced).
- Produces: `AssistantAskStamp::stamp(QuoteSnapshot $snapshot, string $targetSource): void` and the custom-field key `merchantQuoteAgentAssistantAsk`. Task 4's tool calls it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\AssistantAskStamp;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;

/**
 * Who wrote the figure, recorded next to the quote.
 *
 * The buyer may have typed "98 each", or the assistant may have proposed 10%
 * and the buyer agreed. Both reach the policy engine as the same number, so
 * without this the decision log attributes a model's figure to a person.
 */
final class AssistantAskStampTest extends TestCase
{
    public function testItRecordsTheSourceOnTheQuote(): void
    {
        $written = null;
        $gateway = $this->createMock(QuoteGatewayInterface::class);
        $gateway->method('updateQuote')->willReturnCallback(
            static function (string $quoteId, QuoteUpdate $update) use (&$written): void {
                $written = [$quoteId, $update->customFields];
            },
        );

        (new AssistantAskStamp(new NullLogger(), $gateway))->stamp(self::snapshot(), 'assistant_proposed');

        self::assertSame(
            ['quote-1', ['merchantQuoteAgentAssistantAsk' => 'assistant_proposed']],
            $written,
        );
    }

    public function testWithoutAGatewayItDoesNothing(): void
    {
        $this->expectNotToPerformAssertions();

        (new AssistantAskStamp(new NullLogger()))->stamp(self::snapshot(), 'buyer_stated');
    }

    /**
     * Fail-open, like A2cnSessionStamp: the quote exists and the buyer is
     * waiting on it. Losing the provenance note is a logged warning; losing
     * the buyer their quote over one would not be a trade worth making.
     */
    public function testAFailedWriteIsLoggedAndSwallowed(): void
    {
        $gateway = $this->createMock(QuoteGatewayInterface::class);
        $gateway->method('updateQuote')->willThrowException(new \RuntimeException('database gone'));

        $logger = new TestLogger();

        (new AssistantAskStamp($logger, $gateway))->stamp(self::snapshot(), 'assistant_proposed');

        self::assertTrue($logger->hasWarningRecords());
    }

    private static function snapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            id: 'quote-1',
            quoteNumber: 'Q1001',
            state: 'open',
            expirationDate: null,
            currency: 'EUR',
            totalGross: 238.00,
            totalNet: 200.00,
            taxStatus: 'net',
            lineItems: [],
            comments: [],
        );
    }
}
```

If `Psr\Log\Test\TestLogger` is not in the vendor tree, use an anonymous `AbstractLogger` subclass that
pushes `$level` onto a public array, and assert `'warning'` is in it.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter AssistantAskStampTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Assistant\AssistantAskStamp" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Records whether a quote's price ask was typed by the buyer or proposed by
 * the assistant and agreed to.
 *
 * Both arrive at the policy engine as the same number, and without this the
 * decision log says "the buyer asked for 10%" about a figure a model wrote.
 * The admin can then show "assistant proposed, buyer confirmed" instead.
 *
 * Deliberately NOT read by Negotiation\CappedAuthority: an assistant-proposed
 * figure caps exactly like a typed one today. Narrowing the cap for
 * model-authored asks is a policy decision and belongs in its own spec — this
 * class only makes the distinction visible, so that decision can be taken on
 * evidence rather than on guesses.
 *
 * `$gateway` is nullable, defaulted and last, matching A2cnSessionStamp and
 * SellerActEmitter: QuoteGatewayFactory::create() returns null where
 * SwagCommercial's classes exist but the licence does not.
 */
final readonly class AssistantAskStamp
{
    public const ASK_SOURCE_KEY = 'merchantQuoteAgentAssistantAsk';

    public function __construct(
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    public function stamp(QuoteSnapshot $snapshot, string $targetSource): void
    {
        if ($this->gateway === null) {
            return;
        }

        try {
            $this->gateway->updateQuote($snapshot->id, new QuoteUpdate(customFields: [
                self::ASK_SOURCE_KEY => $targetSource,
            ]));
        } catch (\Throwable $error) {
            // Fail-open: the quote is already open and the buyer is waiting on
            // it. A missing provenance note is a warning; a lost quote is not.
            $this->logger->warning('Could not record who authored an assistant quote ask.', [
                'quoteId' => $snapshot->id,
                'exception' => $error,
            ]);
        }
    }
}
```

Then in `RequestQuoteTool`, take `private readonly AssistantAskStamp $askStamp` as a third constructor
argument and call it after the gateway returns, before building the result:

```php
        if ([] !== $targets) {
            $this->askStamp->stamp($snapshot, $targetSource);
        }
```

`RequestQuoteToolFactory` takes `AssistantAskStamp` in its constructor and passes it through; add
`service(AssistantAskStamp::class)` to the factory's `args()` in `services.php`, and register the stamp
itself beside it:

```php
        $services->set(AssistantAskStamp::class)->args([
            service('logger'),
            service(QuoteGatewayInterface::class)->nullOnInvalid(),
        ]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite unit --filter 'AssistantAskStamp|RequestQuoteTool'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/mago format && vendor/bin/mago lint && vendor/bin/mago analyze
git add src/Assistant src/Resources/config/services.php tests/Unit/Assistant
git commit -m "feat(assistant): stamp whether the buyer or the model wrote the ask"
```

---

### Task 6: The `quote_status` tool

**Files:**
- Create: `src/Assistant/QuoteStatusTool.php`
- Create: `src/Assistant/QuoteStatusToolFactory.php`
- Modify: `src/Resources/config/services.php`
- Test: `tests/Unit/Assistant/QuoteStatusToolTest.php`

**Interfaces:**
- Consumes: `BuyerQuoteGatewayInterface::listQuotes(SalesChannelContext $context, int $limit, int $page): QuoteList` — `QuoteList` has public `list<QuoteSnapshot> $quotes`, `int $total`, `int $limit`, `int $page`.
- Produces: `QuoteStatusTool::__invoke(?string $quoteNumber = null): array` returning `array{state: string, total: string, valid_until: string, note: string}`. The spec calls that second key `replied_total`; `total` is the name to use, because the quote may still be `open` and no reply exists yet.

A second factory rather than a branch in the first: upstream's `create()` returns one object, so one
factory contributes one tool. They also switch off independently — `quote_status` is a read, and stays
available to a buyer whose merchant has turned request-writing off.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Assistant;

use MerchantQuoteAgentPlugin\Assistant\QuoteStatusTool;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteList;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class QuoteStatusToolTest extends TestCase
{
    public function testWithoutANumberItReadsTheNewestQuote(): void
    {
        $result = $this->tool([self::snapshot('Q1002'), self::snapshot('Q1001')])->__invoke();

        self::assertSame('replied', $result['state']);
    }

    public function testItFindsAQuoteByNumber(): void
    {
        $result = $this->tool([self::snapshot('Q1002'), self::snapshot('Q1001')])->__invoke('Q1001');

        self::assertSame('replied', $result['state']);
    }

    /**
     * A model will invent a quote number sooner or later. `not_found` with a
     * note beats an exception surfacing to the shopper as a broken chat.
     */
    public function testAnUnknownNumberIsAStateRatherThanAnError(): void
    {
        $result = $this->tool([self::snapshot('Q1002')])->__invoke('Q9999');

        self::assertSame('not_found', $result['state']);
    }

    /**
     * Every money value leaves as a formatted string. A float in this array is
     * a number the model may re-round, and a re-rounded price shown to a
     * shopper is a wrong price.
     */
    public function testEveryValueIsAString(): void
    {
        foreach ($this->tool([self::snapshot('Q1002')])->__invoke() as $value) {
            self::assertIsString($value);
        }
    }

    /** @param list<QuoteSnapshot> $quotes */
    private function tool(array $quotes): QuoteStatusTool
    {
        $gateway = $this->createMock(BuyerQuoteGatewayInterface::class);
        $gateway->method('isAvailable')->willReturn(true);
        $gateway->method('listQuotes')->willReturn(new QuoteList($quotes, \count($quotes), 25, 1));

        return new QuoteStatusTool($gateway, $this->createMock(SalesChannelContext::class));
    }

    private static function snapshot(string $number): QuoteSnapshot
    {
        return new QuoteSnapshot(
            id: 'quote-' . $number,
            quoteNumber: $number,
            state: 'replied',
            expirationDate: '2026-10-01T00:00:00+00:00',
            currency: 'EUR',
            totalGross: 238.00,
            totalNet: 200.00,
            taxStatus: 'net',
            lineItems: [],
            comments: [],
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter QuoteStatusToolTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Assistant\QuoteStatusTool" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * What happened to a quote the shopper asked for.
 *
 * Reads only the buyer's own quotes — the gateway scopes every listing to the
 * authenticated customer, so a quote number belonging to someone else is
 * simply not in the page and comes back `not_found`, which is also the honest
 * answer for a number a model invented.
 */
#[AsTool(
    name: 'quote_status',
    description: 'Check what the shop replied to a quote the shopper requested. '
    . 'Use when they ask about a quote they already asked for.',
)]
final class QuoteStatusTool
{
    private const PAGE_SIZE = 25;

    /** Figures are formatted here and repeated verbatim; a re-rounded price shown to a shopper is a wrong price. */
    private const NOTE =
        'State the quote\'s status and, if it has one, its total and validity date exactly as given '
            . 'here. Do not recalculate, round or convert any figure, and do not state a discount '
            . 'percentage.';

    private const NOTE_NOT_FOUND =
        'No quote with that number belongs to this shopper. Say so plainly and offer to look again '
            . 'or to request a new quote. Do not guess a number.';

    public function __construct(
        private readonly BuyerQuoteGatewayInterface $gateway,
        private readonly SalesChannelContext $context,
    ) {}

    /**
     * @param string|null $quoteNumber The quote number to look up; omit for the shopper's most recent quote.
     *
     * @return array{state: string, total: string, valid_until: string, note: string}
     */
    public function __invoke(?string $quoteNumber = null): array
    {
        $quotes = $this->gateway->listQuotes($this->context, self::PAGE_SIZE, 1)->quotes;

        $quote = null;
        foreach ($quotes as $candidate) {
            if (null === $quoteNumber || $candidate->quoteNumber === $quoteNumber) {
                $quote = $candidate;
                break;
            }
        }

        if (!$quote instanceof QuoteSnapshot) {
            return ['state' => 'not_found', 'total' => '', 'valid_until' => '', 'note' => self::NOTE_NOT_FOUND];
        }

        return [
            'state' => $quote->state ?? 'unknown',
            'total' => $this->money($quote),
            'valid_until' => $quote->expirationDate ?? '',
            'note' => self::NOTE,
        ];
    }

    /**
     * `taxStatus` says which of the two totals is the customer-facing
     * authoritative amount — that is part of the published snapshot contract,
     * and picking the wrong one here would show a net figure to a gross-priced
     * shopper.
     */
    private function money(QuoteSnapshot $quote): string
    {
        $amount = 'gross' === $quote->taxStatus ? $quote->totalGross : $quote->totalNet;

        if (null === $amount) {
            return '';
        }

        return \sprintf('%s %s', number_format($amount, 2, '.', ''), $quote->currency ?? '');
    }
}
```

`QuoteStatusToolFactory` is `RequestQuoteToolFactory` with the `assistantQuoteRequests` check removed
and `QuoteStatusTool` constructed instead — the request-stack, gateway and customer checks are
identical. Register it in `services.php` beside the other factory, behind the same two gates:

```php
        $services->set(QuoteStatusToolFactory::class)
            ->args([service('request_stack'), service(BuyerQuoteGatewayInterface::class)->nullOnInvalid()])
            ->autoconfigure(false)
            ->tag('swag_assistant.tool_factory');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite unit --filter 'QuoteStatusTool|RequestQuoteTool|AssistantAvailability|AssistantAskStamp'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/mago format && vendor/bin/mago lint && vendor/bin/mago analyze
git add src/Assistant src/Resources/config/services.php tests/Unit/Assistant
git commit -m "feat(assistant): let the shopper ask what happened to a quote"
```

---

### Task 7: Prove the stubs still match upstream, and document it

**Files:**
- Create: `tests/Integration/ToolWiringTest.php`
- Modify: `README.md`, `docs/end-to-end.md`
- Test: itself

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Write the failing test**

Model on `GatewayWiringTest`, with a docblock saying plainly that this is the only thing standing between the stubs and an upstream rename. Assert, against a shop with both plugins installed:

1. `kernel.bundles` contains `SwagAssistantStarterKit` — the literal in `AssistantAvailability`.
2. The real `Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface` exists in the shop, and `RequestQuoteToolFactory` is an instance of it.
3. `ToolContext`'s constructor takes exactly `trace` and `config`, by reflection — if upstream widens it, our stub is stale and we want to know before a shop does.
4. `RequestQuoteToolFactory` is registered under the `swag_assistant.tool_factory` tag, read from the container's tag list rather than asserted from our own source.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.integration.xml.dist --filter ToolWiringTest`
Expected: FAIL — the test shop does not have the starter kit installed yet. Install it there first (`composer require` from a path or VCS repo **in the shop, not in this repo**, then `bin/console plugin:install --activate SwagAssistantStarterKit`), then re-run and expect FAIL only on the assertions, not on setup.

- [ ] **Step 3: Write the docs**

`README.md` — a paragraph directly after the "Agentic Commerce is optional" one, in the same voice, saying the starter kit is optional in the same way and what switches on with it. Link the config field by name.

`docs/end-to-end.md` — a section covering: the two tools, the merchant toggle and its default, why the tool returns no prices, what the provenance stamp records and what reads it, and an explicit statement that this path has no A2CN evidence trail because there is no counterparty agent.

- [ ] **Step 4: Run the whole suite**

Run: `vendor/bin/phpunit --testsuite unit && vendor/bin/phpunit -c phpunit.integration.xml.dist`
Expected: PASS, including `PluginConfigTest` — which reads install-time defaults against a configured shop and will notice the new field.

- [ ] **Step 5: Commit**

```bash
vendor/bin/mago format && vendor/bin/mago lint && vendor/bin/mago analyze
git add tests/Integration/ToolWiringTest.php README.md docs/end-to-end.md
git commit -m "test(assistant): pin the starter-kit contract the stubs only assume"
```

---

## Notes for the executor

- **Task 2 is the only edit to code UCP already depends on.** `BuyerQuoteFlowTest` and `LegacyBuyerFlowTest` are the regression guard and must be run there, not just at the end.
- **Do not add the starter kit to `composer.json`'s `require` or `require-dev`.** That was decided deliberately; the stubs plus Task 7 are the agreed trade.
- **Do not touch `Negotiation\CappedAuthority`.** An assistant-proposed figure caps like a typed one until a separate spec says otherwise.
- If Task 4's `#[AsTool]` attribute class turns out not to be in this repo's vendor tree, stub it under `tests/Stub` the same way as the starter-kit types and say so in the commit message.
