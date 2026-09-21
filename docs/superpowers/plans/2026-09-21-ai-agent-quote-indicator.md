# AI Agent Quote Indicator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tell the buyer, in the storefront, when an AI agent handled their quote — with a banner on the quote detail page and a per-message label in the conversation.

**Architecture:** Nothing new is persisted per comment. The per-message label is *derived* in the storefront from a signal that already exists — an agent comment carries no `customerId`, no `employeeId` and no `createdById`, because the agent writes under a `SystemSource`. The banner is driven by one new `customFields` key on the quote, spread into the single end-of-pass stamp that `ServiceQuoteHandler` already writes. No schema change, no migration, no new route.

**Tech Stack:** PHP 8.3, Shopware 6.7 (DAL + Twig storefront), SwagCommercial B2B QuoteManagement, TypeScript (Shopware storefront `PluginManager`), PHPUnit, `node --experimental-strip-types` assert self-checks.

**Spec:** `docs/superpowers/specs/2026-09-21-ai-agent-quote-indicator-design.md` — read it before Task 1. The plan argues from it; where they disagree, the spec wins and the plan is wrong.

## Global Constraints

- `declare(strict_types=1)` in every PHP file. Target PHP 8.3.
- Mago analyze runs at full strictness over `src`. No `mixed`, no unsafe casts, no bypasses.
- Gate thresholds: cyclomatic complexity 10, nesting depth 4, parameters 5, ~400 lines/file.
- `src/Negotiation` must not import Shopware beyond `IllegalTransitionException`; `src/Policy` imports no Shopware at all. Everything touching SwagCommercial goes through `src/Bridge`. `src/Servicing` may import Shopware.
- No `echo`/`var_dump`/`print_r`/`dd` in application code.
- The marker key is exactly `merchant_quote_agent_handled`. The snippet root is exactly `merchantQuoteAgent.disclosure`.
- Buyer-facing copy carries no reason values, no field names, no policy detail, no model internals — only the fact that an agent is involved. This constraint predates the plan and survives it.
- Disclosure is **always on**. Do not add a system config key, a `BuyerNotificationPreference`-style toggle, or any sales-channel opt-out.
- `composer run format:check && composer run lint` must pass before every commit (the pre-commit hook runs Mago on staged files and will block you otherwise).
- There is no JS test runner in this project. JavaScript logic is verified by assert-based `.check.mjs` files run through `node --experimental-strip-types`, matching `src/Resources/app/administration/src/module/merchant-quote-agent/*.check.mjs`.
- Integration tests (`tests/Integration`) only run inside the shop container and are never in CI, because SwagCommercial is licensed. Unit tests must carry every assertion that can live in a unit test.

---

### Task 1: The `merchant_quote_agent_handled` marker

Adds the quote-level signal the banner reads. Pure PHP, fully unit-tested, no storefront involvement.

**Files:**
- Create: `src/Servicing/AgentDisclosure.php`
- Modify: `src/Servicing/ServiceQuoteHandler.php` (the end-of-pass stamp, currently at line 197)
- Modify: `src/Servicing/QuoteEscalator.php` (class docblock, lines 24–28)
- Create: `tests/Unit/Servicing/AgentDisclosureTest.php`
- Modify: `tests/Unit/Servicing/ServiceQuoteHandlerTest.php`

**Interfaces:**
- Consumes: `MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot` (has `->lifecycle->customFields`, an `array<string, mixed>`); `tests/Unit/Servicing/ServicingHandlerFixture.php` (static helpers `snapshot(array $customFields = [], string $state = 'open')`, `handler()`, `message()`, `countingPipeline(NegotiationOutcome $outcome = NegotiationOutcome::Offered)`, `lastCustomFieldWrite(FakeQuoteGateway $gateway): array`); `tests/Unit/Servicing/FakeQuoteGateway.php` (public `array $customFieldWrites`, `array $quoteUpdates`).
- Produces: `MerchantQuoteAgentPlugin\Servicing\AgentDisclosure::MARKER_KEY` (string `'merchant_quote_agent_handled'`), `AgentDisclosure::stampFor(NegotiationOutcome $outcome): array<string, true>` (empty array when the agent did not act), `AgentDisclosure::handled(QuoteSnapshot $snapshot): bool`. Task 2 uses `MARKER_KEY` verbatim as a Twig string literal.
- `NegotiationOutcome` (`src/Negotiation/NegotiationOutcome.php`) is a backed enum with cases `Offered`, `Countered`, `Escalated`, `NothingToDo`, `Clarified`, `HandedOver`, and a method `answeredTheBuyer(): bool` that is true only for `Offered` and `Countered`.

**Which outcomes disclose — read before Step 1.** `Offered`, `Countered`, `Clarified` and `Escalated` stamp. `HandedOver` and `NothingToDo` do not.

`HandedOver` is defined in the enum's own docblock as a pass that found a human merchant already on the quote and wrote nothing. Stamping it would tell the buyer an AI agent handled a quote a human handled — a false statement to the buyer, and worse than under-disclosing. `NothingToDo` is the same shape with nothing happening.

The gate is wider than `answeredTheBuyer()` on purpose. `Clarified` put an agent-written question in front of the buyer. `Escalated` is included even where the buyer notice is switched off and the buyer sees no agent message at all, because the agent still made a determination about their quote.

- [ ] **Step 1: Write the failing test for the marker itself**

Create `tests/Unit/Servicing/AgentDisclosureTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The marker is what the storefront banner reads. Its key is a published
 * contract with a Twig template that cannot import the constant, so the
 * literal is pinned here.
 */
final class AgentDisclosureTest extends TestCase
{
    public function testTheKeyIsTheOneTheStorefrontTemplateReads(): void
    {
        // The Twig banner reads this literal. Changing the constant without
        // changing the template is a silent no-op on the buyer's page.
        self::assertSame('merchant_quote_agent_handled', AgentDisclosure::MARKER_KEY);
    }

    /**
     * Every case listed explicitly rather than derived, so a case added to the
     * enum later fails this test instead of silently inheriting a default.
     * Which side it belongs on is a decision about what the buyer is told.
     *
     * @return iterable<string, array{NegotiationOutcome, bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'offered - the agent made an offer' => [NegotiationOutcome::Offered, true];
        yield 'countered - the agent countered' => [NegotiationOutcome::Countered, true];
        yield 'clarified - the agent asked the buyer a question' => [NegotiationOutcome::Clarified, true];
        // Included even where the buyer notice is off and the buyer sees no
        // agent message: the agent still made a determination on their quote.
        yield 'escalated - the agent decided to hand off' => [NegotiationOutcome::Escalated, true];
        // A human was already on the quote and the agent wrote nothing.
        // Disclosing here would tell the buyer an AI handled what a person did.
        yield 'handed over - a human was already handling it' => [NegotiationOutcome::HandedOver, false];
        yield 'nothing to do - the agent did not act' => [NegotiationOutcome::NothingToDo, false];
    }

    #[DataProvider('outcomes')]
    public function testOnlyAnOutcomeWhereTheAgentActedDisclosesIt(
        NegotiationOutcome $outcome,
        bool $expected,
    ): void {
        $expectedFragment = $expected ? [AgentDisclosure::MARKER_KEY => true] : [];

        self::assertSame($expectedFragment, AgentDisclosure::stampFor($outcome));
    }

    public function testEveryEnumCaseIsCovered(): void
    {
        // The provider is hand-written so a new case fails rather than
        // defaulting. This is what makes that failure happen.
        $covered = array_map(
            static fn (array $case): NegotiationOutcome => $case[0],
            iterator_to_array(self::outcomes(), false),
        );

        self::assertEqualsCanonicalizing(NegotiationOutcome::cases(), $covered);
    }

    public function testAQuoteWithoutTheMarkerIsNotHandled(): void
    {
        self::assertFalse(AgentDisclosure::handled(ServicingHandlerFixture::snapshot()));
    }

    public function testAQuoteCarryingTheMarkerIsHandled(): void
    {
        $snapshot = ServicingHandlerFixture::snapshot([AgentDisclosure::MARKER_KEY => true]);

        self::assertTrue(AgentDisclosure::handled($snapshot));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter AgentDisclosureTest`
Expected: FAIL — `Class "MerchantQuoteAgentPlugin\Servicing\AgentDisclosure" not found`.

- [ ] **Step 3: Write `AgentDisclosure`**

Create `src/Servicing/AgentDisclosure.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;

/**
 * Whether an AI agent acted on this quote, so the storefront can tell the buyer.
 *
 * Unlike the two markers the servicing loop already keeps on customFields,
 * this one is set once and NEVER cleared. Those two exist to be released — a
 * quote may escalate afresh, and a genuinely new ambiguity may be asked about
 * again. This one records something that happened, and a later human reply
 * does not make it untrue. That opposite lifecycle is why it is its own key
 * rather than a reuse of QuoteEscalator's.
 *
 * Gated on the outcome, and the gate is neither "a pass completed" nor
 * answeredTheBuyer().
 *
 * Not "a pass completed", because HandedOver is a pass that found a human
 * merchant already on the quote and wrote nothing. Disclosing there would tell
 * the buyer an AI handled a quote a person handled, and a false statement to
 * the buyer is worse than a missing one. NothingToDo is the same shape with
 * nothing happening at all.
 *
 * Not answeredTheBuyer() either, which covers only Offered and Countered.
 * Clarified put an agent-written question in front of the buyer. Escalated is
 * included even on a sales channel where the buyer notice is switched off and
 * the buyer sees no agent message: the agent still made a determination about
 * their quote, and that determination is what is being disclosed.
 *
 * So: did the agent ACT on this quote.
 *
 * QuoteWriter shallow-merges customFields, so this cannot disturb the A2CN act
 * chain or the markers already there.
 */
final class AgentDisclosure
{
    /**
     * Read by Resources/views/storefront/page/account/quote-detail/index.html.twig
     * as a string literal — Twig cannot import the constant. AgentDisclosureTest
     * pins the value so the two cannot drift apart silently.
     */
    public const MARKER_KEY = 'merchant_quote_agent_handled';

    /**
     * The fragment that discloses agent handling, to be spread into a servicing
     * pass's stamp.
     *
     * @return array<string, true> empty when the agent did not act on the quote
     */
    public static function stampFor(NegotiationOutcome $outcome): array
    {
        return match ($outcome) {
            NegotiationOutcome::Offered,
            NegotiationOutcome::Countered,
            NegotiationOutcome::Clarified,
            NegotiationOutcome::Escalated => [self::MARKER_KEY => true],
            NegotiationOutcome::HandedOver,
            NegotiationOutcome::NothingToDo => [],
        };
    }

    public static function handled(QuoteSnapshot $snapshot): bool
    {
        return ($snapshot->lifecycle->customFields[self::MARKER_KEY] ?? null) === true;
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `vendor/bin/phpunit --filter AgentDisclosureTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Write the failing test for the handler stamping it**

Append to `tests/Unit/Servicing/ServiceQuoteHandlerTest.php`, inside the class. Add `use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;` and `use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;` to the imports if they are not already there:

```php
    /**
     * @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions
     *
     * The escalation case matters most here: it writes no buyer comment at all
     * on a sales channel with the notice switched off, and the buyer would
     * otherwise learn nothing about the agent's determination.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('actingOutcomes')]
    public function testAPassWhereTheAgentActedDisclosesIt(NegotiationOutcome $outcome): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::countingPipeline($outcome);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertTrue($stamp[AgentDisclosure::MARKER_KEY] ?? false);
    }

    /** @return iterable<string, array{NegotiationOutcome}> */
    public static function actingOutcomes(): iterable
    {
        yield 'offered' => [NegotiationOutcome::Offered];
        yield 'countered' => [NegotiationOutcome::Countered];
        yield 'clarified' => [NegotiationOutcome::Clarified];
        yield 'escalated' => [NegotiationOutcome::Escalated];
    }

    /**
     * @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions
     *
     * HandedOver means a human merchant was already on the quote and the agent
     * wrote nothing. Disclosing there would tell the buyer an AI handled what a
     * person handled.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonActingOutcomes')]
    public function testAPassWhereTheAgentDidNotActDisclosesNothing(NegotiationOutcome $outcome): void
    {
        $gateway = new FakeQuoteGateway([ServicingHandlerFixture::snapshot()]);
        $pipeline = ServicingHandlerFixture::countingPipeline($outcome);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayNotHasKey(AgentDisclosure::MARKER_KEY, $stamp);
    }

    /** @return iterable<string, array{NegotiationOutcome}> */
    public static function nonActingOutcomes(): iterable
    {
        yield 'handed over' => [NegotiationOutcome::HandedOver];
        yield 'nothing to do' => [NegotiationOutcome::NothingToDo];
    }

    /** @throws \Throwable the handler's own declared surface, per #18's unknown pipeline exceptions */
    public function testTheDisclosureMarkerIsNeverCleared(): void
    {
        // The two neighbouring markers are released by a pass that answered.
        // This one must survive exactly that pass, or a quote stops disclosing
        // the moment the agent succeeds on it.
        $gateway = new FakeQuoteGateway([
            ServicingHandlerFixture::snapshot([AgentDisclosure::MARKER_KEY => true]),
        ]);
        $pipeline = ServicingHandlerFixture::countingPipeline(NegotiationOutcome::Offered);

        ServicingHandlerFixture::handler($gateway, $pipeline)(ServicingHandlerFixture::message());

        $stamp = ServicingHandlerFixture::lastCustomFieldWrite($gateway);
        self::assertArrayNotHasKey(
            AgentDisclosure::MARKER_KEY,
            array_filter($stamp, static fn (mixed $value): bool => $value === null),
        );
    }
```

- [ ] **Step 6: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter ServiceQuoteHandlerTest`
Expected: FAIL on all four cases of `testAPassWhereTheAgentActedDisclosesIt` — `assertTrue(false)`, because nothing writes the key yet. `testAPassWhereTheAgentDidNotActDisclosesNothing` and `testTheDisclosureMarkerIsNeverCleared` will already pass; that is fine, they are guards against a regression, not drivers.

- [ ] **Step 7: Spread the stamp into the end-of-pass write**

In `src/Servicing/ServiceQuoteHandler.php`, the `updateQuote` call at line 197. Add `AgentDisclosure::stampFor($outcome)` as a third spread, after the two release fragments:

```php
        $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
            ServicingFingerprint::MARKER_KEY => ServicingFingerprint::stamp(
                $snapshot,
                $after->lifecycle->stateTechnicalName,
            ),
            self::ATTEMPTS_KEY => null,
            ...QuoteEscalator::releaseFor($outcome),
            ...ClarificationMarker::releaseFor($outcome),
            ...AgentDisclosure::stampFor($outcome),
        ]));
```

Extend the comment block directly above that call with a third paragraph:

```php
        // The disclosure marker is the one here that is never released: the two
        // above record a state the quote can leave, this one records that an
        // agent acted on it, which stays true. It has its own outcome gate
        // (not every completed pass is an agent acting - see AgentDisclosure),
        // and it is spread last so a future release fragment cannot null it by
        // accident.
```

`AgentDisclosure` is in the same namespace as `ServiceQuoteHandler`, so no import is needed.

- [ ] **Step 8: Run the tests and watch them pass**

Run: `vendor/bin/phpunit --filter 'ServiceQuoteHandlerTest|AgentDisclosureTest'`
Expected: PASS.

Then run the whole unit suite to prove nothing that asserts on the stamp's exact shape broke:

Run: `composer run test`
Expected: PASS. If a test fails because it asserted the stamp array equals an exact literal, update that assertion to include the new key — do not weaken it to a subset check.

- [ ] **Step 9: Amend the `QuoteEscalator` docblock**

The spec records this as a deliberate reversal, and the docblock currently contradicts what now ships. In `src/Servicing/QuoteEscalator.php`, replace the sentence fragment at lines 24–28 that reads `no mention of an agent` so the paragraph becomes:

```php
 * SO EVERYTHING WRITTEN HERE IS CUSTOMER-FACING COPY. No reason value, no
 * problem list, no field names — and no constraint or mapping message, several
 * of which echo the offending value rather than just the field. Internal
 * detail belongs in the log.
 *
 * The FACT that an agent is involved is no longer internal: since the buyer
 * disclosure (see AgentDisclosure and the storefront banner) the buyer is told
 * an AI agent handled this quote, always and on every sales channel. That is a
 * deliberate narrowing of the rule above, not an exception to it — the agent's
 * reasons, policy, violations and model internals stay internal exactly as
 * before. Do not widen it further by putting WHY into this copy.
```

- [ ] **Step 10: Verify and commit**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all PASS.

```bash
git add src/Servicing/AgentDisclosure.php src/Servicing/ServiceQuoteHandler.php src/Servicing/QuoteEscalator.php tests/Unit/Servicing/AgentDisclosureTest.php tests/Unit/Servicing/ServiceQuoteHandlerTest.php
git commit -m "feat(servicing): record that an agent acted on the quote

Never cleared, unlike the two markers beside it: this one records
something that happened rather than a state the quote can leave.

Gated on the outcome. HandedOver means a human was already on the quote
and the agent wrote nothing, so disclosing there would tell the buyer an
AI handled what a person handled.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: The storefront banner, on both lanes

Renders the disclosure on the quote detail page. Plain Twig, so it works on the released 6.7.x lane as well as 7.13.

**Files:**
- Modify: `src/MerchantQuoteAgentPlugin.php` (add `getTemplatePriority()`)
- Create: `src/Resources/views/storefront/page/account/quote-detail/index.html.twig`
- Modify: `src/Resources/snippet/en_GB/messages.en-GB.json`
- Create: `tests/Unit/MerchantQuoteAgentPluginTemplatePriorityTest.php`
- Create: `tests/Integration/StorefrontDisclosureBannerTest.php`

**Interfaces:**
- Consumes: `AgentDisclosure::MARKER_KEY` from Task 1, as the Twig string literal `merchant_quote_agent_handled`.
- Produces: the snippet keys `merchantQuoteAgent.disclosure.banner.headline` and `merchantQuoteAgent.disclosure.banner.body`. Task 3 adds a sibling key under the same `merchantQuoteAgent.disclosure` root.

**Why the priority override exists — read before Step 1.** `BundleHierarchyBuilder::buildNamespaceHierarchy()` sorts bundles by `getTemplatePriority()`, where a *lower* integer means *higher* precedence. `Bundle::getTemplatePriority()` returns `0`, and neither SwagCommercial nor this plugin overrides it. PHP's `asort` is stable, so the tie falls through to bundle registration order, which `DbalKernelPluginLoader` derives from `ORDER BY installed_at`. Whether this plugin's template wins therefore depends on the order a given shop happened to install the two plugins in. It would work on the test shop and fail silently on a merchant's.

- [ ] **Step 1: Write the failing test for template precedence**

Create `tests/Unit/MerchantQuoteAgentPluginTemplatePriorityTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use PHPUnit\Framework\TestCase;

/**
 * The storefront banner extends a SwagCommercial template, which only works
 * while this bundle sorts AHEAD of SwagCommercial in the Twig namespace
 * hierarchy.
 *
 * BundleHierarchyBuilder sorts on getTemplatePriority(), lower first, with a
 * stable asort. Both plugins default to 0, so the tie falls through to bundle
 * registration order, which DbalKernelPluginLoader takes from
 * `ORDER BY installed_at`. At the default this feature works or not depending
 * on which plugin a shop installed first — green here, silently dead there.
 * A negative priority is what removes the shop's install history from the
 * answer.
 */
final class MerchantQuoteAgentPluginTemplatePriorityTest extends TestCase
{
    public function testThisPluginOutranksPluginsThatTookTheDefaultPriority(): void
    {
        $plugin = new MerchantQuoteAgentPlugin(true, __DIR__);

        self::assertLessThan(0, $plugin->getTemplatePriority());
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit --filter MerchantQuoteAgentPluginTemplatePriorityTest`
Expected: FAIL — `Failed asserting that 0 is less than 0`.

- [ ] **Step 3: Override the priority**

In `src/MerchantQuoteAgentPlugin.php`, add this method to the class. Place it next to the other `Plugin` lifecycle overrides and keep the existing `Override` import in use:

```php
    /**
     * Wins the Twig namespace hierarchy against SwagCommercial, whose
     * quote detail page this plugin's storefront banner extends.
     *
     * Lower is higher precedence. Both plugins would otherwise sit at the
     * default 0, and BundleHierarchyBuilder's stable sort would break that tie
     * on bundle registration order — which DbalKernelPluginLoader takes from
     * `ORDER BY installed_at`. That makes the banner's visibility depend on
     * which plugin the merchant happened to install first. -1 is the smallest
     * value that removes the shop's install history from the answer while
     * still leaving room for a theme or a later extension to outrank us.
     */
    #[Override]
    public function getTemplatePriority(): int
    {
        return -1;
    }
```

- [ ] **Step 4: Run it and watch it pass**

Run: `vendor/bin/phpunit --filter MerchantQuoteAgentPluginTemplatePriorityTest`
Expected: PASS.

- [ ] **Step 5: Add the snippet copy**

In `src/Resources/snippet/en_GB/messages.en-GB.json`, add a `disclosure` sibling to the existing `consent` object, inside `merchantQuoteAgent`:

```json
        "disclosure": {
            "banner": {
                "headline": "Handled by an AI agent",
                "body": "An automated assistant has been working on this quote on our behalf. You can reply here at any time, and a member of our team can take over."
            }
        }
```

The copy states the fact and nothing else — no reason, no policy, no limits. See the Global Constraints.

It deliberately does not say the agent *replied*. `Escalated` stamps the marker, and on a sales channel with the buyer notice switched off that quote carries no agent message at all; promising a reply the buyer cannot find in the thread would be its own small lie.

- [ ] **Step 6: Write the banner template**

Create `src/Resources/views/storefront/page/account/quote-detail/index.html.twig`:

```twig
{% sw_extends '@QuoteManagement/storefront/page/account/quote-detail/index.html.twig' %}

{# The buyer disclosure. Appended to SwagCommercial's own banner block rather
   than given a block of its own, so it sits with the other page-level notices
   and inherits their spacing.

   The key is AgentDisclosure::MARKER_KEY, which Twig cannot import;
   AgentDisclosureTest pins the literal on the PHP side so the two cannot
   drift apart silently.

   Deliberately not gated on quoteState: an agent that handled a quote handled
   it, and the disclosure outlives the state the quote was in at the time. #}
{% block page_account_quote_details_banner %}
    {{ parent() }}

    {% if page.quote.customFields.merchant_quote_agent_handled is defined and page.quote.customFields.merchant_quote_agent_handled %}
        {% sw_include '@Storefront/storefront/utilities/alert.html.twig' with {
            type: "info",
            heading: "merchantQuoteAgent.disclosure.banner.headline"|trans|sw_sanitize,
            content: "merchantQuoteAgent.disclosure.banner.body"|trans|sw_sanitize,
            class: " my-3"
        } %}
    {% endif %}
{% endblock %}
```

- [ ] **Step 7: Write the integration test**

Create `tests/Integration/StorefrontDisclosureBannerTest.php`. Follow the surrounding files' conventions: extend `IntegrationTestCase` and reach services through `static::getContainer()`.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;
use Shopware\Core\Framework\Adapter\Twig\NamespaceHierarchy\TemplateNamespaceHierarchyBuilder;

/**
 * The banner is the only disclosure surface that reaches the released
 * SwagCommercial lane, and its whole delivery depends on this plugin sorting
 * ahead of SwagCommercial in the Twig namespace hierarchy. The unit test
 * pins the priority VALUE; only this one proves the value actually wins
 * against the SwagCommercial the shop has installed.
 */
final class StorefrontDisclosureBannerTest extends IntegrationTestCase
{
    public function testThisPluginResolvesAheadOfSwagCommercialInTheTemplateHierarchy(): void
    {
        $hierarchy = static::getContainer()
            ->get(TemplateNamespaceHierarchyBuilder::class)
            ->buildNamespaceHierarchy([]);

        $namespaces = array_keys($hierarchy);
        $ours = array_search('MerchantQuoteAgentPlugin', $namespaces, true);
        $theirs = array_search('QuoteManagement', $namespaces, true);

        self::assertIsInt($ours, 'This plugin registers no storefront templates at all.');
        self::assertIsInt($theirs, 'SwagCommercial QuoteManagement is not loaded in this shop.');
        self::assertLessThan(
            $theirs,
            $ours,
            'SwagCommercial resolves first, so its quote detail page wins and the banner never renders.',
        );
    }

    public function testTheMarkerKeyMatchesTheLiteralInTheTemplate(): void
    {
        // The template cannot import the constant, so this asserts the two
        // halves of that contract against each other rather than trusting a
        // comment.
        $template = file_get_contents(
            \dirname(__DIR__, 2) . '/src/Resources/views/storefront/page/account/quote-detail/index.html.twig',
        );

        self::assertIsString($template);
        self::assertStringContainsString(
            'page.quote.customFields.' . AgentDisclosure::MARKER_KEY,
            $template,
        );
    }
}
```

- [ ] **Step 8: Run the tests**

Run: `composer run test`
Expected: PASS — this covers the unit test. The integration test needs the shop container.

Run: `composer run test:integration -- --filter StorefrontDisclosureBannerTest`
Expected: PASS. If the shop container is not available in this environment, say so explicitly in the handoff rather than marking the step done — this is the one assertion that catches the install-order trap, and an unrun test does not catch it.

The banner's actual *render* is checked by eye in Task 4, Step 5, not here. A full storefront page render needs a logged-in buyer and a quote fixture; the two assertions above cover the parts that fail silently (precedence, and the key literal matching the constant), and whether an alert box appears on a page does not fail silently.

- [ ] **Step 9: Verify and commit**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Expected: all PASS.

```bash
git add src/MerchantQuoteAgentPlugin.php src/Resources/views/storefront src/Resources/snippet tests/Unit/MerchantQuoteAgentPluginTemplatePriorityTest.php tests/Integration/StorefrontDisclosureBannerTest.php
git commit -m "feat(storefront): disclose agent handling on the quote detail page

Plain Twig, so it reaches the released SwagCommercial lane too. Forces
a negative template priority: at the shared default of 0 the namespace
hierarchy breaks the tie on installed_at, so the banner's visibility
would depend on which plugin the merchant installed first.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: The per-message disclosure logic, as pure functions

The two decisions the storefront override needs, extracted as pure functions so they can be checked without a browser, a DOM, or a Shopware runtime. Task 4 wires them into the actual plugin override.

**Files:**
- Create: `src/Resources/app/storefront/src/agent-disclosure.ts`
- Create: `src/Resources/app/storefront/src/agent-disclosure.check.mjs`
- Modify: `composer.json` (`scripts`)

**Interfaces:**
- Consumes: nothing from earlier tasks. The entry shape is SwagCommercial's `HistoryEntry = Record<string, unknown>`.
- Produces: `isAgentEntry(entry: Record<string, unknown>): boolean` and `AGENT_ACTOR_NAME: string`, both imported by Task 4's `main.ts`.

**Why two functions and not one — read before Step 1.** Overriding the actor lookup alone is not enough, and this is the subtle half of the feature.

SwagCommercial's `isMerchantCommentOnlyEntry()` is `isCommentOnlyEntry() && !isCustomerOrEmployeeHistory()`. An agent comment satisfies both, because "no author at all" is not "customer or employee". `mergeMerchantCommentHistories()` then merges it into any merchant entry whose `sentAt` is within `HISTORY_MERGE_WINDOW_MS` (15 seconds), and `mergeCommentIntoHistoryEntry()` produces `{...target, comment: <agent text>, createdById: target.createdById ?? ...}`. The merged entry carries the merchant's `createdById` and the agent's words. The null-author signal is destroyed *before* the actor is ever resolved.

Concretely: a merchant editing the quote in the administration within 15 seconds of an agent reply would see the agent's text attributed to a named human, with the disclosure gone — the precise failure this feature exists to prevent. So the merge is suppressed for agent entries, which costs a slightly more verbose timeline (an agent pass that both changes the quote and comments renders two articles instead of one) and buys a signal that cannot be silently lost.

- [ ] **Step 1: Write the failing self-check**

Create `src/Resources/app/storefront/src/agent-disclosure.check.mjs`:

```javascript
/**
 * Self-check for agent-disclosure.ts. No test runner: the project has no JS
 * toolchain, and this matches how the administration module is checked.
 *
 *     node --experimental-strip-types src/Resources/app/storefront/src/agent-disclosure.check.mjs
 *
 * The fixtures are the four author shapes a quote_comment can carry, as
 * measured against SwagCommercial's own source: the buyer (customerId, plus
 * employeeId for a B2B employee), the merchant (createdById alone), and the
 * agent (none of them).
 */

import assert from 'node:assert/strict';
import { AGENT_ACTOR_NAME, isAgentEntry } from './agent-disclosure.ts';

const buyer = { comment: 'Can you do better?', customerId: 'c1' };
const employee = { comment: 'Approving this', employeeId: 'e1' };
const merchant = { comment: 'Here is our best offer', createdById: 'u1' };
const agent = { comment: 'We can offer 12% off', sentAt: '2026-09-21T10:00:00Z' };

assert.equal(isAgentEntry(agent), true, 'an entry with no author of any kind is the agent');
assert.equal(isAgentEntry(buyer), false, 'a customerId is the buyer');
assert.equal(isAgentEntry(employee), false, 'an employeeId is a B2B employee, still the buyer side');
assert.equal(isAgentEntry(merchant), false, 'a createdById is the merchant');

// The association objects, not just the foreign keys: the history payload
// carries `customer` and `employee` expanded, and an entry can arrive with the
// association populated. Treating one of those as the agent would label a
// buyer's own message as AI-written.
assert.equal(
    isAgentEntry({ comment: 'hi', customer: { firstName: 'Ada' } }),
    false,
    'an expanded customer association is the buyer',
);
assert.equal(
    isAgentEntry({ comment: 'hi', employee: { firstName: 'Ada' } }),
    false,
    'an expanded employee association is the buyer side',
);

// An empty association object is what SwagCommercial's own asRecord() yields
// for an absent association, so it must not read as a present one.
assert.equal(
    isAgentEntry({ comment: 'hi', customer: {}, employee: {} }),
    true,
    'empty association objects mean absent, not present',
);

// The merge case. This is the regression that motivated suppressing the merge
// at all: a merchant detail change 5 seconds after an agent reply is inside
// SwagCommercial's 15s window, and merging would carry the merchant's
// createdById onto the agent's text.
const mergedByUpstream = { comment: 'We can offer 12% off', createdById: 'u1', sentAt: '2026-09-21T10:00:05Z' };
assert.equal(
    isAgentEntry(mergedByUpstream),
    false,
    'once merged the signal is gone - which is why the merge is suppressed upstream of this',
);

assert.equal(typeof AGENT_ACTOR_NAME, 'string');
assert.ok(AGENT_ACTOR_NAME.length > 0, 'the actor needs a name to render');

console.log('agent-disclosure.check.mjs: all assertions passed');
```

- [ ] **Step 2: Run it and watch it fail**

Run: `node --experimental-strip-types src/Resources/app/storefront/src/agent-disclosure.check.mjs`
Expected: FAIL — `Cannot find module` for `./agent-disclosure.ts`.

- [ ] **Step 3: Write the module**

Create `src/Resources/app/storefront/src/agent-disclosure.ts`:

```typescript
/**
 * Who wrote a quote history entry, for the buyer-facing AI disclosure.
 *
 * Pure and DOM-free on purpose: agent-disclosure.check.mjs runs it under
 * `node --experimental-strip-types` with no browser and no Shopware runtime,
 * which is the only kind of JS check this project has.
 */

/**
 * The name the buyer sees in place of "Merchant" on an agent-written message.
 * Not translated: the plugin's buyer-facing copy is English throughout (see
 * QuoteEscalator::BUYER_MESSAGE), and this matches that bar rather than
 * raising it for one string.
 */
export const AGENT_ACTOR_NAME = 'AI Agent';

const isPresent = (value: unknown): boolean => {
    if (typeof value === 'string') {
        return value.length > 0;
    }

    // SwagCommercial's asRecord() yields {} for an absent association, so an
    // empty object means absent, not present.
    if (value !== null && typeof value === 'object') {
        return Object.keys(value as Record<string, unknown>).length > 0;
    }

    return Boolean(value);
};

/**
 * True when no party of any kind authored this entry, which is the agent.
 *
 * Three parties write a quote_comment and SwagCommercial gives each a
 * different column: the buyer gets customerId (plus employeeId for a B2B
 * employee), the merchant gets createdById, and the agent - writing from a
 * message handler under a SystemSource - gets none of them. This mirrors
 * QuoteComment::isAuthored() on the PHP side.
 *
 * Negative on every author rather than positive on the agent, because there is
 * no positive agent signal to read. That means anything genuinely unattributed
 * reads as the agent, which is the safe direction for a disclosure: labelling
 * an unattributed machine message as AI is better than leaving an AI message
 * labelled as a person.
 */
export function isAgentEntry(entry: Record<string, unknown>): boolean {
    return !isPresent(entry.customerId)
        && !isPresent(entry.employeeId)
        && !isPresent(entry.createdById)
        && !isPresent(entry.customer)
        && !isPresent(entry.employee);
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `node --experimental-strip-types src/Resources/app/storefront/src/agent-disclosure.check.mjs`
Expected: `agent-disclosure.check.mjs: all assertions passed`.

- [ ] **Step 5: Wire it into the quality gate**

In `composer.json`, append the new check to the existing `quality:admin` script so it runs in CI with the others. The value becomes (one line, `&&`-joined, keeping the four existing checks first):

```
"quality:admin": "node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/decision.check.mjs && node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/measures.check.mjs && node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/strategy.check.mjs && node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/strategy-measures.check.mjs && node --experimental-strip-types src/Resources/app/storefront/src/agent-disclosure.check.mjs"
```

- [ ] **Step 6: Verify and commit**

Run: `composer run quality:admin`
Expected: all five checks pass.

```bash
git add src/Resources/app/storefront/src/agent-disclosure.ts src/Resources/app/storefront/src/agent-disclosure.check.mjs composer.json
git commit -m "feat(storefront): identify agent-written history entries

Pure and DOM-free so it runs under the same assert-based check the
administration module uses. Negative on every author column rather than
positive on the agent, because there is no positive agent signal to read.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Wire the override into SwagCommercial's storefront plugin

The only task that touches the Shopware storefront runtime. Modern lane (7.13+) only; on the released lane `B2bQuoteHistoryItemPlugin` does not exist and the override is inert.

**Files:**
- Create: `src/Resources/app/storefront/src/main.ts`
- Modify: `docs/superpowers/specs/2026-09-21-ai-agent-quote-indicator-design.md` (Status → Implemented)

**Interfaces:**
- Consumes: `isAgentEntry()` and `AGENT_ACTOR_NAME` from Task 3; `AgentDisclosure` from Task 1 only indirectly (no import).
- Produces: nothing later tasks consume. This is the last task.

**Before you start.** Shopware discovers a plugin's storefront entry point at the fixed path `src/Resources/app/storefront/src/main.ts`. Do not invent a webpack config: the storefront build picks this up by convention, the same way `src/Resources/app/administration/src/main.ts` is picked up today. Confirm by building the storefront in the test shop at Step 4 rather than by reasoning about it.

`B2bQuoteHistoryItemPlugin` is `@internal` to SwagCommercial and both overridden methods are private by convention. Record that coupling the way `src/Bridge/Commercial/SwagCommercialCommentWriter.php` records its own — an `@internal-dependency` docblock naming both methods.

- [ ] **Step 1: Write the override**

Create `src/Resources/app/storefront/src/main.ts`:

```typescript
/**
 * Tells the buyer which quote messages an AI agent wrote.
 *
 * @internal-dependency
 * Shopware\Commercial B2bQuoteHistoryItemPlugin::getActor()
 * Shopware\Commercial B2bQuoteHistoryItemPlugin::isMerchantCommentOnlyEntry()
 * - both private by convention in an @internal storefront plugin, so a
 * SwagCommercial update can rename or restructure either. Same class of
 * coupling the bridge accepts for QuoteCommenter; see
 * Bridge/Commercial/SwagCommercialCommentWriter.php.
 *
 * Modern lane only. On released SwagCommercial there is no such plugin to
 * override and PluginManager.override() is a no-op, which is why the Twig
 * banner - not this - is the disclosure that reaches every lane.
 */

import { AGENT_ACTOR_NAME, isAgentEntry } from './agent-disclosure.ts';

type HistoryEntry = Record<string, unknown>;

type HistoryActor = {
    name: string,
    initials: string,
    isCustomer: boolean,
};

const PluginManager = window.PluginManager;

PluginManager.override('B2bQuoteHistoryItemPlugin', {
    /**
     * Without this the buyer is told the MERCHANT wrote the agent's messages:
     * upstream resolves employee, then customer, then createdBy, and falls
     * through to the 'merchantComment' snippet - "Merchant" - when an entry
     * has none of the three, which is exactly an agent entry.
     */
    getActor(entry: HistoryEntry): HistoryActor {
        if (!isAgentEntry(entry)) {
            return this.$super('getActor', entry);
        }

        return {
            name: AGENT_ACTOR_NAME,
            initials: this.getInitials(AGENT_ACTOR_NAME),
            isCustomer: false,
        };
    },

    /**
     * Stops an agent message being merged into a merchant one.
     *
     * Upstream's predicate is `isCommentOnlyEntry() && !isCustomerOrEmployeeHistory()`,
     * and an agent entry satisfies both - "no author at all" is not "customer
     * or employee". mergeMerchantCommentHistories() would then fold it into
     * any merchant entry within HISTORY_MERGE_WINDOW_MS (15s), and
     * mergeCommentIntoHistoryEntry() builds {...target, comment: <agent text>,
     * createdById: target.createdById ?? ...}. The merged entry carries the
     * merchant's createdById and the agent's words, so getActor() above never
     * sees the signal - it is destroyed before render.
     *
     * In practice: a merchant editing the quote in the administration within
     * 15s of an agent reply would see the agent's text under a named human,
     * with no disclosure at all.
     *
     * The cost is a slightly longer timeline - an agent pass that both changes
     * the quote and comments now renders two articles instead of one. Worth it
     * for a signal that cannot be silently lost.
     *
     * The other merge path, mergeAddedStatusCommentHistories(), is gated on
     * action === 'request', which an agent comment never is. It needs no
     * override.
     */
    isMerchantCommentOnlyEntry(entry: HistoryEntry): boolean {
        if (isAgentEntry(entry)) {
            return false;
        }

        return this.$super('isMerchantCommentOnlyEntry', entry);
    },
});
```

- [ ] **Step 2: Run the JS self-check**

Run: `composer run quality:admin`
Expected: PASS. This exercises the pure logic `main.ts` depends on. `main.ts` itself is wiring and is verified by Steps 3–5, not by an assert file.

- [ ] **Step 3: Run the shop-side type and lint check**

Run: `composer run quality:admin:shop`
Expected: PASS. This needs the test shop (it runs vue-tsc and ESLint through Shopware's extension toolchain). If the shop is unavailable, say so explicitly in the handoff instead of marking the step done.

- [ ] **Step 4: Build the storefront and confirm the entry point was picked up**

Sync the plugin to the shop with `scripts/sync-to-shop.sh` (it needs `SHOP_SSH` and `SHOP_PATH`; the administration bundle is built inside the container by `bin/build-administration.sh`, and the storefront has the matching `bin/build-storefront.sh`).

In the shop docroot:

```bash
bin/build-storefront.sh
```

Then confirm this plugin's storefront bundle was emitted, rather than assuming it:

```bash
ls public/bundles/merchantquoteagentplugin/
```

Expected: a `storefront/js/` directory containing a built bundle for this plugin. If nothing is emitted, the entry point path is wrong — fix the path to match the convention, do not add a webpack config to force it.

- [ ] **Step 5: Verify against a real quote**

On the test shop, open a quote the agent has answered, as the buyer, and confirm all three:

1. The banner renders on the quote detail page.
2. The agent's messages are attributed to "AI Agent", not "Merchant".
3. The merge case: make a merchant-side detail change in the administration within 15 seconds of an agent reply, reload the buyer's page, and confirm the agent's message still renders as its own article under "AI Agent".

Point 3 is the one that fails if `isMerchantCommentOnlyEntry` was skipped. Do not skip it — it is the whole reason that override exists.

- [ ] **Step 6: Flip the spec's status**

In `docs/superpowers/specs/2026-09-21-ai-agent-quote-indicator-design.md`, change `## Status` from `Proposed.` to `Implemented.` with the date.

- [ ] **Step 7: Verify and commit**

Run: `composer run quality`
Expected: all PASS.

```bash
git add src/Resources/app/storefront/src/main.ts docs/superpowers/specs/2026-09-21-ai-agent-quote-indicator-design.md
git commit -m "feat(storefront): label agent-written quote messages for the buyer

Overrides two methods, not one. getActor() alone is not enough: upstream
merges an unauthored comment into a nearby merchant entry within 15s and
destroys the signal before render, so a merchant editing the quote just
after an agent reply would see the agent's words under their own name.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## Follow-up, not in this plan

The spec records one item deliberately left out: the current `Merchant` attribution of agent comments is a defect in its own right, and on the released lane this plan does not fix it. It should get its own issue rather than being buried here.
