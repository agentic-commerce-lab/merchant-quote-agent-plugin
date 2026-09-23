# Draft Mode and Merchant Feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A per-sales-channel Draft Mode in which the quote agent prepares replies and price changes in a DAL version of the quote but never sends them, a review panel where the merchant edits, sends or rejects each draft, and a feedback form whose answers (plus what was actually sent) reach the JSONL export.

**Architecture:** Draft Mode is a decorator around the existing servicing pipeline (`Review\DraftModePipeline`). In Draft Mode it hands the unchanged `NegotiationPipeline` a `Review\DraftingQuoteGateway` that routes price writes into a fresh DAL version of the quote, swallows comments and transitions, and records the would-be reply on the decision row. The merchant's Send (admin API, `src/Review`) merges that version, posts the reply as the admin user and moves the quote to `replied`. Review state and feedback are columns on `merchant_quote_agent_decision`.

**Tech Stack:** PHP 8.3, Shopware 6.7 DAL, SwagCommercial quote management (7.13 trunk and 6.7.12 released), cuyz/valinor (`Policy\Data\ArrayMapper`), PHPUnit 11, Vue 3 administration (Meteor `mt-*` components), node `assert` self-checks.

**Spec:** `docs/superpowers/specs/2026-09-23-draft-mode-design.md`

## Global Constraints

- `declare(strict_types=1)` in every PHP file; Mago analyze at full strictness — no `mixed` escape hatches, no unsafe casts.
- Gate thresholds: cyclomatic complexity 10 per class, nesting depth 4, **at most 5 constructor/method parameters**, ~400 lines per file.
- Target PHP 8.3. No DAL attribute argument or core API newer than the 6.7.1 support floor (`CoreFloorCompatibilityTest`) — in particular no `maxLength:` on `#[Field]`.
- `src/Negotiation` must not import Shopware beyond `IllegalTransitionException` (`NamespacePurityTest`); `src/Policy` imports none.
- Throw domain exceptions only; wrap with `$previous`; never `return`/`throw` from `finally`.
- Boundary data: valinor via `Policy\Data\ArrayMapper` only. No new `ValidatorInterface::validate()` call.
- Logging via PSR-3, structured context, no PII.
- Record columns stay `#[Protection(write: [Protection::SYSTEM_SCOPE])]`; writes go through system-scope services.
- Migration timestamp for this feature: `1789800000`.
- mt-badge variants: only `neutral | info | positive | critical | attention`.
- Snippets in both `en-GB` (`snippet/en.json`) and `de-DE` (`snippet/de.json`).
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Checks: `composer run format:check && composer run lint` after every PHP change; `composer run typecheck` for `src`; `composer run test` for unit tests; admin changes also `composer run quality:admin`.
- Integration tests run against the shared local shop: `composer run test:integration -- --filter <Name>`. The sync does **not** run migrations — after Task 3, run `docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/console database:migrate MerchantQuoteAgentPlugin --all && bin/console cache:clear'` once. Never `plugin:update`.

## Deviations from the spec (decided while planning)

1. **Where the fork sits.** The spec sketched branches inside `OfferRound`, `ReplyComposer`, `ClarificationRound` and `QuoteEscalator`. Several of those are at the 5-parameter cap and `Negotiation` must stay Shopware-free, so Draft Mode is a pipeline decorator plus a drafting gateway instead. Observable behaviour is the spec's table exactly. `ReplyComposer::reword()` and `ReplyComposer::transitionFor()` become public for the review endpoints; nothing else in `Negotiation` changes.
2. **Stale check needs its own column.** Right after a pass whose AskMirror rewrote requested prices, `ServicingFingerprint::of(live)` never equals the handler's stamp (the stamp describes the pre-mirror snapshot). The drafting pass therefore records `review_fingerprint` = state + buyer comments *as serviced* + asks *as live after mirroring*; Send compares `ServicingFingerprint::of(live)` with it. One more nullable column, dropped from the export.
3. **The review card reads a backend view.** Instead of the admin reading the DAL version through the repository, `GET …/decision/{id}/draft` returns live vs drafted values in one shape, which Preview also returns.
4. **`sent_changes` is totals-level:** `{discountPercent, totalNet, totalGross, expiresAt, editedByMerchant}` — no per-line ids in the export.
5. **Any pass supersedes a pending draft**, including `nothing_to_do` ("thanks!"): a buyer comment makes the draft stale anyway.
6. **ACL** is an *additional permission* (`merchant_quote_agent_drafts.review`), because Shopware's role keys under `permissions` are fixed (`viewer/editor/creator/deleter`) and `editor` already means "edit strategies".
7. **Dashboard semantics.** Passes whose `reviewStatus` is `pending`, `rejected` or `superseded` do not count as having answered the buyer, and any drafted pass counts against auto-execution (a human was needed). New disposition `awaitingReview`.

## File map

| File | Responsibility |
|---|---|
| `src/Bridge/AgentContext.php` (modify) | `forVersion()` — agent context bound to a DAL version |
| `src/Bridge/QuoteSnapshotReader.php` (modify) | `readIn()` — read in whatever version a context names |
| `src/Bridge/SwagCommercialQuoteGateway.php` (modify) | optional `Context` so one class serves live, draft and merchant |
| `src/Bridge/QuoteGatewayFactory.php` (modify) | `forContext()` |
| `src/Bridge/QuoteDraftVersionsInterface.php`, `QuoteDraftVersions.php`, `DraftVersionUnavailable.php` (create) | create / gateway / merge / delete a draft version |
| `src/Config/*` + `config.xml` (modify) | `draftMode` setting |
| `src/Migration/Migration1789800000AddDraftReviewToDecision.php` (create) | nine columns |
| `src/Audit/QuoteDecisionRecord.php`, `DecisionDraft.php`, `DecisionRecorder.php` (modify) | new fields, `recordDraft()` |
| `src/Audit/ReviewStatus.php`, `DraftOutcome.php` (create) | review vocabulary; pending-or-discard at finish |
| `src/Audit/DecisionReviewStoreInterface.php`, `DecisionReviewStore.php` (create) | supersede / sent / rejected / feedback writes |
| `src/Audit/Export/AnonymizedDecision.php`, `src/Audit/DecisionEraser.php` (modify) | export classification, erasure |
| `src/Servicing/ServicingFingerprint.php` (modify) | `review()` |
| `src/Servicing/AgentDisclosure.php`, `ServiceQuoteHandler.php`, `ShopwareEscalationNotifier.php` (modify) | no banner for drafts; draft-ready copy |
| `src/Policy/Data/QuoteEscalationReason.php` (modify) | `DraftReady` |
| `src/Review/DraftingQuoteGateway.php`, `DraftModePipeline.php` (create) | Draft Mode pass |
| `src/Protocol/Mandate/NegotiationBands.php`, `SellerMandateFactory.php`, `src/Protocol/Http/MandateDocumentResponder.php` (modify) | 0 bps in Draft Mode |
| `src/Review/{DraftEdits,FeedbackReason,FeedbackRequest,InvalidReviewRequest,DraftNotReviewable,DecisionNotFound,PendingDraft,PendingDrafts,DraftEditor,DraftReply,DraftView,DraftSender,DraftReviewController}.php` (create) | review endpoints |
| `src/Negotiation/ReplyComposer.php` (modify) | `reword()`, `transitionFor()` public |
| `src/Resources/config/services.php`, `src/MerchantQuoteAgentPlugin.php` (modify) | wiring, route import |
| admin `review.ts`, `review.check.mjs`, `decision.ts`, `decision.check.mjs`, `acl/index.ts`, snippets (modify/create) | pure logic + vocabulary |
| admin `component/merchant-quote-agent-draft-review/*`, `component/merchant-quote-agent-feedback-modal/*` (create); detail + list pages (modify) | UI |
| `docs/for-merchants.md`, spec (modify) | docs |

---

### Task 1: Draft versions in the bridge (and prove them on both lanes)

**Files:**
- Modify: `src/Bridge/AgentContext.php`
- Modify: `src/Bridge/QuoteSnapshotReader.php:52-85`
- Modify: `src/Bridge/SwagCommercialQuoteGateway.php`
- Modify: `src/Bridge/QuoteGatewayFactory.php`
- Create: `src/Bridge/ContextBoundGateways.php`, `src/Bridge/QuoteDraftVersionsInterface.php`, `src/Bridge/QuoteDraftVersions.php`, `src/Bridge/DraftVersionUnavailable.php`
- Modify: `src/Resources/config/services.php` (commercial block, after `QuoteGatewayFactory`)
- Test: `tests/Integration/DraftVersionTest.php`, `tests/Unit/Bridge/AgentContextVersionTest.php`

**Interfaces:**
- Produces:
  - `AgentContext::forVersion(string $versionId): Context`
  - `QuoteSnapshotReader::readIn(string $quoteId, Context $versionedContext): QuoteSnapshot`
  - `interface ContextBoundGateways { forContext(Context $context): ?QuoteGatewayInterface; }`, implemented by `QuoteGatewayFactory`
  - `interface QuoteDraftVersionsInterface { create(string $quoteId): string; gateway(string $versionId): QuoteGatewayInterface; merge(string $versionId): void; delete(string $quoteId, string $versionId): void; }`
  - `QuoteDraftVersions::VERSION_NAME = 'merchant-quote-agent-draft'`

- [ ] **Step 1: Write the failing unit test for `AgentContext::forVersion()`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use PHPUnit\Framework\TestCase;

final class AgentContextVersionTest extends TestCase
{
    public function testAVersionedAgentContextKeepsTheAgentMarker(): void
    {
        // createWithVersionId() drops states, so a naive re-version would make
        // the servicing trigger read our own draft writes as someone else's.
        $context = AgentContext::forVersion('0190aaaa0000700080000000000000aa');

        self::assertSame('0190aaaa0000700080000000000000aa', $context->getVersionId());
        self::assertTrue($context->hasState(AgentContext::STATE));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`Call to undefined method ...forVersion()`)

Run: `vendor/bin/phpunit tests/Unit/Bridge/AgentContextVersionTest.php`

- [ ] **Step 3: Add `forVersion()` to `src/Bridge/AgentContext.php`** (below `create()`)

```php
    /**
     * The agent's context re-versioned onto a DAL version — Draft Mode writes
     * its offer into one. The state is added AFTER createWithVersionId(),
     * which drops states (see above), or the version's writes would read as
     * a stranger's.
     */
    public static function forVersion(string $versionId): Context
    {
        $context = Context::createDefaultContext()->createWithVersionId($versionId);
        $context->addState(self::STATE);

        return $context;
    }
```

- [ ] **Step 4: Run it — expect PASS.** Run: `vendor/bin/phpunit tests/Unit/Bridge/AgentContextVersionTest.php`

- [ ] **Step 5: Split `QuoteSnapshotReader::read()`** so a caller holding an already-versioned context can read it as-is. Replace the start of `read()` (currently `$versionedContext = $this->versionResolver->contextFor($context, $version);` followed by the criteria/search/return body) with:

```php
    /** @throws QuoteNotFoundException */
    public function read(string $quoteId, QuoteVersion $version, Context $context): QuoteSnapshot
    {
        return $this->readIn($quoteId, $this->versionResolver->contextFor($context, $version));
    }

    /**
     * Reads in whatever version $versionedContext already names — a Draft
     * Mode version, which QuoteVersion has no case for because nothing but
     * the gateway bound to it ever asks.
     *
     * @throws QuoteNotFoundException
     */
    public function readIn(string $quoteId, Context $versionedContext): QuoteSnapshot
    {
        $criteria = new Criteria([$quoteId]);
        // ... the existing body of read() from `$criteria->addAssociation('lineItems');`
        //     through `return new QuoteSnapshot(...)`, unchanged ...
    }
```

(Move the body verbatim; only the first line of the old method moves into `read()`.)

- [ ] **Step 6: Give `SwagCommercialQuoteGateway` an optional context.** Add the imports `use Shopware\Core\Defaults;` and `use Shopware\Core\Framework\Context;` (Context is already imported — keep one), change the constructor and add two private helpers:

```php
    public function __construct(
        private QuoteSnapshotReader $reader,
        private QuoteWriters $writers,
        private QuoteLifecycleWriters $lifecycle,
        /**
         * Null is the agent on the live quote — every gateway before Draft
         * Mode. A context bound to a DAL version is a Draft Mode draft; an
         * admin's API context is the merchant sending one. Cloned per call so
         * no write can leak a state into the next.
         */
        private ?Context $context = null,
    ) {}

    private function context(): Context
    {
        return $this->context === null ? AgentContext::create() : clone $this->context;
    }

    /** `Live` means "the version this gateway is bound to" — the draft, for a draft gateway. */
    private function read(string $quoteId, QuoteVersion $version, Context $context): QuoteSnapshot
    {
        if ($version === QuoteVersion::Live && $context->getVersionId() !== Defaults::LIVE_VERSION) {
            return $this->reader->readIn($quoteId, $context);
        }

        return $this->reader->read($quoteId, $version, $context);
    }
```

Then, in every method, replace `AgentContext::create()` with `$this->context()`, and replace the three `$this->reader->read($quoteId, ..., $context)` calls (in `fetchSnapshot`, `assertRevision`, `addComment`) with `$this->read($quoteId, ..., $context)`. `fetchSnapshot` becomes:

```php
    #[\Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        return $this->read($quoteId, $version, $this->context());
    }
```

- [ ] **Step 7: Add `ContextBoundGateways` and implement it on `QuoteGatewayFactory`**

`src/Bridge/ContextBoundGateways.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;

/**
 * A gateway whose reads and writes run under a given context — a Draft Mode
 * version, or the admin user sending a draft. The seam exists so the review
 * services can be tested with a fake gateway; QuoteGatewayFactory is final.
 */
interface ContextBoundGateways
{
    /** Null when the SwagCommercial license toggle is off. */
    public function forContext(Context $context): ?QuoteGatewayInterface;
}
```

`src/Bridge/QuoteGatewayFactory.php` — `final readonly class QuoteGatewayFactory implements ContextBoundGateways`, add `use Shopware\Core\Framework\Context;` and:

```php
    /**
     * A gateway whose reads and writes run under $context instead of the
     * agent's own live one: a Draft Mode version (AgentContext::forVersion())
     * or the admin user sending a draft. Null exactly when create() is.
     */
    #[\Override]
    public function forContext(Context $context): ?QuoteGatewayInterface
    {
        if (!CommercialAvailability::isLicensed()) {
            return null;
        }

        return new SwagCommercialQuoteGateway($this->reader, $this->writers, $this->lifecycle, $context);
    }
```

- [ ] **Step 8: Create the port and its implementation**

`src/Bridge/DraftVersionUnavailable.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/** SwagCommercial's license toggle is off, so no gateway can serve a draft version. */
final class DraftVersionUnavailable extends \RuntimeException
{
    public static function forVersion(string $versionId): self
    {
        return new self(sprintf('Draft version %s cannot be served: the SwagCommercial gateway is unavailable.', $versionId));
    }
}
```

`src/Bridge/QuoteDraftVersionsInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/**
 * Draft Mode's proposal lives in a DAL version of the quote — the same
 * mechanism SwagCommercial's own admin quote editor drafts in — so it is
 * priced by Shopware's real recalculation while the live quote, and so the
 * buyer, sees nothing until the merchant sends it.
 */
interface QuoteDraftVersionsInterface
{
    /** @return string the new version's id */
    public function create(string $quoteId): string;

    /** @throws DraftVersionUnavailable */
    public function gateway(string $versionId): QuoteGatewayInterface;

    /** Replays the version onto the live quote; the version is gone afterwards. */
    public function merge(string $versionId): void;

    /** Discards the version. A version that is already gone is not an error. */
    public function delete(string $quoteId, string $versionId): void;
}
```

`src/Bridge/QuoteDraftVersions.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Create, merge and delete mirror SwagCommercial's
 * StorefrontQuoteDraftVersionManager: createVersion() off a live context,
 * merge() in system scope, and delete as "delete the quote in the version's
 * context, then the version row". All three run in system scope because the
 * version is the agent's working copy, not anybody's edit.
 */
final readonly class QuoteDraftVersions implements QuoteDraftVersionsInterface
{
    public const VERSION_NAME = 'merchant-quote-agent-draft';

    /**
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quotes
     * @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $versions
     */
    public function __construct(
        private EntityRepository $quotes,
        private EntityRepository $versions,
        private QuoteGatewayFactory $gateways,
    ) {}

    #[\Override]
    public function create(string $quoteId): string
    {
        return $this->quotes->createVersion($quoteId, AgentContext::create(), self::VERSION_NAME);
    }

    #[\Override]
    public function gateway(string $versionId): QuoteGatewayInterface
    {
        return $this->gateways->forContext(AgentContext::forVersion($versionId))
            ?? throw DraftVersionUnavailable::forVersion($versionId);
    }

    #[\Override]
    public function merge(string $versionId): void
    {
        $this->quotes->merge($versionId, AgentContext::create());
    }

    #[\Override]
    public function delete(string $quoteId, string $versionId): void
    {
        $this->quotes->delete([['id' => $quoteId]], AgentContext::forVersion($versionId));
        $this->versions->delete([['id' => $versionId]], Context::createDefaultContext());
    }
}
```

Note: `AgentContext::create()` is a default (system-scope) context with the agent state, so the servicing trigger ignores the merge's comment/line writes exactly as it ignores every other agent write.

- [ ] **Step 9: Wire it** in `src/Resources/config/services.php`, directly after `$services->set(QuoteGatewayInterface::class)->factory(...)` (add the `use` lines at the top in alphabetical position):

```php
    // Draft Mode's working copy of a quote: a DAL version, priced by the real
    // recalculation, invisible to the buyer until a merchant sends it.
    $services->set(QuoteDraftVersions::class)->args([
        service('quote.repository'),
        service('version.repository'),
        service(QuoteGatewayFactory::class),
    ]);
    $services->alias(QuoteDraftVersionsInterface::class, QuoteDraftVersions::class);
    $services->alias(ContextBoundGateways::class, QuoteGatewayFactory::class);
```

- [ ] **Step 10: Write the integration test** `tests/Integration/DraftVersionTest.php`

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersions;
use Shopware\Core\Framework\Context;

/**
 * Draft Mode's premise, against the real shop: an offer written through a
 * draft gateway is priced by Shopware's own recalculation, leaves the live
 * quote untouched, and merge() carries it over. Run on BOTH lanes (7.13 and
 * 6.7.12) — the 2026-09-23 spike only proved 7.13.
 */
final class DraftVersionTest extends IntegrationTestCase
{
    public function testAQuoteWideDraftIsPricedInTheVersionAndMergedOnDemand(): void
    {
        $live = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $live->fetchSnapshot($quoteId);
        $versions = $this->versions();

        $versionId = $versions->create($quoteId);
        $draft = $versions->gateway($versionId);
        $draft->updateQuote($quoteId, new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 10.0)));
        $draft->recalculate($quoteId);

        $drafted = $draft->fetchSnapshot($quoteId);
        $untouched = $live->fetchSnapshot($quoteId);

        self::assertSame($before->totals->totalNet, $untouched->totals->totalNet, 'The draft moved the live quote.');
        self::assertLessThan($before->totals->totalNet, $drafted->totals->totalNet, 'The draft priced nothing.');
        self::assertNotNull($drafted->totals->totalGross);

        $versions->merge($versionId);
        $merged = $live->fetchSnapshot($quoteId);

        self::assertEqualsWithDelta($drafted->totals->totalNet, $merged->totals->totalNet, 0.01);
        self::assertEqualsWithDelta((float) $drafted->totals->totalGross, (float) $merged->totals->totalGross, 0.01);
        self::assertCount(
            \count($before->content->comments),
            $merged->content->comments,
            'Merging duplicated or dropped the quote\'s comments.',
        );
    }

    public function testAPerLineDraftStaysInTheVersion(): void
    {
        $live = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $live->fetchSnapshot($quoteId);
        $line = $before->content->lines[0] ?? null;
        self::assertNotNull($line, 'The fixture quote has no line.');

        $versions = $this->versions();
        $versionId = $versions->create($quoteId);
        $draft = $versions->gateway($versionId);
        $draft->updateLineItems($quoteId, [
            new QuoteLineItemChange($line->identity->lineItemId, unitPriceNet: round($line->unitPriceNet * 0.9, 2)),
        ]);
        $draft->recalculate($quoteId);

        self::assertLessThan($before->totals->totalNet, $draft->fetchSnapshot($quoteId)->totals->totalNet);
        self::assertSame($before->totals->totalNet, $live->fetchSnapshot($quoteId)->totals->totalNet);
    }

    public function testDeletingDiscardsTheDraftAndASecondDeleteIsHarmless(): void
    {
        $live = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $live->fetchSnapshot($quoteId);
        $versions = $this->versions();

        $versionId = $versions->create($quoteId);
        $versions->gateway($versionId)->updateQuote(
            $quoteId,
            new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 10.0)),
        );
        $versions->delete($quoteId, $versionId);
        $versions->delete($quoteId, $versionId);

        self::assertSame($before->totals->totalNet, $live->fetchSnapshot($quoteId)->totals->totalNet);
    }

    /** Built by hand, like ShopServices builds the gateway: plugin services are private in the test container. */
    private function versions(): QuoteDraftVersions
    {
        return new QuoteDraftVersions(
            static::getContainer()->get('quote.repository'),
            static::getContainer()->get('version.repository'),
            static::gatewayFactory(),
        );
    }
}
```

- [ ] **Step 11: Run unit checks and the integration test on the local 7.13 shop**

Run: `composer run format:check && composer run lint && composer run typecheck && composer run test`
Run: `composer run test:integration -- --filter DraftVersionTest`
Expected: all green; 3 integration tests pass.

- [ ] **Step 12: Run the same integration test on the 6.7.12 lane (gate for everything after this task)**

The released-SwagCommercial lane is the b2bseller host (it IP-bans on frequent SSH — one connection per command, no loops). Resolve the absolute docroot once, then run:

```bash
ssh shoelscher@b2bseller-shoelscher.eu-core-1.shopdev.de 'cd ~/files/b2bseller && pwd'
```

```bash
SHOP_SSH=shoelscher@b2bseller-shoelscher.eu-core-1.shopdev.de SHOP_PATH=<absolute path printed above> SHOP_PHP=php8.4 composer run test:integration -- --filter DraftVersionTest
```

Expected: 3 tests pass. **If they fail on 6.7.12, stop and report to the user** — the whole design rests on this.

- [ ] **Step 13: Commit**

```bash
git add src/Bridge tests/Unit/Bridge/AgentContextVersionTest.php tests/Integration/DraftVersionTest.php src/Resources/config/services.php
git commit -m "feat(bridge): draft versions for Draft Mode

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The `draftMode` setting

**Files:**
- Modify: `src/Resources/config/config.xml` (Agent activation card)
- Modify: `src/Config/QuoteAgentSettings.php`, `src/Config/QuoteAgentSettingsFactory.php:88-96`, `src/Config/QuoteAgentSettingsReader.php`
- Test: `tests/Unit/Config/ConfigXmlSchemaTest.php`, `tests/Unit/Config/QuoteAgentSettingsReaderTest.php`, `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`

**Interfaces:**
- Produces: `QuoteAgentSettings::$draftMode` (bool, default false, 7th constructor argument); `QuoteAgentSettingsReader::draftMode(?string $salesChannelId): bool`; `notifyBuyerOnEscalation()` returns false whenever `draftMode()` is true.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Config/ConfigXmlSchemaTest.php`:

```php
    /** Draft Mode changes who sends; installing the plugin must not switch it on. */
    public function testConfigXmlDeclaresDraftModeDefaultOff(): void
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(__DIR__ . '/../../../src/Resources/config/config.xml'));

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//input-field[name="draftMode"]');
        self::assertNotNull($nodes);
        self::assertSame(1, $nodes->count());

        $field = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $field);
        self::assertSame('bool', $field->getAttribute('type'));
        self::assertSame('false', $xpath->query('defaultValue', $field)?->item(0)?->textContent);
    }
```

Append to `tests/Unit/Config/QuoteAgentSettingsReaderTest.php`:

```php
    public function testDraftModeIsOnOnlyWhenExplicitlyTrue(): void
    {
        self::assertFalse($this->reader()->draftMode(null));
        self::assertFalse($this->reader(['draftMode' => false])->draftMode(null));
        self::assertTrue($this->reader(['draftMode' => true])->draftMode(null));
    }

    /** The agent never speaks to the buyer in Draft Mode — not even to say a human has it. */
    public function testDraftModeSilencesTheEscalationNotice(): void
    {
        self::assertFalse(
            $this->reader(['notifyBuyerOnEscalation' => true, 'draftMode' => true])->notifyBuyerOnEscalation(null),
        );
    }

    public function testTheSettingsCarryDraftMode(): void
    {
        self::assertTrue($this->reader(['draftMode' => true])->forSalesChannel(null)?->draftMode);
        self::assertFalse($this->reader()->forSalesChannel(null)?->draftMode);
    }
```

Append to `tests/Unit/Config/QuoteAgentSettingsFactoryTest.php`:

```php
    public function testDraftModeSurvivesWithPolicyAndWithStrategy(): void
    {
        $settings = self::build(['draftMode' => true]);
        self::assertNotNull($settings);

        self::assertTrue($settings->withPolicy($settings->policy)->draftMode);
        self::assertTrue($settings->withStrategy(
            new \MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy('p', '0000000000000000000000000000cccc'),
            \MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource::Config,
        )->draftMode);
    }
```

Before relying on it, open `src/Strategy/ResolvedStrategy.php` and use its real constructor argument order in that test.

- [ ] **Step 2: Run — expect FAIL.** Run: `vendor/bin/phpunit tests/Unit/Config`

- [ ] **Step 3: Add the field to `config.xml`**, as the last field of the *Agent activation* card:

```xml
        <input-field type="bool">
            <name>draftMode</name>
            <label>Draft Mode: review every reply before it is sent</label>
            <defaultValue>false</defaultValue>
            <helpText>The agent prepares every reply and price change but never sends them. Each draft waits for you under Orders › Quote agent, where you can edit, send or reject it. The buyer hears nothing from the agent directly — not even that a human is looking at the quote.</helpText>
        </input-field>
```

- [ ] **Step 4: Add the field to `QuoteAgentSettings`.** New last constructor argument, copied by both `with*` methods; update the docblock's "All six fields" to "All seven fields" and add one sentence: "`$draftMode` is here rather than behind a raw accessor because the pipeline decorator and the mandate read it alongside the validated policy."

```php
        public ?StrategyAssignmentSource $strategyAssignmentSource = null,
        /** Prepare, never send — see Review\DraftModePipeline. */
        public bool $draftMode = false,
    ) {}
```

In `withPolicy()` and `withStrategy()` add `$this->draftMode,` as the last `new self(...)` argument.

- [ ] **Step 5: Factory** — in `QuoteAgentSettingsFactory::fromValues()`'s `return new QuoteAgentSettings(...)` add:

```php
            draftMode: RawConfigValue::bool($raw, 'draftMode') === true,
```

- [ ] **Step 6: Reader** — add `'draftMode',` to `KEYS`, and replace `notifyBuyerOnEscalation()` / add `draftMode()`:

```php
    /**
     * Read raw and never validated, which is the whole point: see
     * BuyerNotificationPreference. `!== false` rather than `=== true` so an
     * unset key means notify, matching config.xml's defaultValue and the
     * identical rule in QuoteAgentSettingsFactory — only an explicit false
     * silences the notice. Draft Mode silences it too: in Draft Mode the agent
     * says nothing to the buyer at all.
     */
    #[\Override]
    public function notifyBuyerOnEscalation(?string $salesChannelId): bool
    {
        return $this->config->get(self::DOMAIN . 'notifyBuyerOnEscalation', $salesChannelId) !== false
            && !$this->draftMode($salesChannelId);
    }

    /** Default off: only an explicit true holds the agent's output back for review. */
    public function draftMode(?string $salesChannelId): bool
    {
        return $this->config->get(self::DOMAIN . 'draftMode', $salesChannelId) === true;
    }
```

- [ ] **Step 7: Run — expect PASS.** Run: `vendor/bin/phpunit tests/Unit/Config && composer run format:check && composer run lint && composer run typecheck`

- [ ] **Step 8: Commit**

```bash
git add src/Resources/config/config.xml src/Config tests/Unit/Config
git commit -m "feat(config): draftMode setting

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Review columns on the decision record (schema, recorder, export, erasure)

**Files:**
- Create: `src/Migration/Migration1789800000AddDraftReviewToDecision.php`
- Create: `src/Audit/ReviewStatus.php`, `src/Audit/DraftOutcome.php`
- Modify: `src/Audit/QuoteDecisionRecord.php` (append fields), `src/Audit/DecisionDraft.php`, `src/Audit/DecisionRecorder.php`
- Modify: `src/Audit/Export/AnonymizedDecision.php`, `src/Audit/DecisionEraser.php`
- Modify: `docs/for-merchants.md` (export section)
- Test: `tests/Unit/Audit/AddDraftReviewToDecisionMigrationTest.php`, `tests/Unit/Audit/DraftMirrorsEntityTest.php`, `tests/Unit/Audit/DecisionRecorderDraftTest.php`

**Interfaces:**
- Produces:
  - `enum Audit\ReviewStatus: string { Pending='pending'; Sent='sent'; Rejected='rejected'; Superseded='superseded'; static awaitsReview(NegotiationOutcome $outcome): bool }` — true for Offered, Countered, Clarified.
  - `DecisionRecorder::recordDraft(?string $versionId, string $reviewFingerprint): void`
  - Record/draft properties: `draftVersionId`, `reviewStatus`, `reviewFingerprint` (written by the pass); `reviewedAt`, `sentReply`, `sentChanges`, `feedbackReasons`, `feedbackComment`, `feedbackAt` (written later).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Audit/AddDraftReviewToDecisionMigrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Migration\Migration1789800000AddDraftReviewToDecision;
use PHPUnit\Framework\TestCase;

final class AddDraftReviewToDecisionMigrationTest extends TestCase
{
    public function testTheTimestampIsExact(): void
    {
        self::assertSame(1789800000, (new Migration1789800000AddDraftReviewToDecision())->getCreationTimestamp());
    }
}
```

`tests/Unit/Audit/DecisionRecorderDraftTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DecisionRecorderDraftTest extends TestCase
{
    public function testADraftedOfferIsRecordedAsPendingWithItsVersion(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft('0190aaaa0000700080000000000000aa', 'open|1|x');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame('pending', $writer->drafts[0]->reviewStatus);
        self::assertSame('0190aaaa0000700080000000000000aa', $writer->drafts[0]->draftVersionId);
        self::assertSame('open|1|x', $writer->drafts[0]->reviewFingerprint);
    }

    public function testAClarificationDraftHasNoVersionButIsStillPending(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft(null, 'open|1|x');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Clarified));

        self::assertSame('pending', $writer->drafts[0]->reviewStatus);
        self::assertNull($writer->drafts[0]->draftVersionId);
    }

    /** The version of an escalated pass is deleted by the pipeline; the row must not point at it. */
    public function testAnEscalatedPassKeepsNoDraft(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft('0190aaaa0000700080000000000000aa', 'open|1|x');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Escalated));

        self::assertNull($writer->drafts[0]->reviewStatus);
        self::assertNull($writer->drafts[0]->draftVersionId);
        self::assertNull($writer->drafts[0]->reviewFingerprint);
    }

    public function testAFailedPassKeepsNoDraft(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft('0190aaaa0000700080000000000000aa', 'open|1|x');
        $recorder->finish(null, new \RuntimeException('boom'));

        self::assertNull($writer->drafts[0]->reviewStatus);
        self::assertNull($writer->drafts[0]->draftVersionId);
    }

    public function testTheFirstFingerprintWinsAndALaterNullVersionKeepsTheVersion(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->recordDraft('0190aaaa0000700080000000000000aa', 'first');
        $recorder->recordDraft(null, 'second');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertSame('first', $writer->drafts[0]->reviewFingerprint);
        self::assertSame('0190aaaa0000700080000000000000aa', $writer->drafts[0]->draftVersionId);
    }

    public function testAnAutonomousPassHasNoReviewStatus(): void
    {
        [$recorder, $writer] = self::recorder();

        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        self::assertNull($writer->drafts[0]->reviewStatus);
    }

    /** @return array{0: DecisionRecorder, 1: FakeDecisionWriter} */
    private static function recorder(): array
    {
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin(
            QuoteSnapshotFixture::snapshot(),
            new PassContext(ServicingTriggerReason::cases()[0], 0),
        );

        return [$recorder, $writer];
    }
}
```

Update `tests/Unit/Audit/DraftMirrorsEntityTest.php`: add a constant and include it in `testEveryEntityFieldIsADraftPropertyOrExplicitlyReserved()`'s `$excluded`:

```php
    /**
     * Written by the review endpoints (Review\*, through DecisionReviewStore)
     * after a merchant sends, rejects or comments on a draft, never by a pass.
     */
    private const WRITTEN_BY_THE_REVIEW_STORE = [
        'reviewedAt',
        'sentReply',
        'sentChanges',
        'feedbackReasons',
        'feedbackComment',
        'feedbackAt',
    ];
```

```php
            ...self::WRITTEN_BY_THE_REVIEW_STORE,
```

- [ ] **Step 2: Run — expect FAIL.** Run: `vendor/bin/phpunit tests/Unit/Audit`

- [ ] **Step 3: Migration** `src/Migration/Migration1789800000AddDraftReviewToDecision.php`

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Override;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Draft Mode's review lifecycle and the merchant's feedback, on the row of the
 * pass they are about.
 *
 * `draft_version_id`, `review_status` and `review_fingerprint` are written by
 * the drafting pass; the other six by the review endpoints afterwards. All
 * nullable: every existing row, and every autonomous pass, has none of it.
 * `review_status` is indexed because the list page filters and the
 * superseding pass searches on it.
 *
 * Idempotent on `review_status` alone: the nine columns are added in one
 * statement, so either all exist or none do.
 */
class Migration1789800000AddDraftReviewToDecision extends MigrationStep
{
    #[Override]
    public function getCreationTimestamp(): int
    {
        return 1789800000;
    }

    /** @throws DbalException */
    #[Override]
    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `merchant_quote_agent_decision` LIKE :column', [
            'column' => 'review_status',
        ]);

        if ($columns !== []) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
                ALTER TABLE `merchant_quote_agent_decision`
                    ADD COLUMN `draft_version_id` BINARY(16) NULL,
                    ADD COLUMN `review_status` VARCHAR(16) NULL,
                    ADD COLUMN `review_fingerprint` LONGTEXT NULL,
                    ADD COLUMN `reviewed_at` DATETIME(3) NULL,
                    ADD COLUMN `sent_reply` LONGTEXT NULL,
                    ADD COLUMN `sent_changes` JSON NULL,
                    ADD COLUMN `feedback_reasons` JSON NULL,
                    ADD COLUMN `feedback_comment` LONGTEXT NULL,
                    ADD COLUMN `feedback_at` DATETIME(3) NULL,
                    ADD KEY `idx.mqad.review_status` (`review_status`);
            SQL);
    }

    #[Override]
    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive. The columns are additive.
    }
}
```

- [ ] **Step 4: `src/Audit/ReviewStatus.php`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;

/**
 * Where a Draft Mode pass stands. Null on the row means the pass was never a
 * draft — every autonomous pass, and every row written before Draft Mode.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /** The outcomes that put something in front of the buyer — so, in Draft Mode, in front of the merchant. */
    public static function awaitsReview(NegotiationOutcome $outcome): bool
    {
        return \in_array(
            $outcome,
            [NegotiationOutcome::Offered, NegotiationOutcome::Countered, NegotiationOutcome::Clarified],
            true,
        );
    }
}
```

- [ ] **Step 5: `src/Audit/DraftOutcome.php`** (same shape as `PassOutcome`, keeping the branch out of the recorder's budget)

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;

/**
 * Settles a Draft Mode pass at finish(): a pass that drafted something for the
 * buyer waits for the merchant, anything else keeps no draft — its version is
 * deleted by DraftModePipeline, so the row must not point at it.
 */
final class DraftOutcome
{
    private function __construct() {}

    public static function applyTo(DecisionDraft $draft, ?NegotiationPass $pass, ?\Throwable $error): void
    {
        if ($draft->reviewFingerprint === null) {
            return;
        }

        if ($error === null && $pass !== null && ReviewStatus::awaitsReview($pass->outcome)) {
            $draft->reviewStatus = ReviewStatus::Pending->value;

            return;
        }

        $draft->draftVersionId = null;
        $draft->reviewFingerprint = null;
    }
}
```

- [ ] **Step 6: `DecisionDraft`** — append before `startedAt`:

```php
    /** The DAL version holding a Draft Mode proposal — see Review\DraftingQuoteGateway. */
    public ?string $draftVersionId = null;

    /** Null for an autonomous pass — see ReviewStatus. */
    public ?string $reviewStatus = null;

    /** What the buyer's side of the quote looked like when drafted — see ServicingFingerprint::review(). */
    public ?string $reviewFingerprint = null;
```

Also extend the class docblock's "minus `terminalState`/`terminalAt`" sentence with ", minus the six review columns (written by DecisionReviewStore)".

- [ ] **Step 7: `DecisionRecorder`** — add the method (after `recordReply()`), and call `DraftOutcome` in `finish()` right after `ErrorChain::applyTo($draft, $error);`:

```php
    /**
     * A Draft Mode pass drafted something: its version (null for a
     * clarification, which changes no price) and the buyer-side fingerprint
     * a Send checks staleness against. Called by DraftingQuoteGateway on its
     * first draft write and again on the reply; the first fingerprint is the
     * one taken after AskMirror and before anything else, so it wins.
     */
    public function recordDraft(?string $versionId, string $reviewFingerprint): void
    {
        if ($this->draft === null) {
            return;
        }

        $this->draft->draftVersionId = $versionId ?? $this->draft->draftVersionId;
        $this->draft->reviewFingerprint ??= $reviewFingerprint;
    }
```

```php
        DraftOutcome::applyTo($draft, $pass, $error);
```

- [ ] **Step 8: `QuoteDecisionRecord`** — append after `strategyAssignmentSource`, same attributes as the neighbours. Also extend the class docblock's "the only four columns not written by a servicing pass" to name the six review-store columns.

```php
    /** The DAL version holding a pending draft's prices; null once sent, rejected or superseded. */
    #[Field(type: FieldType::UUID, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $draftVersionId = null;

    /** See ReviewStatus. Null for an autonomous pass. */
    #[Field(type: FieldType::STRING, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $reviewStatus = null;

    /** Internal staleness check for Send — see ServicingFingerprint::review(). Never exported. */
    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $reviewFingerprint = null;

    /** When the merchant sent or rejected the draft. */
    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $reviewedAt = null;

    /** The text the merchant actually sent — compare with replyToBuyer, the draft. */
    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $sentReply = null;

    /** @var array<string, mixed>|null {discountPercent, totalNet, totalGross, expiresAt, editedByMerchant} */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $sentChanges = null;

    /** @var list<string>|null Review\FeedbackReason values */
    #[Field(type: FieldType::JSON, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?array $feedbackReasons = null;

    /** The merchant's own words on why the agent's work was not right. */
    #[Field(type: FieldType::TEXT, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?string $feedbackComment = null;

    #[Field(type: FieldType::DATETIME, api: ['admin-api' => true, 'store-api' => false])]
    #[Protection(write: [Protection::SYSTEM_SCOPE])]
    public ?\DateTimeImmutable $feedbackAt = null;
```

- [ ] **Step 9: Export classification** in `AnonymizedDecision` — append to the lists:

```php
    // VERBATIM, append:
        'reviewStatus',
        'reviewedAt',
        'sentChanges',
        'feedbackReasons',
        'feedbackAt',

    // FREE_TEXT becomes:
    public const FREE_TEXT = ['rawProposal', 'buyerAsk', 'replyToBuyer', 'violations', 'sentReply', 'feedbackComment'];

    // DROPPED becomes (update its docblock: "... and two internal working columns: a DAL version id and the staleness fingerprint, which carries line ids and comment timestamps"):
    public const DROPPED = ['quoteNumber', 'draftVersionId', 'reviewFingerprint'];
```

In `of()` add to `$row` (after `'writes' => $record->writes,`):

```php
            'reviewStatus' => $record->reviewStatus,
            'reviewedAt' => self::at($record->reviewedAt),
            'sentChanges' => $record->sentChanges,
            'feedbackReasons' => $record->feedbackReasons,
            'feedbackAt' => self::at($record->feedbackAt),
```

and to the free-text block:

```php
            'sentReply' => $record->sentReply,
            'feedbackComment' => $record->feedbackComment,
```

Update the FREE_TEXT docblock: "Exported only under --include-comments (the admin Export button includes them by default)."

- [ ] **Step 10: Erasure** — in `DecisionEraser::erased()` add after `'violations' => null,`:

```php
            // What the merchant sent in the buyer's conversation, and what
            // they wrote about it -- either can quote the buyer.
            'sentReply' => null,
            'feedbackComment' => null,
```

and change the comment above `'buyerAsk'` from "the same four" to "the same six".

- [ ] **Step 11: Docs** — in `docs/for-merchants.md`'s export section (around lines 313-411) add the seven exported fields to the matching lists with one line each (`reviewStatus`: pending/sent/rejected/superseded, null for autonomous passes; `reviewedAt`; `sentChanges`: totals the merchant actually sent and whether they edited; `feedbackReasons`: the reason codes; `feedbackAt`; `sentReply` and `feedbackComment` as free text), and name `draftVersionId`/`reviewFingerprint` as dropped.

- [ ] **Step 12: Run — expect PASS.** Run: `composer run test && composer run format:check && composer run lint && composer run typecheck`
Expected: green, including `ExportFieldCoverageTest`, `DecisionEraserTest`, `DraftMirrorsEntityTest`, `CoreFloorCompatibilityTest`.

- [ ] **Step 13: Apply the migration on the local shop (MySQL 8 probe) and run the record integration tests**

Run: `./scripts/sync-to-shop.sh && docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/console database:migrate MerchantQuoteAgentPlugin --all && bin/console cache:clear'`
Run: `composer run test:integration -- --filter 'DecisionRecord|DecisionExport'`
Expected: migration applies without error; tests green.

- [ ] **Step 14: Commit**

```bash
git add src/Migration src/Audit docs/for-merchants.md tests/Unit/Audit
git commit -m "feat(audit): review lifecycle and feedback columns on the decision record

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Review fingerprint and the review store

**Files:**
- Modify: `src/Servicing/ServicingFingerprint.php`
- Create: `src/Audit/DecisionReviewStoreInterface.php`, `src/Audit/DecisionReviewStore.php`
- Modify: `src/Resources/config/services.php` (audit block, after `EscalationResolutionWriter`)
- Test: `tests/Unit/Servicing/ReviewFingerprintTest.php`, `tests/Unit/Audit/DecisionReviewStoreTest.php`

**Interfaces:**
- Produces:
  - `ServicingFingerprint::review(QuoteSnapshot $serviced, QuoteSnapshot $live): string`
  - `interface DecisionReviewStoreInterface { find(string $decisionId): ?QuoteDecisionRecord; supersedePending(string $quoteId): list<string>; markSent(string $decisionId, string $sentReply, ?array $sentChanges): void; markRejected(string $decisionId): void; saveFeedback(string $decisionId, list<string> $reasons, string $comment): void; }`

- [ ] **Step 1: Write the failing fingerprint test** `tests/Unit/Servicing/ReviewFingerprintTest.php`

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

final class ReviewFingerprintTest extends TestCase
{
    /**
     * The reason review() exists: AskMirror writes the buyer's ask onto the
     * line DURING the pass, so of(live) right after a draft differs from the
     * handler's stamp. review() takes the asks from the live read, so a Send
     * straight after the draft is not stale.
     */
    public function testAMirroredAskDoesNotMakeTheDraftStale(): void
    {
        $comment = QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:00.000');
        $serviced = QuoteSnapshotFixture::snapshot(comments: [$comment], lines: [QuoteSnapshotFixture::line(null)]);
        $mirrored = QuoteSnapshotFixture::snapshot(comments: [$comment], lines: [QuoteSnapshotFixture::line(9.0)]);

        self::assertSame(ServicingFingerprint::of($mirrored), ServicingFingerprint::review($serviced, $mirrored));
    }

    public function testANewBuyerCommentMakesTheDraftStale(): void
    {
        $first = QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:00.000');
        $serviced = QuoteSnapshotFixture::snapshot(comments: [$first]);
        $later = QuoteSnapshotFixture::snapshot(comments: [$first, QuoteSnapshotFixture::buyerComment('2026-09-23 11:00:00.000')]);

        self::assertNotSame(ServicingFingerprint::of($later), ServicingFingerprint::review($serviced, $serviced));
    }

    /** A comment that landed while the pass ran was never read by it. */
    public function testACommentThatLandedDuringThePassIsNotCredited(): void
    {
        $first = QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:00.000');
        $serviced = QuoteSnapshotFixture::snapshot(comments: [$first]);
        $live = QuoteSnapshotFixture::snapshot(comments: [$first, QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:05.000')]);

        self::assertNotSame(ServicingFingerprint::of($live), ServicingFingerprint::review($serviced, $live));
    }

    public function testABuyerEditedAskMakesTheDraftStale(): void
    {
        $atDraft = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(9.0)]);
        $edited = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(8.0)]);

        self::assertNotSame(ServicingFingerprint::of($edited), ServicingFingerprint::review($atDraft, $atDraft));
    }
}
```

- [ ] **Step 2: Run — expect FAIL.** Run: `vendor/bin/phpunit tests/Unit/Servicing/ReviewFingerprintTest.php`

- [ ] **Step 3: Add `review()` to `ServicingFingerprint`** (after `stamp()`)

```php
    /**
     * What a Draft Mode Send compares of() against to tell whether the buyer
     * did anything since the draft: the buyer comments the pass actually
     * SERVICED (so one landing mid-pass still reads as new, for stamp()'s
     * reason), with the state and asks as the LIVE quote holds them once the
     * pass is done — AskMirror writes the buyer's ask onto the line during the
     * pass, so of() right after a draft never equals stamp(), and a Send
     * checked against the stamp would always be stale.
     *
     * ponytail: a buyer editing a requested price in the seconds between
     * AskMirror and this read is credited, not caught; the pass that edit
     * triggers supersedes the draft anyway.
     */
    public static function review(QuoteSnapshot $serviced, QuoteSnapshot $live): string
    {
        return self::compose($live->lifecycle->stateTechnicalName, self::buyerAuthored($serviced), self::asksOf($live));
    }
```

- [ ] **Step 4: Run — expect PASS.**

- [ ] **Step 5: Write the failing store test** `tests/Unit/Audit/DecisionReviewStoreTest.php`

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStore;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

final class DecisionReviewStoreTest extends TestCase
{
    public function testSupersedingMarksPendingRowsAndHandsBackTheirVersions(): void
    {
        $pending = self::record('pending', '0190aaaa0000700080000000000000aa');
        $repository = self::repository([$pending]);

        $versions = (new DecisionReviewStore($repository))->supersedePending('quote-1');

        self::assertSame(['0190aaaa0000700080000000000000aa'], $versions);
        self::assertSame(
            [['id' => $pending->id, 'reviewStatus' => 'superseded', 'draftVersionId' => null]],
            $repository->updates[0],
        );
    }

    public function testSupersedingNothingWritesNothing(): void
    {
        $repository = self::repository([]);

        self::assertSame([], (new DecisionReviewStore($repository))->supersedePending('quote-1'));
        self::assertSame([], $repository->updates);
    }

    public function testSentStoresWhatWasSentAndDropsTheVersion(): void
    {
        $repository = self::repository([]);

        (new DecisionReviewStore($repository))->markSent('rec-1', 'Hello', ['totalNet' => 90.0]);

        $row = $repository->updates[0][0];
        self::assertSame('sent', $row['reviewStatus']);
        self::assertSame('Hello', $row['sentReply']);
        self::assertSame(['totalNet' => 90.0], $row['sentChanges']);
        self::assertNull($row['draftVersionId']);
        self::assertInstanceOf(\DateTimeImmutable::class, $row['reviewedAt']);
    }

    public function testFeedbackStoresNullForAnEmptyHalf(): void
    {
        $repository = self::repository([]);

        (new DecisionReviewStore($repository))->saveFeedback('rec-1', ['wrong_price'], '');

        $row = $repository->updates[0][0];
        self::assertSame(['wrong_price'], $row['feedbackReasons']);
        self::assertNull($row['feedbackComment']);
        self::assertInstanceOf(\DateTimeImmutable::class, $row['feedbackAt']);
    }

    public function testAMalformedIdFindsNothing(): void
    {
        self::assertNull((new DecisionReviewStore(self::repository([])))->find('not-a-uuid'));
    }

    private static function record(string $status, ?string $versionId): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = '0190bbbb0000700080000000000000bb';
        $record->quoteId = 'quote-1';
        $record->reviewStatus = $status;
        $record->draftVersionId = $versionId;

        return $record;
    }

    /** @param list<QuoteDecisionRecord> $records */
    private static function repository(array $records): EntityRepository
    {
        return new class($records) extends EntityRepository {
            /** @var list<list<array<string, mixed>>> */
            public array $updates = [];

            /** @param list<QuoteDecisionRecord> $records */
            public function __construct(
                private readonly array $records,
            ) {}

            #[\Override]
            public function search(Criteria $criteria, Context $context): EntitySearchResult
            {
                $entities = new EntityCollection($this->records);

                return new EntitySearchResult(QuoteDecisionRecord::class, $entities->count(), $entities, null, $criteria, $context);
            }

            /** @param array<int, array<string, mixed>> $data */
            #[\Override]
            public function update(array $data, Context $context): EntityWrittenContainerEvent
            {
                $this->updates[] = array_values($data);

                return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
            }
        };
    }
}
```

If `QuoteDecisionRecord::$quoteId` is typed non-nullable string in the entity, the assignments above are valid; check the property types before running.

- [ ] **Step 6: Run — expect FAIL.**

- [ ] **Step 7: Create the interface and store**

`src/Audit/DecisionReviewStoreInterface.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * Every write a Draft Mode review makes to a decision row after its pass,
 * behind one seam so the pipeline decorator and the review endpoints can be
 * tested without a database. Separate from DecisionRecordWriterInterface for
 * the reason TerminalOutcomeWriterInterface is.
 */
interface DecisionReviewStoreInterface
{
    public function find(string $decisionId): ?QuoteDecisionRecord;

    /**
     * Marks every pending draft of the quote superseded.
     *
     * @return list<string> the draft version ids those rows held, for the caller to delete
     */
    public function supersedePending(string $quoteId): array;

    /** @param array<string, mixed>|null $sentChanges null for a clarification, which changes no price */
    public function markSent(string $decisionId, string $sentReply, ?array $sentChanges): void;

    public function markRejected(string $decisionId): void;

    /** @param list<string> $reasons Review\FeedbackReason values */
    public function saveFeedback(string $decisionId, array $reasons, string $comment): void;
}
```

`src/Audit/DecisionReviewStore.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * System scope throughout, because every field carries
 * Protection(write: [Protection::SYSTEM_SCOPE]) — the same reason
 * TerminalOutcomeWriter uses one. The admin user's own permission is checked
 * by the route's `_acl`, before any of this runs.
 */
final readonly class DecisionReviewStore implements DecisionReviewStoreInterface
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $records */
    public function __construct(
        private EntityRepository $records,
    ) {}

    #[\Override]
    public function find(string $decisionId): ?QuoteDecisionRecord
    {
        if (!Uuid::isValid($decisionId)) {
            return null;
        }

        $record = $this->records->search(new Criteria([$decisionId]), Context::createDefaultContext())->first();

        return $record instanceof QuoteDecisionRecord ? $record : null;
    }

    #[\Override]
    public function supersedePending(string $quoteId): array
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('quoteId', $quoteId),
            new EqualsFilter('reviewStatus', ReviewStatus::Pending->value),
        );

        $payload = [];
        $versionIds = [];

        foreach ($this->records->search($criteria, $context)->getEntities() as $record) {
            if (!$record instanceof QuoteDecisionRecord) {
                continue;
            }

            $payload[] = ['id' => $record->id, 'reviewStatus' => ReviewStatus::Superseded->value, 'draftVersionId' => null];

            if ($record->draftVersionId !== null) {
                $versionIds[] = $record->draftVersionId;
            }
        }

        if ($payload !== []) {
            $this->records->update($payload, $context);
        }

        return $versionIds;
    }

    #[\Override]
    public function markSent(string $decisionId, string $sentReply, ?array $sentChanges): void
    {
        $this->write($decisionId, [
            'reviewStatus' => ReviewStatus::Sent->value,
            'reviewedAt' => new \DateTimeImmutable(),
            'sentReply' => $sentReply,
            'sentChanges' => $sentChanges,
            'draftVersionId' => null,
        ]);
    }

    #[\Override]
    public function markRejected(string $decisionId): void
    {
        $this->write($decisionId, [
            'reviewStatus' => ReviewStatus::Rejected->value,
            'reviewedAt' => new \DateTimeImmutable(),
            'draftVersionId' => null,
        ]);
    }

    #[\Override]
    public function saveFeedback(string $decisionId, array $reasons, string $comment): void
    {
        $this->write($decisionId, [
            'feedbackReasons' => $reasons === [] ? null : $reasons,
            'feedbackComment' => $comment === '' ? null : $comment,
            'feedbackAt' => new \DateTimeImmutable(),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function write(string $decisionId, array $fields): void
    {
        $this->records->update([['id' => $decisionId, ...$fields]], Context::createDefaultContext());
    }
}
```

Check `EntityRepository::search()->first()` exists on `EntitySearchResult` (it does in 6.7: `EntitySearchResult::first()`); if Mago objects, use `->getEntities()->first()`.

- [ ] **Step 8: Wire it** — in `services.php` after the `EscalationResolutionSubscriber` line:

```php
    // Draft Mode's after-the-pass writes: superseded, sent, rejected, feedback.
    $services->set(DecisionReviewStore::class)->args([service('merchant_quote_agent_decision.repository')]);
    $services->alias(DecisionReviewStoreInterface::class, DecisionReviewStore::class);
```

- [ ] **Step 9: Run — expect PASS.** Run: `composer run test && composer run format:check && composer run lint && composer run typecheck`

- [ ] **Step 10: Commit**

```bash
git add src/Servicing/ServicingFingerprint.php src/Audit src/Resources/config/services.php tests/Unit/Servicing/ReviewFingerprintTest.php tests/Unit/Audit/DecisionReviewStoreTest.php
git commit -m "feat(audit): review fingerprint and review store

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The Draft Mode pass

**Files:**
- Create: `src/Review/DraftingQuoteGateway.php`, `src/Review/DraftModePipeline.php`
- Modify: `src/Servicing/AgentDisclosure.php`, `src/Servicing/ServiceQuoteHandler.php:204-213`
- Modify: `src/Policy/Data/QuoteEscalationReason.php`, `src/Servicing/ShopwareEscalationNotifier.php`
- Modify: `src/Resources/config/services.php` (negotiation block)
- Test: `tests/Unit/Review/DraftingQuoteGatewayTest.php`, `tests/Unit/Review/DraftModePipelineTest.php`, `tests/Unit/Review/FakeDraftVersions.php`, `tests/Unit/Review/FakeReviewStore.php`, `tests/Unit/Servicing/AgentDisclosureTest.php`, `tests/Unit/Servicing/EscalationNotificationTest.php`

**Interfaces:**
- Consumes: `QuoteDraftVersionsInterface` (Task 1), `DecisionRecorder::recordDraft()` + `ReviewStatus` (Task 3), `ServicingFingerprint::review()` + `DecisionReviewStoreInterface` (Task 4), `QuoteAgentSettings::$draftMode` (Task 2).
- Produces:
  - `DraftingQuoteGateway(QuoteGatewayInterface $live, QuoteDraftVersionsInterface $versions, DecisionRecorder $recorder, QuoteSnapshot $serviced)` with `versionId(): ?string`.
  - `DraftModePipeline(QuoteServicingPipelineInterface $inner, QuoteDraftVersionsInterface $versions, DecisionRecorder $recorder, DecisionReviewStoreInterface $reviews, EscalationNotifierInterface $notifier)`.
  - `AgentDisclosure::stampFor(NegotiationOutcome $outcome, bool $drafted = false): array`
  - `QuoteEscalationReason::DraftReady = 'draft_ready'`

- [ ] **Step 1: Test doubles**

`tests/Unit/Review/FakeDraftVersions.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

final class FakeDraftVersions implements QuoteDraftVersionsInterface
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $merged = [];

    /** @var list<string> */
    public array $deleted = [];

    public function __construct(
        public readonly FakeQuoteGateway $draft,
    ) {}

    #[\Override]
    public function create(string $quoteId): string
    {
        $id = sprintf('0190aaaa00007000800000000000%04d', \count($this->created) + 1);
        $this->created[] = $id;

        return $id;
    }

    #[\Override]
    public function gateway(string $versionId): QuoteGatewayInterface
    {
        return $this->draft;
    }

    #[\Override]
    public function merge(string $versionId): void
    {
        $this->merged[] = $versionId;
    }

    #[\Override]
    public function delete(string $quoteId, string $versionId): void
    {
        $this->deleted[] = $versionId;
    }
}
```

`tests/Unit/Review/FakeReviewStore.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;

final class FakeReviewStore implements DecisionReviewStoreInterface
{
    /** @var list<string> version ids supersedePending() hands back */
    public array $pendingVersions = [];

    /** @var list<array{string, string, ?array<string, mixed>}> */
    public array $sent = [];

    /** @var list<string> */
    public array $rejected = [];

    /** @var list<array{string, list<string>, string}> */
    public array $feedback = [];

    public ?QuoteDecisionRecord $record = null;

    #[\Override]
    public function find(string $decisionId): ?QuoteDecisionRecord
    {
        return $this->record;
    }

    #[\Override]
    public function supersedePending(string $quoteId): array
    {
        $versions = $this->pendingVersions;
        $this->pendingVersions = [];

        return $versions;
    }

    #[\Override]
    public function markSent(string $decisionId, string $sentReply, ?array $sentChanges): void
    {
        $this->sent[] = [$decisionId, $sentReply, $sentChanges];
    }

    #[\Override]
    public function markRejected(string $decisionId): void
    {
        $this->rejected[] = $decisionId;
    }

    #[\Override]
    public function saveFeedback(string $decisionId, array $reasons, string $comment): void
    {
        $this->feedback[] = [$decisionId, $reasons, $comment];
    }
}
```

- [ ] **Step 2: Write the failing gateway test** `tests/Unit/Review/DraftingQuoteGatewayTest.php`

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteRevisionMismatch;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Review\DraftingQuoteGateway;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DraftingQuoteGatewayTest extends TestCase
{
    public function testPriceWritesGoToTheDraftVersionAndNeverToTheLiveQuote(): void
    {
        [$gateway, $live, $versions] = self::gateway();

        $gateway->updateQuote('q1', new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0), expiresAt: new \DateTimeImmutable('+14 days')));
        $gateway->recalculate('q1');
        $gateway->updateLineItems('q1', [new QuoteLineItemChange('line-1', unitPriceNet: 9.0)]);

        self::assertCount(1, $versions->created, 'One pass, one version.');
        self::assertSame(['updateQuote', 'recalculate', 'updateLineItems'], $versions->draft->calls);
        self::assertNotContains('updateQuote', $live->calls);
        self::assertNotContains('recalculate', $live->calls);
        self::assertSame($versions->created[0], $gateway->versionId());
    }

    public function testBookkeepingAndMirroredAsksStayLive(): void
    {
        [$gateway, $live, $versions] = self::gateway();

        $gateway->updateQuote('q1', new QuoteUpdate(customFields: ['merchant_quote_agent_escalated' => 'x']));
        $gateway->updateLineItems('q1', [new QuoteLineItemChange('line-1', requestedUnitPriceNet: 9.0)]);

        self::assertSame(['updateQuote', 'updateLineItems'], $live->calls);
        self::assertSame([], $versions->created);
    }

    public function testCommentsAndTransitionsReachNobody(): void
    {
        [$gateway, $live, $versions, $recorder, $writer] = self::gateway();

        $gateway->addComment('q1', 'We can offer 5%.');
        $gateway->transition('q1', QuoteTransition::Sent);
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Clarified));

        self::assertSame([], $live->comments);
        self::assertSame([], $live->transitions);
        self::assertSame([], $versions->draft->comments);
        self::assertSame('We can offer 5%.', $writer->drafts[0]->replyToBuyer);
        self::assertSame('pending', $writer->drafts[0]->reviewStatus);
        self::assertNotNull($writer->drafts[0]->reviewFingerprint);
    }

    public function testReadsFollowTheDraftOnceItExists(): void
    {
        [$gateway, $live, $versions] = self::gateway();

        $gateway->fetchSnapshot('q1');
        self::assertSame(['fetchSnapshot'], $live->calls);

        $gateway->updateQuote('q1', new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0)));
        $gateway->fetchSnapshot('q1');

        self::assertSame(['updateQuote', 'fetchSnapshot'], $versions->draft->calls);
    }

    /** The precondition protects the LIVE quote: a buyer edit between the read and the write must still lose. */
    public function testTheRevisionPreconditionIsCheckedAgainstTheLiveQuote(): void
    {
        [$gateway] = self::gateway();

        $this->expectException(QuoteRevisionMismatch::class);

        $gateway->updateQuote(
            'q1',
            new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0)),
            new QuoteRevision('v1', new \DateTimeImmutable('2020-01-01 00:00:00.000')),
        );
    }

    /** @return array{0: DraftingQuoteGateway, 1: FakeQuoteGateway, 2: FakeDraftVersions, 3: DecisionRecorder, 4: FakeDecisionWriter} */
    private static function gateway(): array
    {
        $snapshot = QuoteSnapshotFixture::snapshot(comments: [QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:00.000')]);
        $live = new FakeQuoteGateway([$snapshot]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$snapshot]));
        $writer = new FakeDecisionWriter();
        $recorder = new DecisionRecorder($writer);
        $recorder->begin($snapshot, new PassContext(ServicingTriggerReason::cases()[0], 0));

        return [new DraftingQuoteGateway($live, $versions, $recorder, $snapshot), $live, $versions, $recorder, $writer];
    }
}
```

Note: `testReadsFollowTheDraftOnceItExists` asserts the live gateway's *first* read; `draftFor()` also reads live for the fingerprint, so assert with `assertSame('fetchSnapshot', $live->calls[0])` if the exact list differs.

- [ ] **Step 3: Run — expect FAIL.** Run: `vendor/bin/phpunit tests/Unit/Review`

- [ ] **Step 4: Create `src/Review/DraftingQuoteGateway.php`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteRevisionMismatch;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;

/**
 * The gateway one Draft Mode pass runs against, so the pipeline itself does
 * not change: the same interpretation, the same bands, the same offer
 * applier and verifier, the same reply composer — only where their writes
 * land differs.
 *
 * Routed by what a write IS, not by who calls it:
 *
 * - A price, discount, validity or line change goes into a DAL version of the
 *   quote, opened on the first such write. The verifier then reads the
 *   version back exactly as it reads the live quote today.
 * - A write of only customFields (the markers, the baseline stamp, the
 *   attempt counter) or of only the buyer's requested prices (AskMirror)
 *   stays live: that is bookkeeping and the buyer's own data, not an answer.
 * - A comment is recorded as the draft reply and posted nowhere. A transition
 *   does nothing: `process` and `sent` are what put the quote in front of the
 *   buyer, and the merchant's Send performs them.
 *
 * Per pass: DraftModePipeline builds one for each Draft Mode pass and
 * discards it afterwards.
 */
final class DraftingQuoteGateway implements QuoteGatewayInterface
{
    private ?string $versionId = null;

    private ?QuoteGatewayInterface $draft = null;

    public function __construct(
        private readonly QuoteGatewayInterface $live,
        private readonly QuoteDraftVersionsInterface $versions,
        private readonly DecisionRecorder $recorder,
        private readonly QuoteSnapshot $serviced,
    ) {}

    /** The version this pass opened, or null when it wrote no price. */
    public function versionId(): ?string
    {
        return $this->versionId;
    }

    #[\Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        if ($this->draft !== null && $version === QuoteVersion::Live) {
            return $this->draft->fetchSnapshot($quoteId);
        }

        return $this->live->fetchSnapshot($quoteId, $version);
    }

    /** @param list<QuoteLineItemChange> $changes */
    #[\Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
    {
        if (self::onlyAsks($changes)) {
            $this->live->updateLineItems($quoteId, $changes, $expected);

            return;
        }

        $this->draftFor($quoteId, $expected)->updateLineItems($quoteId, $changes);
    }

    #[\Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        if ($update->discount === null && $update->expiresAt === null) {
            $this->live->updateQuote($quoteId, $update, $expected);

            return;
        }

        $this->draftFor($quoteId, $expected)->updateQuote($quoteId, $update);
    }

    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void
    {
        $this->draftFor($quoteId, null)->addProduct($quoteId, $productId, $quantity);
    }

    #[\Override]
    public function recalculate(string $quoteId): void
    {
        ($this->draft ?? $this->live)->recalculate($quoteId);
    }

    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        $this->recordDraft($quoteId);
        $this->recorder->recordReply($comment, null);
    }

    #[\Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        // Deliberately nothing: see the class docblock.
    }

    private function draftFor(string $quoteId, ?QuoteRevision $expected): QuoteGatewayInterface
    {
        if ($this->draft !== null) {
            return $this->draft;
        }

        // The precondition guards the LIVE quote — a buyer edit between the
        // pass's read and its first write must lose, loudly, exactly as it
        // does outside Draft Mode. The version is fresh, so it has nothing
        // to compare against.
        if ($expected !== null && !$this->live->fetchSnapshot($quoteId)->revision->matches($expected)) {
            throw QuoteRevisionMismatch::forId($quoteId);
        }

        $this->versionId = $this->versions->create($quoteId);
        $this->draft = $this->versions->gateway($this->versionId);
        $this->recordDraft($quoteId);

        return $this->draft;
    }

    private function recordDraft(string $quoteId): void
    {
        $this->recorder->recordDraft(
            $this->versionId,
            ServicingFingerprint::review($this->serviced, $this->live->fetchSnapshot($quoteId)),
        );
    }

    /** @param list<QuoteLineItemChange> $changes */
    private static function onlyAsks(array $changes): bool
    {
        foreach ($changes as $change) {
            if ($change->touchesPrice() || $change->quantity !== null || $change->isRemoval()) {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 5: Run the gateway tests — expect PASS.**

- [ ] **Step 6: Write the failing pipeline test** `tests/Unit/Review/DraftModePipelineTest.php`

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Review\DraftingQuoteGateway;
use MerchantQuoteAgentPlugin\Review\DraftModePipeline;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotice;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotifierInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\ServicingSettingsFixture;
use PHPUnit\Framework\TestCase;

final class DraftModePipelineTest extends TestCase
{
    public function testOutsideDraftModeTheInnerPipelineGetsTheLiveGatewayButPendingDraftsAreStillSuperseded(): void
    {
        $h = self::harness(NegotiationOutcome::Offered);
        $h->reviews->pendingVersions = ['0190aaaa0000700080000000000000aa'];

        $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: false), self::context());

        self::assertSame($h->live, $h->inner->gateway);
        self::assertSame(['0190aaaa0000700080000000000000aa'], $h->versions->deleted);
        self::assertSame([], $h->notifier->notices);
    }

    public function testADraftedOfferNotifiesTheMerchantAndKeepsItsVersion(): void
    {
        $h = self::harness(NegotiationOutcome::Offered, writesAPrice: true);

        $outcome = $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: true), self::context());

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertInstanceOf(DraftingQuoteGateway::class, $h->inner->gateway);
        self::assertSame([], $h->versions->deleted);
        self::assertSame(QuoteEscalationReason::DraftReady, $h->notifier->notices[0]->reason ?? null);
    }

    public function testAnEscalatedDraftPassDeletesItsVersionAndSendsNoDraftNotice(): void
    {
        $h = self::harness(NegotiationOutcome::Escalated, writesAPrice: true);

        $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: true), self::context());

        self::assertSame($h->versions->created, $h->versions->deleted);
        self::assertSame([], $h->notifier->notices);
    }

    public function testAFailingPassDeletesItsVersionAndRethrows(): void
    {
        $h = self::harness(null, writesAPrice: true);

        try {
            $h->pipeline->service($h->snapshot, $h->live, self::settings(draftMode: true), self::context());
            self::fail('The inner failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('inner failed', $e->getMessage());
        }

        self::assertSame($h->versions->created, $h->versions->deleted);
    }

    private static function settings(bool $draftMode): QuoteAgentSettings
    {
        $base = ServicingSettingsFixture::settings();

        return new QuoteAgentSettings($base->policy, $base->llm, null, draftMode: $draftMode);
    }

    private static function context(): PassContext
    {
        return new PassContext(ServicingTriggerReason::cases()[0], 0);
    }

    private static function harness(?NegotiationOutcome $returns, bool $writesAPrice = false): DraftModeHarness
    {
        $snapshot = QuoteSnapshotFixture::snapshot();
        $inner = new RecordingInnerPipeline($returns, $writesAPrice);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$snapshot]));
        $reviews = new FakeReviewStore();
        $notifier = new RecordingNotifier();

        return new DraftModeHarness(
            new DraftModePipeline($inner, $versions, new DecisionRecorder(new FakeDecisionWriter()), $reviews, $notifier),
            $inner,
            new FakeQuoteGateway([$snapshot]),
            $versions,
            $reviews,
            $notifier,
            $snapshot,
        );
    }
}
```

The tests above already read notices as `$h->notifier->notices`.

`tests/Unit/Review/RecordingInnerPipeline.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;

/** Stands in for NegotiationPipeline: records the gateway it was given, optionally writes a price, returns or throws. */
final class RecordingInnerPipeline implements QuoteServicingPipelineInterface
{
    public ?QuoteGatewayInterface $gateway = null;

    public function __construct(
        private readonly ?NegotiationOutcome $returns,
        private readonly bool $writesAPrice,
    ) {}

    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome {
        $this->gateway = $gateway;

        if ($this->writesAPrice) {
            $gateway->updateQuote('q1', new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0)));
        }

        return $this->returns ?? throw new \RuntimeException('inner failed');
    }
}
```

`tests/Unit/Review/RecordingNotifier.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Servicing\EscalationNotice;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotifierInterface;

final class RecordingNotifier implements EscalationNotifierInterface
{
    /** @var list<EscalationNotice> */
    public array $notices = [];

    #[\Override]
    public function notify(EscalationNotice $notice): void
    {
        $this->notices[] = $notice;
    }
}
```

`tests/Unit/Review/DraftModeHarness.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Review\DraftModePipeline;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

/**
 * @mago-expect lint:excessive-parameter-list
 * A plain holder of the seven things DraftModePipelineTest asserts on.
 */
final readonly class DraftModeHarness
{
    public function __construct(
        public DraftModePipeline $pipeline,
        public RecordingInnerPipeline $inner,
        public FakeQuoteGateway $live,
        public FakeDraftVersions $versions,
        public FakeReviewStore $reviews,
        public RecordingNotifier $notifier,
        public QuoteSnapshot $snapshot,
    ) {}
}
```

Drop the now-unused imports (`Discount`, `DiscountType`, `QuoteUpdate`, `QuoteGatewayInterface`, `EscalationNotifierInterface`, `QuoteServicingPipelineInterface`) from `DraftModePipelineTest`.

- [ ] **Step 7: Add the `DraftReady` reason** to `src/Policy/Data/QuoteEscalationReason.php` (last case):

```php
    // Draft Mode. Never recorded on a decision row: it names the NOTICE that a
    // draft is waiting for the merchant, sent through the same channels as an
    // escalation so the seeded escalation flow covers it without a new event.
    case DraftReady = 'draft_ready';
```

- [ ] **Step 8: Create `src/Review/DraftModePipeline.php`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Audit\ReviewStatus;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotice;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotifierInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;

/**
 * Draft Mode around the negotiation pipeline, which stays exactly as it is.
 *
 * Every pass first retires the quote's pending drafts — whatever the buyer
 * did to trigger it made them stale — and deletes their versions, in or out
 * of Draft Mode, so switching the mode off cannot leave a draft to be sent
 * against a conversation that has moved on.
 *
 * In Draft Mode the pass runs against a DraftingQuoteGateway. Afterwards a
 * pass that drafted something for the buyer tells the merchant; any other
 * pass discards the version it opened (a failed verification opens one and
 * then escalates).
 */
final readonly class DraftModePipeline implements QuoteServicingPipelineInterface
{
    public function __construct(
        private QuoteServicingPipelineInterface $inner,
        private QuoteDraftVersionsInterface $versions,
        private DecisionRecorder $recorder,
        private DecisionReviewStoreInterface $reviews,
        private EscalationNotifierInterface $notifier,
    ) {}

    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome {
        $quoteId = $snapshot->identity->quoteId;

        foreach ($this->reviews->supersedePending($quoteId) as $versionId) {
            $this->discard($quoteId, $versionId);
        }

        if (!$settings->draftMode) {
            return $this->inner->service($snapshot, $gateway, $settings, $context);
        }

        $drafting = new DraftingQuoteGateway($gateway, $this->versions, $this->recorder, $snapshot);

        try {
            $outcome = $this->inner->service($snapshot, $drafting, $settings, $context);
        } catch (\Throwable $e) {
            $this->discard($quoteId, $drafting->versionId());

            throw $e;
        }

        if (!ReviewStatus::awaitsReview($outcome)) {
            $this->discard($quoteId, $drafting->versionId());

            return $outcome;
        }

        try {
            $this->notifier->notify(EscalationNotice::of($snapshot, QuoteEscalationReason::DraftReady));
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty, as in QuoteEscalator: the notifier owns its
            // own logging, and the draft is on the list page either way.
        }

        return $outcome;
    }

    private function discard(string $quoteId, ?string $versionId): void
    {
        if ($versionId === null) {
            return;
        }

        try {
            $this->versions->delete($quoteId, $versionId);
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty: a version nobody references is invisible to
            // the buyer and to the merchant, and failing the pass over it
            // would make Messenger redeliver and draft the quote twice.
        }
    }
}
```

- [ ] **Step 9: Disclosure and notifier copy**

`src/Servicing/AgentDisclosure.php` — change the method (and add a paragraph to its docblock: "A drafted pass discloses nothing: a human reviewed and sent what the buyer reads."):

```php
    public static function stampFor(NegotiationOutcome $outcome, bool $drafted = false): array
    {
        if ($drafted) {
            return [];
        }

        return match ($outcome) {
            // ... unchanged ...
        };
    }
```

`src/Servicing/ServiceQuoteHandler.php` — in the final `updateQuote` spread:

```php
            ...AgentDisclosure::stampFor($outcome, $settings->draftMode),
```

`src/Servicing/ShopwareEscalationNotifier.php` — `message()` becomes:

```php
    private static function message(EscalationNotice $notice): string
    {
        if ($notice->reason === QuoteEscalationReason::DraftReady) {
            return sprintf('Quote %s has a draft from the quote agent waiting for your review.', $notice->quoteNumber);
        }

        return sprintf(
            'Quote %s needs a human: the quote agent escalated it (%s).',
            $notice->quoteNumber,
            $notice->reason->value,
        );
    }
```

(add `use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;`). The notification `status` stays `'warning'` for both — the draft does need a human.

Tests to add: in `tests/Unit/Servicing/AgentDisclosureTest.php`

```php
    public function testADraftedPassDisclosesNothing(): void
    {
        self::assertSame([], AgentDisclosure::stampFor(NegotiationOutcome::Offered, drafted: true));
    }
```

and in `tests/Unit/Servicing/EscalationNotificationTest.php`, following its existing harness, a test that a `DraftReady` notice produces a notification message containing `waiting for your review` and not `escalated`.

- [ ] **Step 10: Wire the decorator** in `services.php` — replace

```php
    $services->alias(QuoteServicingPipelineInterface::class, NegotiationPipeline::class);
```

with

```php
    // Draft Mode wraps the pipeline rather than living inside it: see
    // DraftModePipeline. Out of Draft Mode it only retires pending drafts
    // before handing over unchanged.
    $services->set(DraftModePipeline::class)->args([
        service(NegotiationPipeline::class),
        service(QuoteDraftVersionsInterface::class),
        service(DecisionRecorder::class),
        service(DecisionReviewStoreInterface::class),
        service(EscalationNotifierInterface::class),
    ]);
    $services->alias(QuoteServicingPipelineInterface::class, DraftModePipeline::class);
```

- [ ] **Step 11: Run — expect PASS.** Run: `composer run test && composer run format:check && composer run lint && composer run typecheck && composer run quality:depcheck`

- [ ] **Step 12: Commit**

```bash
git add src/Review src/Servicing src/Policy/Data/QuoteEscalationReason.php src/Resources/config/services.php tests/Unit/Review tests/Unit/Servicing
git commit -m "feat(review): Draft Mode pass drafts into a quote version and sends nothing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The A2CN mandate publishes 0 bps auto-grant in Draft Mode

**Files:**
- Modify: `src/Protocol/Mandate/NegotiationBands.php`, `src/Protocol/Mandate/SellerMandateFactory.php`, `src/Protocol/Http/MandateDocumentResponder.php`
- Test: `tests/Unit/Protocol/Mandate/NegotiationBandsTest.php`

**Interfaces:**
- Produces: `NegotiationBands::fromPolicy(NegotiationPolicy $policy, bool $draftMode = false): array`; `SellerMandateFactory::build(..., ?string $currencyIso = null, bool $draftMode = false)`.

- [ ] **Step 1: Failing test** — append to `NegotiationBandsTest`:

```php
    /**
     * In Draft Mode nothing is granted without a human, so the signed mandate
     * must not say the agent grants anything itself. Everything above zero
     * escalates, which is exactly what a draft is.
     */
    public function testDraftModeClaimsNoAutoGrant(): void
    {
        $bands = NegotiationBands::fromPolicy(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 10.0, counterOfferMaxPercent: 20.0)),
            draftMode: true,
        );

        self::assertSame(0, $bands['autoGrantMaxBps']);
        self::assertSame(0, $bands['escalateAboveBps']);
    }
```

- [ ] **Step 2: Run — expect FAIL.** Run: `vendor/bin/phpunit tests/Unit/Protocol/Mandate`

- [ ] **Step 3: Implement.** `NegotiationBands::fromPolicy()`:

```php
    /** @return array<string, mixed> */
    public static function fromPolicy(NegotiationPolicy $policy, bool $draftMode = false): array
    {
        $limits = $policy->price;
        // Draft Mode: a human sends every offer, so the agent grants nothing
        // on its own authority and everything "escalates" to that human.
        $autoGrantMaxBps = $draftMode ? 0 : (int) round($limits->maxDiscountPercent * 100);
        // ... rest unchanged ...
```

`SellerMandateFactory::build()` — add `bool $draftMode = false,` after `?string $currencyIso = null,` and pass it: `'negotiation_bands' => NegotiationBands::fromPolicy($policy, $draftMode),`.

`MandateDocumentResponder::respond()` — add `$settings->draftMode,` as the fifth `build()` argument. Add one line to the responder's docblock: "The mandate is served with a cache header, so a Draft Mode switch reaches buyer agents when their cached copy expires."

- [ ] **Step 4: Run — expect PASS.** Run: `vendor/bin/phpunit tests/Unit/Protocol && composer run format:check && composer run lint && composer run typecheck`

- [ ] **Step 5: Commit**

```bash
git add src/Protocol tests/Unit/Protocol/Mandate/NegotiationBandsTest.php
git commit -m "feat(a2cn): mandate claims no auto-grant in Draft Mode

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Review request DTOs and the pending-draft session

**Files:**
- Create: `src/Review/InvalidReviewRequest.php`, `src/Review/DraftNotReviewable.php`, `src/Review/DecisionNotFound.php`
- Create: `src/Review/FeedbackReason.php`, `src/Review/FeedbackRequest.php`, `src/Review/DraftEdits.php`
- Create: `src/Review/PendingDraft.php`, `src/Review/PendingDrafts.php`, `src/Review/DraftEditor.php`, `src/Review/DraftView.php`
- Test: `tests/Unit/Review/FeedbackRequestTest.php`, `tests/Unit/Review/DraftEditsTest.php`, `tests/Unit/Review/PendingDraftsTest.php`, `tests/Unit/Review/DraftEditorTest.php`, `tests/Unit/Review/DraftViewTest.php`

**Interfaces:**
- Produces:
  - `InvalidReviewRequest::because(string $message, ?\Throwable $previous = null): self` (HTTP 400)
  - `DraftNotReviewable` with `public readonly string $reason` ∈ `not_pending|stale|busy|unavailable`; `::notPending()`, `::stale()`, `::busy()`, `::unavailable()` (HTTP 409)
  - `DecisionNotFound::forId(string)` (HTTP 404)
  - `enum FeedbackReason: string` (six cases)
  - `FeedbackRequest(list<FeedbackReason> $reasons = [], string $comment = '')` with `reasonValues(): list<string>`; `MAX_COMMENT_LENGTH = 2000`
  - `DraftEdits(?float $discountPercent = null, array<string,float> $linePrices = [], ?string $expiresAt = null)` with public `?float $discountPercent`, `array $linePrices`, `?\DateTimeImmutable $expiresAt`, `isEmpty(): bool`
  - `PendingDraft(QuoteDecisionRecord $record, QuoteSnapshot $live, ?QuoteGatewayInterface $draft, bool $stale)` with `draftSnapshot(): QuoteSnapshot`
  - `PendingDrafts::with(string $decisionId, \Closure(PendingDraft): T $work): T`
  - `DraftEditor::apply(PendingDraft $pending, DraftEdits $edits): QuoteSnapshot`
  - `DraftView::of(PendingDraft $pending, QuoteSnapshot $draft, ?string $reply): array<string, mixed>`

- [ ] **Step 1: Failing DTO tests**

`tests/Unit/Review/FeedbackRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use CuyZ\Valinor\Mapper\MappingError;
use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use MerchantQuoteAgentPlugin\Review\FeedbackRequest;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use PHPUnit\Framework\TestCase;

final class FeedbackRequestTest extends TestCase
{
    public function testReasonsAndCommentMapFromTheWire(): void
    {
        $request = ArrayMapper::mapObject(FeedbackRequest::class, [
            'reasons' => ['wrong_price', 'wrong_price', 'other'],
            'comment' => '  Key account, always 10%.  ',
        ]);

        self::assertSame(['wrong_price', 'other'], $request->reasonValues());
        self::assertSame('Key account, always 10%.', $request->comment);
    }

    public function testAnUnknownReasonIsRefused(): void
    {
        $this->expectException(MappingError::class);

        ArrayMapper::mapObject(FeedbackRequest::class, ['reasons' => ['vibes']]);
    }

    public function testEmptyFeedbackIsRefused(): void
    {
        $this->expectException(InvalidReviewRequest::class);

        ArrayMapper::mapObject(FeedbackRequest::class, ['reasons' => [], 'comment' => '   ']);
    }

    public function testACommentOverTheCapIsRefused(): void
    {
        $this->expectException(InvalidReviewRequest::class);

        ArrayMapper::mapObject(FeedbackRequest::class, ['comment' => str_repeat('ä', FeedbackRequest::MAX_COMMENT_LENGTH + 1)]);
    }

    public function testACommentAloneIsEnough(): void
    {
        self::assertSame([], ArrayMapper::mapObject(FeedbackRequest::class, ['comment' => 'Too pushy.'])->reasonValues());
    }
}
```

`tests/Unit/Review/DraftEditsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use PHPUnit\Framework\TestCase;

final class DraftEditsTest extends TestCase
{
    public function testAnEmptyBodyIsNoEdit(): void
    {
        self::assertTrue(ArrayMapper::mapObject(DraftEdits::class, ['reply' => 'x'])->isEmpty());
    }

    public function testEditsMapFromTheWire(): void
    {
        $day = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $edits = ArrayMapper::mapObject(DraftEdits::class, [
            'discountPercent' => 8.0,
            'linePrices' => ['line-1' => 9.5],
            'expiresAt' => $day,
        ]);

        self::assertSame(8.0, $edits->discountPercent);
        self::assertSame(['line-1' => 9.5], $edits->linePrices);
        self::assertSame($day . ' 23:59:59', $edits->expiresAt?->format('Y-m-d H:i:s'));
        self::assertFalse($edits->isEmpty());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refused(): iterable
    {
        yield 'discount above 100' => [['discountPercent' => 120.0]];
        yield 'negative discount' => [['discountPercent' => -1.0]];
        yield 'negative unit price' => [['linePrices' => ['line-1' => -0.01]]];
        yield 'not a day' => [['expiresAt' => '23.09.2026']];
        yield 'a day in the past' => [['expiresAt' => '2020-01-01']];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testOutOfRangeEditsAreRefused(array $body): void
    {
        $this->expectException(InvalidReviewRequest::class);

        ArrayMapper::mapObject(DraftEdits::class, $body);
    }
}
```

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Exceptions and DTOs**

`src/Review/InvalidReviewRequest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/** A review request the merchant can fix: answered 400 with the message, which is merchant-facing copy. */
final class InvalidReviewRequest extends \RuntimeException
{
    public static function because(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }
}
```

`src/Review/DraftNotReviewable.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/** The draft cannot be acted on right now; `$reason` is the machine code the review card switches on. Answered 409. */
final class DraftNotReviewable extends \RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notPending(): self
    {
        return new self('not_pending', 'This draft was already sent, rejected or replaced by a newer one.');
    }

    public static function stale(): self
    {
        return new self('stale', 'The buyer wrote again or changed the quote since this draft was prepared.');
    }

    public static function busy(): self
    {
        return new self('busy', 'The agent is working on this quote right now. Try again in a few seconds.');
    }

    public static function unavailable(): self
    {
        return new self('unavailable', 'Quotes cannot be edited because SwagCommercial is not licensed.');
    }
}
```

`src/Review/DecisionNotFound.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

final class DecisionNotFound extends \RuntimeException
{
    public static function forId(string $decisionId): self
    {
        return new self(sprintf('No agent decision %s exists.', $decisionId));
    }
}
```

`src/Review/FeedbackReason.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/** Why the agent's work was not right. The admin's review.ts FEEDBACK_REASONS mirrors these values. */
enum FeedbackReason: string
{
    case WrongPrice = 'wrong_price';
    case WrongWording = 'wrong_wording';
    case MisunderstoodBuyer = 'misunderstood_buyer';
    case ShouldHaveEscalated = 'should_have_escalated';
    case ShouldNotHaveEscalated = 'should_not_have_escalated';
    case Other = 'other';
}
```

`src/Review/FeedbackRequest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/**
 * The merchant's feedback on one decision, mapped by valinor (types, and the
 * reason vocabulary) and checked here for what types cannot say.
 */
final readonly class FeedbackRequest
{
    public const MAX_COMMENT_LENGTH = 2000;

    /** @var list<FeedbackReason> */
    public array $reasons;

    public string $comment;

    /**
     * @param list<FeedbackReason> $reasons
     *
     * @throws InvalidReviewRequest
     */
    public function __construct(array $reasons = [], string $comment = '')
    {
        $comment = trim($comment);

        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw InvalidReviewRequest::because(sprintf('The comment is longer than %d characters.', self::MAX_COMMENT_LENGTH));
        }

        $unique = [];

        foreach ($reasons as $reason) {
            $unique[$reason->value] = $reason;
        }

        if ($unique === [] && $comment === '') {
            throw InvalidReviewRequest::because('Pick at least one reason or write a comment.');
        }

        $this->reasons = array_values($unique);
        $this->comment = $comment;
    }

    /** @return list<string> */
    public function reasonValues(): array
    {
        return array_map(static fn(FeedbackReason $reason): string => $reason->value, $this->reasons);
    }
}
```

`src/Review/DraftEdits.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/**
 * What the merchant changed in the review card before previewing or sending.
 * Mapped by valinor; the ranges are checked here.
 *
 * No policy ceiling: the caps bind the agent, not the merchant. The card
 * shows a hint above the configured maximum and nothing more.
 */
final readonly class DraftEdits
{
    public ?float $discountPercent;

    /** @var array<string, float> lineItemId => net unit price */
    public array $linePrices;

    public ?\DateTimeImmutable $expiresAt;

    /**
     * @param array<non-empty-string, float> $linePrices
     * @param ?non-empty-string $expiresAt a calendar day, YYYY-MM-DD; valid to its end
     *
     * @throws InvalidReviewRequest
     */
    public function __construct(?float $discountPercent = null, array $linePrices = [], ?string $expiresAt = null)
    {
        if ($discountPercent !== null && ($discountPercent < 0.0 || $discountPercent > 100.0)) {
            throw InvalidReviewRequest::because('The discount must be between 0 and 100 percent.');
        }

        foreach ($linePrices as $price) {
            if ($price < 0.0) {
                throw InvalidReviewRequest::because('A unit price cannot be negative.');
            }
        }

        $this->discountPercent = $discountPercent;
        $this->linePrices = $linePrices;
        $this->expiresAt = $expiresAt === null ? null : self::day($expiresAt);
    }

    public function isEmpty(): bool
    {
        return $this->discountPercent === null && $this->linePrices === [] && $this->expiresAt === null;
    }

    /** @throws InvalidReviewRequest */
    private static function day(string $value): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw InvalidReviewRequest::because('The validity date must be a calendar day, YYYY-MM-DD.');
        }

        $end = $day->setTime(23, 59, 59);

        if ($end < new \DateTimeImmutable()) {
            throw InvalidReviewRequest::because('The validity date is in the past.');
        }

        return $end;
    }
}
```

- [ ] **Step 4: Run the DTO tests — expect PASS.** If valinor maps to properties instead of the constructor (tests show the checks not running), check the installed version's docs for constructor mapping and register the constructor with `->registerConstructor()` in a Review-local mapper — but only after confirming `ArrayMapper` cannot do it; do not change `ArrayMapper`'s shared builder.

- [ ] **Step 5: Failing session/editor/view tests**

`tests/Unit/Review/PendingDraftsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Review\DecisionNotFound;
use MerchantQuoteAgentPlugin\Review\DraftNotReviewable;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Review\PendingDrafts;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PendingDraftsTest extends TestCase
{
    public function testAFreshDraftIsHandedOverNotStaleWithItsDraftGateway(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $store = new FakeReviewStore();
        $store->record = self::record('pending', '0190aaaa0000700080000000000000aa', ServicingFingerprint::of($live));
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$live]));

        $seen = self::drafts($store, $versions, $live)->with('rec', static fn(PendingDraft $d): PendingDraft => $d);

        self::assertFalse($seen->stale);
        self::assertSame($versions->draft, $seen->draft);
    }

    public function testADraftWhoseQuoteMovedOnIsStale(): void
    {
        $live = QuoteSnapshotFixture::snapshot(comments: [QuoteSnapshotFixture::buyerComment('2026-09-23 12:00:00.000')]);
        $store = new FakeReviewStore();
        $store->record = self::record('pending', null, ServicingFingerprint::of(QuoteSnapshotFixture::snapshot()));

        $seen = self::drafts($store, new FakeDraftVersions(new FakeQuoteGateway([$live])), $live)
            ->with('rec', static fn(PendingDraft $d): PendingDraft => $d);

        self::assertTrue($seen->stale);
        self::assertNull($seen->draft);
    }

    public function testASentDraftIsNotReviewable(): void
    {
        $store = new FakeReviewStore();
        $store->record = self::record('sent', null, 'x');

        $this->expectException(DraftNotReviewable::class);

        self::drafts($store, new FakeDraftVersions(new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()])), QuoteSnapshotFixture::snapshot())
            ->with('rec', static fn(PendingDraft $d): bool => true);
    }

    public function testAnUnknownDecisionIsNotFound(): void
    {
        $this->expectException(DecisionNotFound::class);

        self::drafts(new FakeReviewStore(), new FakeDraftVersions(new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()])), QuoteSnapshotFixture::snapshot())
            ->with('rec', static fn(PendingDraft $d): bool => true);
    }

    public function testAQuoteThatIsBeingServicedIsBusy(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $store = new FakeReviewStore();
        $store->record = self::record('pending', null, ServicingFingerprint::of($live));
        $factory = new LockFactory(new InMemoryStore());
        $held = (new QuoteServicingLock($factory, 'flock'))->for('q1');
        self::assertTrue($held->acquire());

        $this->expectException(DraftNotReviewable::class);

        (new PendingDrafts($store, new FakeDraftVersions(new FakeQuoteGateway([$live])), new FakeQuoteGateway([$live]), new QuoteServicingLock($factory, 'flock')))
            ->with('rec', static fn(PendingDraft $d): bool => true);
    }

    private static function drafts(FakeReviewStore $store, FakeDraftVersions $versions, \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $live): PendingDrafts
    {
        return new PendingDrafts(
            $store,
            $versions,
            new FakeQuoteGateway([$live]),
            new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'flock'),
        );
    }

    private static function record(string $status, ?string $versionId, string $fingerprint): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = '0190bbbb0000700080000000000000bb';
        $record->quoteId = 'q1';
        $record->reviewStatus = $status;
        $record->draftVersionId = $versionId;
        $record->reviewFingerprint = $fingerprint;

        return $record;
    }
}
```

`tests/Unit/Review/DraftEditorTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\DraftEditor;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DraftEditorTest extends TestCase
{
    public function testEditsAreWrittenIntoTheDraftAndRecalculated(): void
    {
        $snapshot = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null)]);
        $draft = new FakeQuoteGateway([$snapshot]);

        DraftEditor::apply(self::pending($snapshot, $draft), new DraftEdits(8.0, ['line-1' => 9.5]));

        self::assertSame(['fetchSnapshot', 'updateLineItems', 'updateQuote', 'recalculate', 'fetchSnapshot'], $draft->calls);
        self::assertSame(9.5, $draft->lineItemChanges[0]->unitPriceNet);
        self::assertSame(8.0, $draft->quoteUpdates[0]->discount?->value);
    }

    public function testNoEditOnlyReadsTheDraft(): void
    {
        $snapshot = QuoteSnapshotFixture::snapshot();
        $draft = new FakeQuoteGateway([$snapshot]);

        DraftEditor::apply(self::pending($snapshot, $draft), new DraftEdits());

        self::assertSame(['fetchSnapshot'], $draft->calls);
    }

    public function testALineThatIsNotOnTheQuoteIsRefused(): void
    {
        $snapshot = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null)]);

        $this->expectException(InvalidReviewRequest::class);

        DraftEditor::apply(self::pending($snapshot, new FakeQuoteGateway([$snapshot])), new DraftEdits(linePrices: ['someone-elses-line' => 1.0]));
    }

    public function testAClarificationHasNoPricesToEdit(): void
    {
        $this->expectException(InvalidReviewRequest::class);

        DraftEditor::apply(self::pending(QuoteSnapshotFixture::snapshot(), null), new DraftEdits(5.0));
    }

    private static function pending(\MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $live, ?FakeQuoteGateway $draft): PendingDraft
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';

        return new PendingDraft($record, $live, $draft, false);
    }
}
```

`tests/Unit/Review/DraftViewTest.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Review\DraftView;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class DraftViewTest extends TestCase
{
    public function testAPerLineDraftShowsLiveAndDraftedPrices(): void
    {
        $live = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null, unitPriceNet: 10.0)]);
        $draft = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null, unitPriceNet: 9.0)]);
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';
        $record->outcome = 'offered';
        $record->writes = ['claim', 'updateLineItems', 'updateQuote', 'recalculate'];
        $record->replyToBuyer = 'We can do 9.00 per unit.';
        $record->maxDiscountPercent = 10.0;

        $view = DraftView::of(new PendingDraft($record, $live, new FakeQuoteGateway([$draft]), false), $draft, null);

        self::assertSame('lines', $view['pricing']);
        self::assertSame([['id' => 'line-1', 'label' => 'Widget', 'quantity' => 10, 'live' => 10.0, 'draft' => 9.0]], $view['lines']);
        self::assertSame('We can do 9.00 per unit.', $view['reply']);
        self::assertFalse($view['stale']);
    }

    public function testAClarificationHasNoPricing(): void
    {
        $live = QuoteSnapshotFixture::snapshot();
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';
        $record->outcome = 'clarified';

        self::assertNull(DraftView::of(new PendingDraft($record, $live, null, false), $live, 'Which colour?')['pricing']);
    }
}
```

(`QuoteContent` import is unused in the snippet above — drop it if Mago complains.)

- [ ] **Step 6: Run — expect FAIL.**

- [ ] **Step 7: Implement the session, editor and view**

`src/Review/PendingDraft.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;

/** One pending draft, opened under the quote's lock: its row, the live quote, and the gateway onto its version (null for a clarification). */
final readonly class PendingDraft
{
    public function __construct(
        public QuoteDecisionRecord $record,
        public QuoteSnapshot $live,
        public ?QuoteGatewayInterface $draft,
        public bool $stale,
    ) {}

    public function draftSnapshot(): QuoteSnapshot
    {
        return $this->draft?->fetchSnapshot($this->record->quoteId) ?? $this->live;
    }
}
```

`src/Review/PendingDrafts.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Audit\ReviewStatus;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;

/**
 * Opens a pending draft for one review action, under the SAME per-quote lock
 * a servicing pass holds — so a Send can never interleave with a pass that is
 * about to supersede the draft it sends. Non-blocking: a busy quote is a 409
 * the card retries, not a request that hangs.
 *
 * The row is read again once the lock is held; the read before it only
 * supplies the quote id to lock on.
 */
final readonly class PendingDrafts
{
    public function __construct(
        private DecisionReviewStoreInterface $store,
        private QuoteDraftVersionsInterface $versions,
        private ?QuoteGatewayInterface $live,
        private QuoteServicingLock $locks,
    ) {}

    /**
     * @template T
     *
     * @param \Closure(PendingDraft): T $work
     *
     * @return T
     *
     * @throws DecisionNotFound|DraftNotReviewable
     */
    public function with(string $decisionId, \Closure $work): mixed
    {
        $quoteId = $this->find($decisionId)->quoteId;
        $gateway = $this->live ?? throw DraftNotReviewable::unavailable();
        $lock = $this->locks->for($quoteId);

        if (!$lock->acquire()) {
            throw DraftNotReviewable::busy();
        }

        try {
            $record = $this->find($decisionId);

            if ($record->reviewStatus !== ReviewStatus::Pending->value) {
                throw DraftNotReviewable::notPending();
            }

            $live = $gateway->fetchSnapshot($quoteId);
            $draft = $record->draftVersionId === null ? null : $this->versions->gateway($record->draftVersionId);

            return $work(new PendingDraft($record, $live, $draft, ServicingFingerprint::of($live) !== $record->reviewFingerprint));
        } finally {
            $lock->release();
        }
    }

    /** @throws DecisionNotFound */
    private function find(string $decisionId): QuoteDecisionRecord
    {
        return $this->store->find($decisionId) ?? throw DecisionNotFound::forId($decisionId);
    }
}
```

`src/Review/DraftEditor.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;

/**
 * Writes the merchant's edits into the draft version and recalculates it
 * there, so Preview and Send read the totals Shopware itself computes. The
 * same writers the agent's offer used — only the author differs.
 */
final class DraftEditor
{
    private function __construct() {}

    /**
     * @return QuoteSnapshot the draft as it stands after the edits
     *
     * @throws InvalidReviewRequest
     */
    public static function apply(PendingDraft $pending, DraftEdits $edits): QuoteSnapshot
    {
        $gateway = $pending->draft;
        $quoteId = $pending->record->quoteId;

        if ($gateway === null) {
            if (!$edits->isEmpty()) {
                throw InvalidReviewRequest::because('This draft asks the buyer a question; it has no prices to edit.');
            }

            return $pending->live;
        }

        $before = $gateway->fetchSnapshot($quoteId);

        if ($edits->isEmpty()) {
            return $before;
        }

        self::assertLinesBelong($before, $edits);

        if ($edits->linePrices !== []) {
            $changes = [];

            foreach ($edits->linePrices as $lineItemId => $unitPriceNet) {
                $changes[] = new QuoteLineItemChange((string) $lineItemId, unitPriceNet: $unitPriceNet);
            }

            $gateway->updateLineItems($quoteId, $changes);
        }

        if ($edits->discountPercent !== null || $edits->expiresAt !== null) {
            $gateway->updateQuote($quoteId, new QuoteUpdate(
                discount: $edits->discountPercent === null ? null : new Discount(DiscountType::Percentage, $edits->discountPercent),
                expiresAt: $edits->expiresAt,
            ));
        }

        $gateway->recalculate($quoteId);

        return $gateway->fetchSnapshot($quoteId);
    }

    /** @throws InvalidReviewRequest */
    private static function assertLinesBelong(QuoteSnapshot $draft, DraftEdits $edits): void
    {
        $known = [];

        foreach ($draft->content->lines as $line) {
            $known[$line->identity->lineItemId] = true;
        }

        foreach (array_keys($edits->linePrices) as $lineItemId) {
            if (!isset($known[$lineItemId])) {
                throw InvalidReviewRequest::because('One of the edited lines is not on this quote.');
            }
        }
    }
}
```

`src/Review/DraftView.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * The review card's one read: live against drafted, side by side. `pricing`
 * says which kind of price the agent drafted — per-line or quote-wide — so the
 * card offers the matching inputs; null is a clarification, which has none.
 */
final class DraftView
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(PendingDraft $pending, QuoteSnapshot $draft, ?string $reply): array
    {
        $record = $pending->record;
        $live = $pending->live;

        return [
            'decisionId' => $record->id,
            'outcome' => $record->outcome,
            'stale' => $pending->stale,
            'quoteState' => $live->lifecycle->stateTechnicalName,
            'currencyIso' => $live->identity->currencyIso,
            'maxDiscountPercent' => $record->maxDiscountPercent,
            'pricing' => self::pricing($pending),
            'discountPercent' => ['live' => self::percent($live), 'draft' => self::percent($draft)],
            'lines' => self::lines($live, $draft),
            'totals' => ['live' => self::totals($live), 'draft' => self::totals($draft)],
            'expiresAt' => ['live' => $live->lifecycle->expiresAt?->format('Y-m-d'), 'draft' => $draft->lifecycle->expiresAt?->format('Y-m-d')],
            'reply' => $reply ?? $record->replyToBuyer ?? '',
        ];
    }

    private static function pricing(PendingDraft $pending): ?string
    {
        if ($pending->draft === null) {
            return null;
        }

        return \in_array('updateLineItems', $pending->record->writes ?? [], true) ? 'lines' : 'discount';
    }

    private static function percent(QuoteSnapshot $snapshot): ?float
    {
        $discount = $snapshot->totals->discount;

        return $discount !== null && $discount->type === DiscountType::Percentage ? $discount->value : null;
    }

    /** @return list<array{id: string, label: ?string, quantity: int, live: float, draft: float}> */
    private static function lines(QuoteSnapshot $live, QuoteSnapshot $draft): array
    {
        $drafted = [];

        foreach ($draft->content->lines as $line) {
            $drafted[$line->identity->lineItemId] = $line->unitPriceNet;
        }

        $lines = [];

        foreach ($live->content->lines as $line) {
            $lines[] = [
                'id' => $line->identity->lineItemId,
                'label' => $line->identity->label,
                'quantity' => $line->quantity,
                'live' => $line->unitPriceNet,
                'draft' => $drafted[$line->identity->lineItemId] ?? $line->unitPriceNet,
            ];
        }

        return $lines;
    }

    /** @return array{net: float, gross: ?float} */
    private static function totals(QuoteSnapshot $snapshot): array
    {
        return ['net' => $snapshot->totals->totalNet, 'gross' => $snapshot->totals->totalGross];
    }
}
```

- [ ] **Step 8: Run — expect PASS.** Run: `vendor/bin/phpunit tests/Unit/Review && composer run format:check && composer run lint && composer run typecheck`

- [ ] **Step 9: Commit**

```bash
git add src/Review tests/Unit/Review
git commit -m "feat(review): request DTOs, pending-draft session, editor and view

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Re-drafting the reply, Send, and the review controller

**Files:**
- Modify: `src/Negotiation/ReplyComposer.php` (`reword()` and `transitionFor()` public)
- Create: `src/Review/DraftReply.php`, `src/Review/DraftSender.php`, `src/Review/DraftReviewController.php`
- Modify: `src/Resources/config/services.php` (after the Task 5 block), `src/MerchantQuoteAgentPlugin.php:127-134`
- Test: `tests/Unit/Review/DraftReplyTest.php`, `tests/Unit/Review/DraftSenderTest.php`, `tests/Unit/Review/DraftReviewControllerTest.php`

**Interfaces:**
- Consumes: everything from Task 7; `QuoteGatewayFactory::forContext()` (Task 1); `DecisionReviewStoreInterface` (Task 4).
- Produces:
  - `ReplyComposer::reword(QuoteAgentSettings $settings, QuoteSnapshot $after, string $ask, ?float $reductionPercent): array{0: string, 1: string|null}` (now public)
  - `ReplyComposer::transitionFor(string $state): QuoteTransition` (now public static)
  - `DraftReply::compose(PendingDraft $pending, QuoteSnapshot $after): ?string`
  - `DraftSender::send(PendingDraft $pending, string $reply, DraftEdits $edits, Context $merchant): void`
  - Routes (all `_routeScope: api`):
    - `GET  /api/_action/merchant-quote-agent/decision/{decisionId}/draft` — `_acl: merchant_quote_agent_decision:read` → DraftView
    - `POST /api/_action/merchant-quote-agent/decision/{decisionId}/preview` — `_acl: merchant_quote_agent_decision:update`, body = DraftEdits → DraftView with re-drafted `reply`
    - `POST /api/_action/merchant-quote-agent/decision/{decisionId}/send` — update, body = `{reply: string, ...DraftEdits}` → `{status: 'sent'}`
    - `POST /api/_action/merchant-quote-agent/decision/{decisionId}/reject` — update → `{status: 'rejected'}`
    - `PUT  /api/_action/merchant-quote-agent/decision/{decisionId}/feedback` — update, body = FeedbackRequest → 204
  - Errors: 400 `{code: 'invalid', message}`, 404 `{code: 'not_found', message}`, 409 `{code: <DraftNotReviewable::$reason>, message}`.

- [ ] **Step 1: Make the two `ReplyComposer` methods public.** Change `private function reword(` to `public function reword(` and `private static function transitionFor(` to `public static function transitionFor(`. Add to `reword()`'s docblock: "Public for Review\DraftReply, which re-drafts a reply against a merchant's edited prices through exactly this path, guard and fallback included." Run `vendor/bin/phpunit tests/Unit/Negotiation && composer run lint` — nothing else changes.

- [ ] **Step 2: Failing `DraftReply` test** `tests/Unit/Review/DraftReplyTest.php`

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Review\DraftReply;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DraftReplyTest extends TestCase
{
    public function testTheReplyIsReDraftedAgainstTheEditedTotal(): void
    {
        $live = NegotiationFixture::snapshot(totalNet: 1000.0);
        $after = NegotiationFixture::snapshot(totalNet: 920.0);

        // The model answers with something RewordingGuard rejects, so the
        // deterministic template ships — the figures in it are the point.
        [$client] = ScriptedClient::spy(['not a usable reply']);

        $text = self::reply($client, NegotiationFixture::settings())->compose(self::pending($live, $after), $after);

        self::assertNotNull($text);
        self::assertStringContainsString('8', $text, 'The re-drafted reply does not state the edited reduction.');
    }

    public function testAClarificationIsNotReDrafted(): void
    {
        $live = NegotiationFixture::snapshot();
        [$client] = ScriptedClient::spy([]);

        self::assertNull(self::reply($client, NegotiationFixture::settings())->compose(new PendingDraft(self::record(), $live, null, false), $live));
    }

    public function testNoUsableSettingsMeansNoReDraft(): void
    {
        $live = NegotiationFixture::snapshot(totalNet: 1000.0);
        $after = NegotiationFixture::snapshot(totalNet: 920.0);
        [$client] = ScriptedClient::spy([]);

        self::assertNull(self::reply($client, null)->compose(self::pending($live, $after), $after));
    }

    public function testEditsThatRaiseTheTotalAreRefused(): void
    {
        $live = NegotiationFixture::snapshot(totalNet: 1000.0);
        $after = NegotiationFixture::snapshot(totalNet: 1100.0);
        [$client] = ScriptedClient::spy([]);

        $this->expectException(InvalidReviewRequest::class);

        self::reply($client, NegotiationFixture::settings())->compose(self::pending($live, $after), $after);
    }

    private static function reply(\MerchantQuoteAgentPlugin\Negotiation\ModelPlatform $client, ?QuoteAgentSettings $settings): DraftReply
    {
        $source = new class($settings) implements QuoteAgentSettingsSource {
            public function __construct(
                private readonly ?QuoteAgentSettings $settings,
            ) {}

            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return $this->settings;
            }
        };

        return new DraftReply(
            new ReplyComposer($client, new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}'), new NullLogger(), new DecisionRecorder(new FakeDecisionWriter())),
            $source,
            new NullLogger(),
        );
    }

    private static function pending(\MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $live, \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $after): PendingDraft
    {
        return new PendingDraft(self::record(), $live, new FakeQuoteGateway([$after]), false);
    }

    private static function record(): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';

        return $record;
    }
}
```

Before running, check `NegotiationFixture::snapshot()`'s parameters (state, totalNet, …) and `ScriptedClient::spy()`'s return shape in `tests/Unit/Negotiation` (both are used exactly this way in `ReplyComposerTest`), and adjust the calls if they differ.

- [ ] **Step 3: Implement `src/Review/DraftReply.php`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Negotiation\ReductionForPass;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use Psr\Log\LoggerInterface;

/**
 * Re-drafts the reply after the merchant edited the prices, so the figures in
 * the text match the figures in the quote. The agent's own reply step does
 * it — model rewording, RewordingGuard, template fallback — with the
 * reduction measured exactly as OfferRound measures it.
 */
final readonly class DraftReply
{
    public function __construct(
        private ReplyComposer $composer,
        private QuoteAgentSettingsSource $settings,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return string|null null when there is nothing to re-draft against: a
     *                     clarification, or a channel whose configuration no
     *                     longer resolves — the card keeps the text it has
     *
     * @throws InvalidReviewRequest when the edited prices raise the total
     */
    public function compose(PendingDraft $pending, QuoteSnapshot $after): ?string
    {
        if ($pending->draft === null) {
            return null;
        }

        try {
            $settings = $this->settings->forSalesChannel($pending->live->identity->salesChannelId);
        } catch (InvalidQuoteAgentConfiguration $e) {
            $this->logger->warning('The reply could not be re-drafted: the sales channel configuration is unusable.', [
                'quoteId' => $pending->record->quoteId,
                'exception' => $e,
            ]);

            return null;
        }

        if ($settings === null) {
            return null;
        }

        $granted = abs($after->totals->totalNet - $pending->live->totals->totalNet) > Epsilon::MONEY;
        [$percent, $disagreed] = ReductionForPass::of(
            SnapshotAdapter::anchored($pending->live)->totalNet,
            $after->totals->totalNet,
            $granted,
        );

        if ($disagreed) {
            throw InvalidReviewRequest::because('These prices would raise the total above what the quote shows now.');
        }

        [$text] = $this->composer->reword(
            $settings,
            $after,
            SnapshotAdapter::conversation($pending->live)->newestBuyerText(),
            $percent,
        );

        return $text;
    }
}
```

If `ReductionForPass::of()` treats a raise differently (read its body — it may throw `NegativeReduction` internally and report `$disagreed`), keep this mapping to one 400.

- [ ] **Step 4: Run the DraftReply tests — expect PASS.**

- [ ] **Step 5: Failing `DraftSender` test** `tests/Unit/Review/DraftSenderTest.php`

`DraftSender` takes `ContextBoundGateways` (the interface `QuoteGatewayFactory` implements since Task 1) so tests can inject a fake merchant gateway:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\DraftNotReviewable;
use MerchantQuoteAgentPlugin\Review\DraftSender;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;

final class DraftSenderTest extends TestCase
{
    public function testAnOfferIsMergedClaimedPostedAndMovedToReplied(): void
    {
        $open = QuoteSnapshotFixture::snapshot(state: 'open');
        $inReview = QuoteSnapshotFixture::snapshot(state: 'in_review');
        $merchant = new FakeQuoteGateway([$inReview]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$open]));
        $store = new FakeReviewStore();

        self::sender($versions, $store, $merchant)->send(
            new PendingDraft(self::record('0190aaaa0000700080000000000000aa'), $open, $versions->draft, false),
            ' We can offer 5%. ',
            new DraftEdits(),
            Context::createDefaultContext(),
        );

        self::assertSame(['0190aaaa0000700080000000000000aa'], $versions->merged);
        self::assertSame([QuoteTransition::Process, QuoteTransition::Sent], $merchant->transitions);
        self::assertSame(['We can offer 5%.'], $merchant->comments);
        self::assertSame('We can offer 5%.', $store->sent[0][1]);
        self::assertFalse($store->sent[0][2]['editedByMerchant'] ?? null);
    }

    public function testAClarificationIsOnlyPosted(): void
    {
        $open = QuoteSnapshotFixture::snapshot(state: 'open');
        $merchant = new FakeQuoteGateway([$open]);
        $versions = new FakeDraftVersions(new FakeQuoteGateway([$open]));
        $store = new FakeReviewStore();

        self::sender($versions, $store, $merchant)->send(
            new PendingDraft(self::record(null), $open, null, false),
            'Which colour?',
            new DraftEdits(),
            Context::createDefaultContext(),
        );

        self::assertSame([], $versions->merged);
        self::assertSame([], $merchant->transitions);
        self::assertSame(['Which colour?'], $merchant->comments);
        self::assertNull($store->sent[0][2]);
    }

    public function testAStaleDraftIsNotSent(): void
    {
        $open = QuoteSnapshotFixture::snapshot();
        $merchant = new FakeQuoteGateway([$open]);

        $this->expectException(DraftNotReviewable::class);

        self::sender(new FakeDraftVersions(new FakeQuoteGateway([$open])), new FakeReviewStore(), $merchant)
            ->send(new PendingDraft(self::record(null), $open, null, true), 'x', new DraftEdits(), Context::createDefaultContext());
    }

    public function testAnEmptyReplyIsRefused(): void
    {
        $open = QuoteSnapshotFixture::snapshot();

        $this->expectException(InvalidReviewRequest::class);

        self::sender(new FakeDraftVersions(new FakeQuoteGateway([$open])), new FakeReviewStore(), new FakeQuoteGateway([$open]))
            ->send(new PendingDraft(self::record(null), $open, null, false), '   ', new DraftEdits(), Context::createDefaultContext());
    }

    private static function sender(FakeDraftVersions $versions, FakeReviewStore $store, FakeQuoteGateway $merchant): DraftSender
    {
        $gateways = new class($merchant) implements \MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways {
            public function __construct(
                private readonly FakeQuoteGateway $merchant,
            ) {}

            #[\Override]
            public function forContext(Context $context): FakeQuoteGateway
            {
                return $this->merchant;
            }
        };

        return new DraftSender($versions, $gateways, $store);
    }

    private static function record(?string $versionId): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';
        $record->draftVersionId = $versionId;

        return $record;
    }
}
```

- [ ] **Step 6: Implement `src/Review/DraftSender.php`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use Shopware\Core\Framework\Context;

/**
 * The merchant's Send: the draft's prices onto the live quote, the reply in
 * the buyer's conversation, the quote to `replied` — the same order the agent
 * follows outside Draft Mode, performed as the ADMIN USER, so SwagCommercial
 * records the merchant as author and MerchantHandover sees a human action.
 *
 * No rollback after the merge, the stance OfferApplier takes: a failure
 * afterwards surfaces as an error and the row stays pending, so it is visible
 * rather than papered over.
 */
final readonly class DraftSender
{
    public function __construct(
        private QuoteDraftVersionsInterface $versions,
        private ContextBoundGateways $gateways,
        private DecisionReviewStoreInterface $store,
    ) {}

    /** @throws DraftNotReviewable|InvalidReviewRequest */
    public function send(PendingDraft $pending, string $reply, DraftEdits $edits, Context $merchant): void
    {
        if ($pending->stale) {
            throw DraftNotReviewable::stale();
        }

        $reply = trim($reply);

        if ($reply === '') {
            throw InvalidReviewRequest::because('The reply to the buyer is empty.');
        }

        $gateway = $this->gateways->forContext($merchant) ?? throw DraftNotReviewable::unavailable();
        $quoteId = $pending->record->quoteId;
        $versionId = $pending->record->draftVersionId;
        $sentChanges = null;

        if ($versionId !== null) {
            $sentChanges = self::sentChanges(DraftEditor::apply($pending, $edits), !$edits->isEmpty());
            $this->versions->merge($versionId);

            if ($pending->live->lifecycle->stateTechnicalName === 'open') {
                $gateway->transition($quoteId, QuoteTransition::Process);
            }
        }

        $gateway->addComment($quoteId, $reply);

        if ($versionId !== null) {
            $state = $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName;
            $gateway->transition($quoteId, ReplyComposer::transitionFor($state));
        }

        $this->store->markSent($pending->record->id, $reply, $sentChanges);
    }

    /** @return array<string, mixed> */
    private static function sentChanges(QuoteSnapshot $after, bool $edited): array
    {
        $discount = $after->totals->discount;

        return [
            'discountPercent' => $discount !== null && $discount->type === DiscountType::Percentage ? $discount->value : null,
            'totalNet' => $after->totals->totalNet,
            'totalGross' => $after->totals->totalGross,
            'expiresAt' => $after->lifecycle->expiresAt?->format(\DateTimeInterface::ATOM),
            'editedByMerchant' => $edited,
        ];
    }
}
```

Run the sender tests — expect PASS. If the class exceeds complexity 10, move the `if ($versionId !== null)` blocks into two private methods (`applyPrices()`, `reachReplied()`).

- [ ] **Step 7: Failing controller test** `tests/Unit/Review/DraftReviewControllerTest.php` — cover the HTTP mapping only (the services are tested above):

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Review\DraftReviewController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DraftReviewControllerTest extends TestCase
{
    public function testFeedbackIsSavedAndAnswered204(): void
    {
        $store = new FakeReviewStore();
        $store->record = new QuoteDecisionRecord();

        $response = self::controller($store)->feedback('rec', self::json(['reasons' => ['wrong_price'], 'comment' => 'Too low.']));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([['rec', ['wrong_price'], 'Too low.']], $store->feedback);
    }

    public function testFeedbackOnAnUnknownDecisionIs404(): void
    {
        self::assertSame(404, self::controller(new FakeReviewStore())->feedback('rec', self::json(['comment' => 'x']))->getStatusCode());
    }

    public function testEmptyFeedbackIs400(): void
    {
        $store = new FakeReviewStore();
        $store->record = new QuoteDecisionRecord();

        self::assertSame(400, self::controller($store)->feedback('rec', self::json(['reasons' => []]))->getStatusCode());
    }

    public function testAnUnreadableBodyIs400(): void
    {
        $store = new FakeReviewStore();
        $store->record = new QuoteDecisionRecord();

        self::assertSame(400, self::controller($store)->feedback('rec', new Request(content: '{not json'))->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body): Request
    {
        return new Request(content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private static function controller(FakeReviewStore $store): DraftReviewController
    {
        // Build with the real collaborators over fakes; see Step 8's constructor.
        return DraftReviewControllerFixture::controller($store);
    }
}
```

`tests/Unit/Review/DraftReviewControllerFixture.php`:

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Review\DraftReply;
use MerchantQuoteAgentPlugin\Review\DraftReviewController;
use MerchantQuoteAgentPlugin\Review\DraftSender;
use MerchantQuoteAgentPlugin\Review\PendingDrafts;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** The controller over fakes: the HTTP mapping is what its test is about. */
final class DraftReviewControllerFixture
{
    private function __construct() {}

    public static function controller(FakeReviewStore $store): DraftReviewController
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $versions = new FakeDraftVersions($gateway);
        [$client] = ScriptedClient::spy([]);

        $settings = new class implements QuoteAgentSettingsSource {
            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return null;
            }
        };

        $gateways = new class($gateway) implements ContextBoundGateways {
            public function __construct(
                private readonly FakeQuoteGateway $gateway,
            ) {}

            #[\Override]
            public function forContext(Context $context): FakeQuoteGateway
            {
                return $this->gateway;
            }
        };

        return new DraftReviewController(
            new PendingDrafts($store, $versions, $gateway, new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'flock')),
            new DraftReply(
                new ReplyComposer($client, new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}'), new NullLogger(), new DecisionRecorder(new FakeDecisionWriter())),
                $settings,
                new NullLogger(),
            ),
            new DraftSender($versions, $gateways, $store),
            $store,
            $versions,
        );
    }
}
```

- [ ] **Step 8: Implement `src/Review/DraftReviewController.php`**

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use CuyZ\Valinor\Mapper\MappingError;
use MerchantQuoteAgentPlugin\Audit\DecisionReviewStoreInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The review card's five calls. Reading a draft needs only the viewer's
 * `merchant_quote_agent_decision:read`; everything that changes something
 * needs `:update`, which only the "review drafts" additional permission
 * grants. The record itself stays write-protected: the services behind these
 * routes write in system scope, after the route's ACL has spoken.
 */
final readonly class DraftReviewController
{
    private const BASE = '/api/_action/merchant-quote-agent/decision/{decisionId}';

    public function __construct(
        private PendingDrafts $drafts,
        private DraftReply $reply,
        private DraftSender $sender,
        private DecisionReviewStoreInterface $store,
        private QuoteDraftVersionsInterface $versions,
    ) {}

    #[Route(
        path: self::BASE . '/draft',
        name: 'api.action.merchant_quote_agent.decision_draft',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:read']],
        methods: ['GET'],
    )]
    public function draft(string $decisionId): JsonResponse
    {
        return self::answer(fn(): array => $this->drafts->with(
            $decisionId,
            static fn(PendingDraft $pending): array => DraftView::of($pending, $pending->draftSnapshot(), null),
        ));
    }

    #[Route(
        path: self::BASE . '/preview',
        name: 'api.action.merchant_quote_agent.decision_preview',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:update']],
        methods: ['POST'],
    )]
    public function preview(string $decisionId, Request $request): JsonResponse
    {
        return self::answer(function () use ($decisionId, $request): array {
            $edits = ArrayMapper::mapObject(DraftEdits::class, self::body($request));

            return $this->drafts->with($decisionId, function (PendingDraft $pending) use ($edits): array {
                $after = DraftEditor::apply($pending, $edits);

                return DraftView::of($pending, $after, $this->reply->compose($pending, $after));
            });
        });
    }

    #[Route(
        path: self::BASE . '/send',
        name: 'api.action.merchant_quote_agent.decision_send',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:update']],
        methods: ['POST'],
    )]
    public function send(string $decisionId, Request $request, Context $context): JsonResponse
    {
        return self::answer(function () use ($decisionId, $request, $context): array {
            $body = self::body($request);
            $edits = ArrayMapper::mapObject(DraftEdits::class, $body);
            $reply = \is_string($body['reply'] ?? null) ? $body['reply'] : '';

            $this->drafts->with(
                $decisionId,
                fn(PendingDraft $pending) => $this->sender->send($pending, $reply, $edits, $context),
            );

            return ['status' => 'sent'];
        });
    }

    #[Route(
        path: self::BASE . '/reject',
        name: 'api.action.merchant_quote_agent.decision_reject',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:update']],
        methods: ['POST'],
    )]
    public function reject(string $decisionId): JsonResponse
    {
        return self::answer(function () use ($decisionId): array {
            $this->drafts->with($decisionId, function (PendingDraft $pending): void {
                $versionId = $pending->record->draftVersionId;

                if ($versionId !== null) {
                    $this->versions->delete($pending->record->quoteId, $versionId);
                }

                $this->store->markRejected($pending->record->id);
            });

            return ['status' => 'rejected'];
        });
    }

    #[Route(
        path: self::BASE . '/feedback',
        name: 'api.action.merchant_quote_agent.decision_feedback',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:update']],
        methods: ['PUT'],
    )]
    public function feedback(string $decisionId, Request $request): Response
    {
        $response = self::answer(function () use ($decisionId, $request): array {
            $feedback = ArrayMapper::mapObject(FeedbackRequest::class, self::body($request));

            if ($this->store->find($decisionId) === null) {
                throw DecisionNotFound::forId($decisionId);
            }

            $this->store->saveFeedback($decisionId, $feedback->reasonValues(), $feedback->comment);

            return [];
        });

        return $response->getStatusCode() === 200 ? new Response(status: 204) : $response;
    }

    /** @param \Closure(): array<string, mixed> $work */
    private static function answer(\Closure $work): JsonResponse
    {
        try {
            return new JsonResponse($work());
        } catch (DraftNotReviewable $e) {
            return new JsonResponse(['code' => $e->reason, 'message' => $e->getMessage()], 409);
        } catch (DecisionNotFound $e) {
            return new JsonResponse(['code' => 'not_found', 'message' => $e->getMessage()], 404);
        } catch (InvalidReviewRequest $e) {
            return new JsonResponse(['code' => 'invalid', 'message' => $e->getMessage()], 400);
        } catch (MappingError $e) {
            return new JsonResponse(['code' => 'invalid', 'message' => 'The request does not have the expected shape.'], 400);
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws InvalidReviewRequest
     */
    private static function body(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        try {
            return $request->toArray();
        } catch (JsonException $e) {
            throw InvalidReviewRequest::because('The request body is not a JSON object.', $e);
        }
    }
}
```

If mago flags the controller's class complexity (> 10), move `answer()` and `body()` into a small `final class ReviewResponses` in the same namespace.

- [ ] **Step 9: Wire the services and routes**

`services.php`, after the `DraftModePipeline` block:

```php
    // The review card's endpoints (Draft Mode).
    $services->set(PendingDrafts::class)->args([
        service(DecisionReviewStoreInterface::class),
        service(QuoteDraftVersionsInterface::class),
        service(QuoteGatewayInterface::class)->ignoreOnInvalid(),
        service(QuoteServicingLock::class),
    ]);
    $services->set(DraftReply::class)->args([
        service(ReplyComposer::class),
        service(QuoteAgentSettingsSource::class),
        service('logger'),
    ]);
    $services->set(DraftSender::class)->args([
        service(QuoteDraftVersionsInterface::class),
        service(ContextBoundGateways::class),
        service(DecisionReviewStoreInterface::class),
    ]);
    $services->set(DraftReviewController::class)->args([
        service(PendingDrafts::class),
        service(DraftReply::class),
        service(DraftSender::class),
        service(DecisionReviewStoreInterface::class),
        service(QuoteDraftVersionsInterface::class),
    ])->tag('controller.service_arguments');
```

`src/MerchantQuoteAgentPlugin.php` — `configureRoutes()` becomes:

```php
    public function configureRoutes(RoutingConfigurator $routes, string $environment): void
    {
        parent::configureRoutes($routes, $environment);

        // Draft Mode's review endpoints edit quotes, so they exist exactly
        // where the commercial services behind them are registered.
        if (CommercialAvailability::isRegistered($this->container)) {
            $routes->import($this->getPath() . '/Review/DraftReviewController.php', 'attribute');
        }

        if (UcpAvailability::isRegistered($this->container)) {
            AgentFacingRoutes::import($routes, $this->getPath(), $this->container);
        }
    }
```

(add `use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;`).

- [ ] **Step 10: Run the whole gate.** Run: `composer run test && composer run format:check && composer run lint && composer run typecheck && composer run quality:depcheck`
Expected: green.

- [ ] **Step 11: Commit**

```bash
git add src/Negotiation/ReplyComposer.php src/Review src/Resources/config/services.php src/MerchantQuoteAgentPlugin.php tests/Unit/Review
git commit -m "feat(review): preview, send, reject and feedback endpoints

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: End-to-end on the real shop (both lanes)

**Files:**
- Test: `tests/Integration/DraftModeFlowTest.php`

**Interfaces:**
- Consumes: the container services from Tasks 1–8.

- [ ] **Step 1: Write the integration test.** It drives the pieces in-process (no LLM): a `DraftingQuoteGateway` over the real gateway writes a 10% discount and a reply like `OfferApplier` + `ReplyComposer` would, a `DecisionRecorder` writes the row, then `PendingDrafts` + `DraftSender` send it as an admin user.

```php
<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use MerchantQuoteAgentPlugin\Audit\DecisionReviewStore;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersions;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;
use MerchantQuoteAgentPlugin\Review\DraftEdits;
use MerchantQuoteAgentPlugin\Review\DraftingQuoteGateway;
use MerchantQuoteAgentPlugin\Review\DraftNotReviewable;
use MerchantQuoteAgentPlugin\Review\DraftSender;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Review\PendingDrafts;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

final class DraftModeFlowTest extends IntegrationTestCase
{
    public function testADraftLeavesTheBuyerUntouchedUntilTheMerchantSendsIt(): void
    {
        [$quoteId, $decisionId] = $this->draftAQuote();
        $live = static::gateway()->fetchSnapshot($quoteId);
        self::assertNotContains('We can offer 10%.', array_map(static fn($c) => $c->comment, $live->content->comments));

        $userId = $this->anyAdminUserId();
        $merchant = new Context(new AdminApiSource($userId));

        $this->pendingDrafts()->with($decisionId, fn(PendingDraft $pending) => $this->sender()->send(
            $pending,
            'We can offer 10%.',
            new DraftEdits(),
            $merchant,
        ));

        $after = static::gateway()->fetchSnapshot($quoteId);
        self::assertSame('replied', $after->lifecycle->stateTechnicalName);
        self::assertLessThan($live->totals->totalNet, $after->totals->totalNet);
        $posted = array_values(array_filter($after->content->comments, static fn($c) => $c->comment === 'We can offer 10%.'));
        self::assertCount(1, $posted);
        self::assertSame($userId, $posted[0]->createdById, 'The reply must be the merchant\'s, not the agent\'s.');

        $record = $this->store()->find($decisionId);
        self::assertSame('sent', $record?->reviewStatus);
        self::assertNull($record?->draftVersionId);
    }

    public function testRejectingDiscardsTheVersion(): void
    {
        [$quoteId, $decisionId] = $this->draftAQuote();
        $before = static::gateway()->fetchSnapshot($quoteId);

        $this->pendingDrafts()->with($decisionId, function (PendingDraft $pending): void {
            $this->versions()->delete($pending->record->quoteId, (string) $pending->record->draftVersionId);
            $this->store()->markRejected($pending->record->id);
        });

        self::assertSame($before->totals->totalNet, static::gateway()->fetchSnapshot($quoteId)->totals->totalNet);
        self::assertSame('rejected', $this->store()->find($decisionId)?->reviewStatus);
    }

    public function testABuyerCommentMakesTheDraftStale(): void
    {
        [$quoteId, $decisionId] = $this->draftAQuote();
        $this->addBuyerComment($quoteId);

        $this->expectException(DraftNotReviewable::class);

        $this->pendingDrafts()->with($decisionId, fn(PendingDraft $pending) => $this->sender()->send(
            $pending,
            'x',
            new DraftEdits(),
            Context::createDefaultContext(),
        ));
    }

    /** @return array{string, string} quote id and decision id of a fresh pending offer draft */
    private function draftAQuote(): array
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        // An open quote, so Send has to claim it first.
        // If the fixture quote is not `open`, transition it there first through the admin API
        // or pick one with QuoteFixture's criteria narrowed to 'open'.
        $snapshot = static::gateway()->fetchSnapshot($quoteId);
        $recorder = new DecisionRecorder(new DecisionRecordWriter(static::getContainer()->get('merchant_quote_agent_decision.repository')));
        $recorder->begin($snapshot, new PassContext(ServicingTriggerReason::cases()[0], 0));

        $drafting = new DraftingQuoteGateway(static::gateway(), $this->versions(), $recorder, $snapshot);
        $drafting->transition($quoteId, QuoteTransition::Process);
        $drafting->updateQuote($quoteId, new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 10.0), expiresAt: new \DateTimeImmutable('+14 days')));
        $drafting->recalculate($quoteId);
        $drafting->addComment($quoteId, 'We can offer 10%.');
        $recorder->finish(new NegotiationPass(NegotiationOutcome::Offered));

        $decisionId = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT LOWER(HEX(id)) FROM merchant_quote_agent_decision WHERE quote_id = UNHEX(:q) ORDER BY created_at DESC LIMIT 1',
            ['q' => $quoteId],
        );
        self::assertIsString($decisionId);

        return [$quoteId, $decisionId];
    }

    private function anyAdminUserId(): string
    {
        $id = static::getContainer()->get(Connection::class)->fetchOne('SELECT LOWER(HEX(id)) FROM `user` LIMIT 1');
        self::assertIsString($id);

        return $id;
    }

    private function addBuyerComment(string $quoteId): void
    {
        static::getContainer()->get(Connection::class)->insert('quote_comment', [
            'id' => Uuid::randomBytes(),
            'version_id' => Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION),
            'quote_id' => Uuid::fromHexToBytes($quoteId),
            'quote_version_id' => Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION),
            'comment' => 'Actually, 15%?',
            'customer_id' => static::getContainer()->get(Connection::class)->fetchOne('SELECT customer_id FROM quote WHERE id = UNHEX(:q) AND version_id = UNHEX(:v)', ['q' => $quoteId, 'v' => \Shopware\Core\Defaults::LIVE_VERSION]),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function versions(): QuoteDraftVersions
    {
        return new QuoteDraftVersions(
            static::getContainer()->get('quote.repository'),
            static::getContainer()->get('version.repository'),
            static::gatewayFactory(),
        );
    }

    private function store(): DecisionReviewStore
    {
        return new DecisionReviewStore(static::getContainer()->get('merchant_quote_agent_decision.repository'));
    }

    private function pendingDrafts(): PendingDrafts
    {
        return new PendingDrafts(
            $this->store(),
            $this->versions(),
            static::gateway(),
            static::getContainer()->get(\MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock::class),
        );
    }

    private function sender(): DraftSender
    {
        return new DraftSender($this->versions(), static::gatewayFactory()->forContext(...), $this->store());
    }
}
```

Adjust before running: check `quote_comment`'s real columns on the shop (`SHOW COLUMNS FROM quote_comment`) and the `QuoteFixture` helper for an `open` quote (add `QuoteFixture::openQuoteId()` mirroring `anyQuoteId()` with `EqualsFilter('stateMachineState.technicalName', 'open')` if none exists). If `QuoteServicingLock` is private in the container, construct it with `new QuoteServicingLock(static::getContainer()->get('lock.factory'), 'flock')`.

- [ ] **Step 2: Run on the local 7.13 shop.** Run: `composer run test:integration -- --filter DraftModeFlowTest` — expect 3 passing tests.

- [ ] **Step 3: Run on the 6.7.12 lane** (one ssh invocation, per Task 1 Step 12): first apply the migration there in the same command you sync with, then run the filter. Expect 3 passing tests. On 6.7.12 the renegotiation state is `reopen`, so `ReplyComposer::transitionFor()` picks `admin_resend` if the fixture quote was reopened — the assertion is on `replied` either way.

- [ ] **Step 4: Check what the merge does to the servicing trigger.** `VersionManager::merge()` may replay the version's cloned comment rows onto live under a context that has lost the agent state (see `AgentContext`'s docblock on `createWithVersionId`). If that queues a pass, the pass must end `handed_over` (the merchant's reply is newer than the buyer's ask) and write nothing. Verify once on the local shop after a real Send in Task 11 Step 7: the newest decision row for the quote must be the `sent` draft or a `handed_over` row, never a second draft. If a second draft appears, stop and report — the fix is to mark merge-replayed comment writes as the agent's in `QuoteServicingTrigger`, which needs its own design.

- [ ] **Step 5: Run the full integration suite locally** to catch regressions in the servicing tests now that `QuoteServicingPipelineInterface` is the decorator. Run: `composer run test:integration`. Expected: the same pass/fail set as on `main` (note memory: the configured shop makes `PluginConfigTest` fail by default — compare against a `main` run, do not "fix" that).

- [ ] **Step 6: Commit**

```bash
git add tests/Integration/DraftModeFlowTest.php tests/Integration/QuoteFixture.php
git commit -m "test(review): Draft Mode end to end on the real shop

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Admin vocabulary — `review.ts`, dispositions, ACL, snippets

**Files:**
- Create: `src/Resources/app/administration/src/module/merchant-quote-agent/review.ts`, `review.check.mjs`
- Modify: `…/merchant-quote-agent/decision.ts`, `decision.check.mjs`, `strategy-measures.ts:66`, `page/merchant-quote-agent-list/index.ts` (filter options, `answeredTheBuyer` calls), `page/merchant-quote-agent-detail/index.ts:361`
- Modify: `…/merchant-quote-agent/acl/index.ts`, `…/merchant-quote-agent/index.ts`, `snippet/en.json`, `snippet/de.json`
- Modify: `composer.json` (`quality:admin`)

**Interfaces:**
- Produces (review.ts):
  - `FEEDBACK_REASONS: readonly string[]` (the six PHP values), `FEEDBACK_COMMENT_MAX = 2000`
  - `feedbackPayload(reasons: string[], comment: string): { reasons: string[]; comment: string } | null`
  - `editsPayload(view: DraftView, form: DraftForm): Record<string, unknown>`
  - `wasEdited(view: DraftView, form: DraftForm): boolean`
  - `exceedsCap(discount: number | null, cap: number | null): boolean`
  - `reviewStatusVariant(status: string | null): string`
  - `errorCode(error: any): string | null`
  - `REVIEW_PRIVILEGE = 'merchant_quote_agent_drafts.review'`
- Produces (decision.ts): `answeredTheBuyer(outcome, reviewStatus = null)`; disposition `awaitingReview`; `disposition(outcome, terminalState, resolvedAt, reviewStatus = null)`.

- [ ] **Step 1: Write the failing self-check** `review.check.mjs`

```js
/**
 * Self-check for review.ts. No test runner, like decision.check.mjs.
 *
 *     node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/review.check.mjs
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    FEEDBACK_COMMENT_MAX,
    FEEDBACK_REASONS,
    editsPayload,
    errorCode,
    exceedsCap,
    feedbackPayload,
    reviewStatusVariant,
    wasEdited,
} from './review.ts';

// The reasons mirror Review\FeedbackReason exactly: an admin value the PHP
// enum does not know is a 400 on every save.
const php = readFileSync(new URL('../../../../../../Review/FeedbackReason.php', import.meta.url), 'utf8');
const phpValues = [...php.matchAll(/case \w+ = '([a-z_]+)';/g)].map((match) => match[1]);
assert.deepEqual([...FEEDBACK_REASONS].sort(), phpValues.sort());

assert.equal(feedbackPayload([], '   '), null);
assert.deepEqual(feedbackPayload(['wrong_price', 'vibes'], ' ok '), { reasons: ['wrong_price'], comment: 'ok' });
assert.deepEqual(feedbackPayload([], 'Too pushy'), { reasons: [], comment: 'Too pushy' });
assert.equal(feedbackPayload([], 'x'.repeat(FEEDBACK_COMMENT_MAX + 1)), null);

const view = {
    pricing: 'discount',
    reply: 'We can offer 5%.',
    discountPercent: { live: null, draft: 5 },
    lines: [{ id: 'l1', draft: 9 }],
    expiresAt: { live: null, draft: '2026-10-07' },
};

const untouched = { reply: view.reply, discountPercent: 5, linePrices: { l1: 9 }, expiresAt: '2026-10-07' };
assert.deepEqual(editsPayload(view, untouched), {});
assert.equal(wasEdited(view, untouched), false);

const edited = { ...untouched, discountPercent: 8 };
assert.deepEqual(editsPayload(view, edited), { discountPercent: 8 });
assert.equal(wasEdited(view, edited), true);
assert.equal(wasEdited(view, { ...untouched, reply: 'Something else' }), true);

// A per-line draft sends only the lines that moved, and never a discount.
const lines = { ...view, pricing: 'lines', lines: [{ id: 'l1', draft: 9 }, { id: 'l2', draft: 4 }] };
assert.deepEqual(editsPayload(lines, { ...untouched, discountPercent: 99, linePrices: { l1: 9, l2: 3.5 } }), { linePrices: { l2: 3.5 } });

// A clarification has no prices at all.
assert.deepEqual(editsPayload({ ...view, pricing: null }, { ...untouched, discountPercent: 8 }), {});

assert.equal(exceedsCap(12, 10), true);
assert.equal(exceedsCap(10, 10), false);
assert.equal(exceedsCap(12, null), false);

assert.equal(reviewStatusVariant('pending'), 'attention');
assert.equal(reviewStatusVariant('sent'), 'positive');
assert.equal(reviewStatusVariant('rejected'), 'critical');
assert.equal(reviewStatusVariant('superseded'), 'neutral');
assert.equal(reviewStatusVariant(null), 'neutral');

assert.equal(errorCode({ response: { data: { code: 'stale' } } }), 'stale');
assert.equal(errorCode(new Error('network')), null);

console.log('review.check.mjs: all assertions passed');
```

Check the relative path to `src/Review/FeedbackReason.php` from the module directory (`src/Resources/app/administration/src/module/merchant-quote-agent/` is six levels below `src/`) and fix the `URL` if the read fails.

- [ ] **Step 2: Run — expect FAIL.** Run: `node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/review.check.mjs`

- [ ] **Step 3: Create `review.ts`**

```ts
/**
 * Pure logic for Draft Mode's review card and the feedback modal, kept out of
 * the components so review.check.mjs can pin it without a browser.
 *
 * FEEDBACK_REASONS mirrors Review\FeedbackReason; review.check.mjs reads the
 * PHP enum and fails when the two drift.
 */

export const FEEDBACK_REASONS = [
    'wrong_price',
    'wrong_wording',
    'misunderstood_buyer',
    'should_have_escalated',
    'should_not_have_escalated',
    'other',
] as const;

/** Review\FeedbackRequest::MAX_COMMENT_LENGTH. JS counts UTF-16 units, never fewer than PHP's code points, so this is never looser. */
export const FEEDBACK_COMMENT_MAX = 2000;

/** The additional permission that may send, reject and edit drafts. */
export const REVIEW_PRIVILEGE = 'merchant_quote_agent_drafts.review';

export interface DraftView {
    pricing: 'lines' | 'discount' | null;
    reply: string;
    discountPercent: { live: number | null; draft: number | null };
    lines: Array<{ id: string; draft: number }>;
    expiresAt: { live: string | null; draft: string | null };
}

export interface DraftForm {
    reply: string;
    discountPercent: number | null;
    linePrices: Record<string, number>;
    expiresAt: string | null;
}

const CENT = 0.005;

function same(a: number | null | undefined, b: number | null | undefined): boolean {
    if (a === null || a === undefined || b === null || b === undefined) {
        return a === b;
    }

    return Math.abs(a - b) < CENT;
}

/** null when there is nothing to save or the comment is too long; the save button stays disabled then. */
export function feedbackPayload(reasons: string[], comment: string): { reasons: string[]; comment: string } | null {
    const known = FEEDBACK_REASONS.filter((reason) => reasons.includes(reason));
    const text = comment.trim();

    if ((known.length === 0 && text === '') || text.length > FEEDBACK_COMMENT_MAX) {
        return null;
    }

    return { reasons: [...known], comment: text };
}

/** Only what the merchant changed, and only the kind of price the agent drafted. */
export function editsPayload(view: DraftView, form: DraftForm): Record<string, unknown> {
    const edits: Record<string, unknown> = {};

    if (view.pricing === null) {
        return edits;
    }

    if (view.pricing === 'discount' && form.discountPercent !== null && !same(form.discountPercent, view.discountPercent.draft)) {
        edits.discountPercent = form.discountPercent;
    }

    if (view.pricing === 'lines') {
        const moved = view.lines.filter((line) => !same(form.linePrices[line.id], line.draft));

        if (moved.length > 0) {
            edits.linePrices = Object.fromEntries(moved.map((line) => [line.id, form.linePrices[line.id]]));
        }
    }

    if (form.expiresAt && form.expiresAt !== view.expiresAt.draft) {
        edits.expiresAt = form.expiresAt;
    }

    return edits;
}

export function wasEdited(view: DraftView, form: DraftForm): boolean {
    return form.reply.trim() !== view.reply.trim() || Object.keys(editsPayload(view, form)).length > 0;
}

/** A hint, never a block: the caps bind the agent, not the merchant. */
export function exceedsCap(discount: number | null, cap: number | null): boolean {
    return discount !== null && cap !== null && discount > cap + CENT;
}

const STATUS_VARIANTS: Record<string, string> = {
    pending: 'attention',
    sent: 'positive',
    rejected: 'critical',
    superseded: 'neutral',
};

export function reviewStatusVariant(status: string | null): string {
    return (status && STATUS_VARIANTS[status]) || 'neutral';
}

/** The DraftReviewController's `code`, when the failure came from it. */
export function errorCode(error: any): string | null {
    const code = error?.response?.data?.code;

    return typeof code === 'string' ? code : null;
}
```

- [ ] **Step 4: Run — expect PASS.**

- [ ] **Step 5: Dispositions in `decision.ts`** (TDD: add these assertions to `decision.check.mjs` first, watch them fail, then implement)

```js
// Draft Mode: a pending draft reached nobody, and a rejected one never will.
assert.equal(answeredTheBuyer('offered', 'pending'), false);
assert.equal(answeredTheBuyer('offered', 'rejected'), false);
assert.equal(answeredTheBuyer('offered', 'superseded'), false);
assert.equal(answeredTheBuyer('offered', 'sent'), true);
assert.equal(disposition('offered', null, null, 'pending'), 'awaitingReview');
assert.equal(disposition('clarified', null, null, 'pending'), 'awaitingReview');
assert.equal(disposition('offered', null, null, 'rejected'), 'needsReview');
assert.equal(disposition('offered', 'accepted', null, 'pending'), 'orderPlaced');
assert.equal(dispositionVariant('awaitingReview'), 'attention');
assert.ok(DISPOSITION_CLASSES.includes('awaitingReview'));

// Any drafted pass needed a human, so it counts against auto-execution.
const drafted = foldToQuotes([{ quoteId: 'q', outcome: 'offered', reviewStatus: 'sent', totalNetBefore: 100 }]);
assert.equal(drafted[0].escalated, true);
```

Implementation in `decision.ts`:

```ts
const NOT_SENT = ['pending', 'rejected', 'superseded'];

export function answeredTheBuyer(outcome: string | null, reviewStatus: string | null = null): boolean {
    return outcome !== null && ANSWERED_OUTCOMES.includes(outcome) && !NOT_SENT.includes(reviewStatus ?? '');
}
```

`DISPOSITION_CLASSES` gains `'awaitingReview'` (after `'needsReview'`); `DISPOSITION_VARIANTS` gains `awaitingReview: 'attention'`; `disposition()` gains a fourth parameter and, after the two terminal-state checks:

```ts
    // Draft Mode: the draft is the merchant's move, not the buyer's.
    if (reviewStatus === 'pending') {
        return 'awaitingReview';
    }

    // Rejected: a human discarded the agent's work and still owes the buyer an answer.
    if (reviewStatus === 'rejected') {
        return 'needsReview';
    }
```

In `foldToQuotes()`: pass `decision.reviewStatus ?? null` as the fourth `disposition()` argument (both call sites; for the `seen` branch use `seen.latest.reviewStatus ?? null`), use `answeredTheBuyer(decision.outcome, decision.reviewStatus ?? null)` for `latestAnswered`, and set `escalated` to `decision.outcome === 'escalated' || Boolean(decision.reviewStatus)` in both branches (update the comment: "a drafted pass needed a human too"). In `strategy-measures.ts:66`, `page/merchant-quote-agent-list/index.ts:607` and `page/merchant-quote-agent-detail/index.ts:361`, pass the pass's `reviewStatus ?? null` as the second argument.

List page `dispositionFilterOptions()`: insert `'awaitingReview'` after `'needsReview'` in the key list.

- [ ] **Step 6: ACL** — in `acl/index.ts` add a second export and register it in the module's `index.ts` next to the existing call:

```ts
/**
 * Sending, rejecting and editing Draft Mode drafts. An additional permission
 * rather than a role on the entry above: that entry's `editor` already means
 * "edit strategies", and Shopware's role keys under `permissions` are fixed.
 * `decision:update` is what the review routes check; the record itself stays
 * write-protected, so this grants nothing through the plain DAL.
 */
export const reviewPrivileges = {
    category: 'additional_permissions',
    parent: null,
    key: 'merchant_quote_agent_drafts',
    roles: {
        review: {
            privileges: ['merchant_quote_agent_decision:read', 'merchant_quote_agent_decision:update'],
            dependencies: ['merchant_quote_agent.viewer'],
        },
    },
};
```

```ts
import { privileges, reviewPrivileges } from './acl';
// ...
Shopware.Service('privileges').addPrivilegeMappingEntry(privileges);
Shopware.Service('privileges').addPrivilegeMappingEntry(reviewPrivileges);
```

- [ ] **Step 7: Snippets** — add to `snippet/en.json` (and the German to `snippet/de.json`) under `merchant-quote-agent`:

```json
"disposition": { "awaitingReview": "Draft awaiting review" },
"review": {
    "title": "Draft awaiting your review",
    "intro": "Draft Mode is on: the agent prepared this but sent nothing. Check it, change what you like, then send it or reject it.",
    "introClarification": "The agent wants to ask the buyer a question. Nothing was sent.",
    "changesTitle": "Proposed changes",
    "columnLine": "Line",
    "columnQuantity": "Qty",
    "columnNow": "Now",
    "columnDraft": "Draft",
    "discount": "Quote discount (%)",
    "validUntil": "Valid until",
    "totalNet": "Total (net)",
    "totalGross": "Total (gross)",
    "overCap": "Above your configured maximum of {cap}. The agent could not have offered this — you can.",
    "updatePreview": "Update preview",
    "replyLabel": "Reply to the buyer",
    "useRedraft": "Use the re-drafted reply",
    "send": "Send to buyer",
    "reject": "Reject draft",
    "sent": "Sent to the buyer.",
    "rejected": "Draft rejected. Handle the quote in SwagCommercial.",
    "readOnly": "You can see this draft but not send or reject it. Ask for the “Quote agent: review drafts” permission.",
    "error": {
        "stale": "The buyer wrote again or the quote changed since this draft. Reject it — the agent drafts again on the buyer's next message.",
        "not_pending": "This draft was already handled or replaced.",
        "busy": "The agent is working on this quote right now. Try again in a few seconds.",
        "unavailable": "Quotes cannot be edited because SwagCommercial is not licensed.",
        "generic": "That did not work. Try again."
    },
    "status": {
        "pending": "Draft — awaiting review",
        "sent": "Draft sent by a merchant",
        "rejected": "Draft rejected",
        "superseded": "Draft replaced by a newer one"
    },
    "sentReplyLabel": "Sent instead"
},
"feedback": {
    "open": "Give feedback",
    "edit": "Edit feedback",
    "title": "What was not right?",
    "offerAfterEdit": "You changed the draft before sending. Tell us why?",
    "reasons": {
        "wrong_price": "Wrong price or discount",
        "wrong_wording": "Wrong tone or wording",
        "misunderstood_buyer": "Misunderstood the buyer",
        "should_have_escalated": "Should have handed it to a human",
        "should_not_have_escalated": "Should not have handed it to a human",
        "other": "Other"
    },
    "comment": "Comment",
    "commentHelp": "Up to 2,000 characters. Included in the decision export.",
    "save": "Save feedback",
    "saved": "Feedback saved.",
    "failed": "The feedback could not be saved.",
    "savedLabel": "Merchant feedback"
}
```

German (`de.json`), same keys: disposition.awaitingReview "Entwurf wartet auf Prüfung"; review.title "Entwurf wartet auf Ihre Prüfung"; intro "Der Entwurfsmodus ist aktiv: Der Agent hat dies vorbereitet, aber nichts gesendet. Prüfen und anpassen, dann senden oder verwerfen."; introClarification "Der Agent möchte dem Käufer eine Frage stellen. Es wurde nichts gesendet."; changesTitle "Vorgeschlagene Änderungen"; columnLine "Position"; columnQuantity "Menge"; columnNow "Aktuell"; columnDraft "Entwurf"; discount "Angebotsrabatt (%)"; validUntil "Gültig bis"; totalNet "Summe (netto)"; totalGross "Summe (brutto)"; overCap "Über Ihrem konfigurierten Maximum von {cap}. Der Agent hätte das nicht anbieten dürfen – Sie dürfen."; updatePreview "Vorschau aktualisieren"; replyLabel "Antwort an den Käufer"; useRedraft "Neu formulierte Antwort übernehmen"; send "An Käufer senden"; reject "Entwurf verwerfen"; sent "An den Käufer gesendet."; rejected "Entwurf verworfen. Bearbeiten Sie das Angebot in SwagCommercial."; readOnly "Sie sehen diesen Entwurf, dürfen ihn aber nicht senden oder verwerfen. Fragen Sie nach der Berechtigung „Angebotsagent: Entwürfe prüfen“."; error.stale "Der Käufer hat erneut geschrieben oder das Angebot hat sich seit dem Entwurf geändert. Verwerfen Sie ihn – der Agent entwirft bei der nächsten Nachricht neu."; error.not_pending "Dieser Entwurf wurde bereits bearbeitet oder ersetzt."; error.busy "Der Agent bearbeitet dieses Angebot gerade. Bitte in ein paar Sekunden erneut versuchen."; error.unavailable "Angebote können nicht bearbeitet werden, weil SwagCommercial nicht lizenziert ist."; error.generic "Das hat nicht geklappt. Bitte erneut versuchen."; status.pending "Entwurf – wartet auf Prüfung"; status.sent "Entwurf von einem Händler gesendet"; status.rejected "Entwurf verworfen"; status.superseded "Entwurf durch neueren ersetzt"; sentReplyLabel "Stattdessen gesendet"; feedback.open "Feedback geben"; feedback.edit "Feedback bearbeiten"; feedback.title "Was war nicht richtig?"; feedback.offerAfterEdit "Sie haben den Entwurf vor dem Senden geändert. Warum?"; reasons: wrong_price "Falscher Preis oder Rabatt", wrong_wording "Falscher Ton oder Wortlaut", misunderstood_buyer "Käufer missverstanden", should_have_escalated "Hätte an einen Menschen übergeben werden sollen", should_not_have_escalated "Hätte nicht übergeben werden sollen", other "Sonstiges"; comment "Kommentar"; commentHelp "Bis zu 2.000 Zeichen. Wird im Entscheidungsexport mitgeliefert."; save "Feedback speichern"; saved "Feedback gespeichert."; failed "Das Feedback konnte nicht gespeichert werden."; savedLabel "Händler-Feedback".

Also add the permission labels Shopware looks up for additional permissions — `sw-privileges.additional_permissions.merchant_quote_agent_drafts.label` = "Quote agent drafts" / "Angebotsagent-Entwürfe" and `sw-privileges.additional_permissions.merchant_quote_agent_drafts.review` = "Quote agent: review drafts" / "Angebotsagent: Entwürfe prüfen" — as top-level keys in both snippet files (check how core names these keys in `vendor/shopware/administration/.../sw-users-permissions` inside the container before committing, and match it).

- [ ] **Step 8: Add the check to `composer.json`'s `quality:admin`** — insert `node --experimental-strip-types src/Resources/app/administration/src/module/merchant-quote-agent/review.check.mjs && ` after the `decision.check.mjs` entry.

- [ ] **Step 9: Run.** Run: `composer run quality:admin` — all checks pass.

- [ ] **Step 10: Commit**

```bash
git add src/Resources/app/administration composer.json
git commit -m "feat(admin): Draft Mode vocabulary, dispositions and permission

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Review card, feedback modal, page wiring — and look at it

**Files:**
- Create: `…/merchant-quote-agent/component/merchant-quote-agent-draft-review/index.ts`, `merchant-quote-agent-draft-review.html.twig`
- Create: `…/merchant-quote-agent/component/merchant-quote-agent-feedback-modal/index.ts`, `merchant-quote-agent-feedback-modal.html.twig`
- Modify: `…/merchant-quote-agent/index.ts` (imports), `page/merchant-quote-agent-detail/index.ts`, `page/merchant-quote-agent-detail/merchant-quote-agent-detail.html.twig`, `merchant-quote-agent.scss`

**Interfaces:**
- Consumes: review.ts (Task 10), the five routes (Task 8).
- Produces: `<merchant-quote-agent-draft-review :decision-id @reviewed="({ edited }) => …" @rejected="…" />`, `<merchant-quote-agent-feedback-modal :decision-id :reasons :comment :prompt @close @saved />`.

- [ ] **Step 1: Feedback modal** — `component/merchant-quote-agent-feedback-modal/index.ts`

```ts
import template from './merchant-quote-agent-feedback-modal.html.twig';
import { FEEDBACK_REASONS, feedbackPayload } from '../../review.ts';

Shopware.Component.register('merchant-quote-agent-feedback-modal', {
    template,

    inject: ['syncService'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        decisionId: { type: String, required: true },
        reasons: { type: Array, default: () => [] },
        comment: { type: String, default: '' },
        /** A sentence above the form, e.g. why it opened by itself. */
        prompt: { type: String, default: '' },
    },

    emits: ['close', 'saved'],

    data() {
        return {
            selected: [...this.reasons],
            text: this.comment,
            isSaving: false,
        };
    },

    computed: {
        options() {
            return FEEDBACK_REASONS.map((value) => ({
                value,
                label: this.$tc(`merchant-quote-agent.feedback.reasons.${value}`),
            }));
        },

        payload() {
            return feedbackPayload(this.selected, this.text);
        },
    },

    methods: {
        toggle(value: string, on: boolean) {
            this.selected = on ? [...new Set([...this.selected, value])] : this.selected.filter((v: string) => v !== value);
        },

        async save() {
            if (this.payload === null) {
                return;
            }

            this.isSaving = true;

            try {
                await this.syncService.httpClient.put(
                    `_action/merchant-quote-agent/decision/${this.decisionId}/feedback`,
                    this.payload,
                    { headers: this.syncService.getBasicHeaders() },
                );
                this.createNotificationSuccess({ message: this.$tc('merchant-quote-agent.feedback.saved') });
                this.$emit('saved', this.payload);
            } catch (error) {
                this.createNotificationError({ message: this.$tc('merchant-quote-agent.feedback.failed') });
                // eslint-disable-next-line no-console
                console.error('merchant-quote-agent: feedback failed', error);
            } finally {
                this.isSaving = false;
            }
        },
    },
});
```

`merchant-quote-agent-feedback-modal.html.twig`:

```twig
{% block merchant_quote_agent_feedback_modal %}
<sw-modal variant="small" :title="$tc('merchant-quote-agent.feedback.title')" @modal-close="$emit('close')">
    <p v-if="prompt" class="mqa-feedback__prompt">{{ prompt }}</p>

    <fieldset class="mqa-feedback__reasons">
        <legend class="mqa-feedback__legend">{{ $tc('merchant-quote-agent.feedback.title') }}</legend>
        <mt-checkbox
            v-for="option in options"
            :key="option.value"
            :label="option.label"
            :model-value="selected.includes(option.value)"
            @update:model-value="(on) => toggle(option.value, on)"
        />
    </fieldset>

    <mt-textarea
        :label="$tc('merchant-quote-agent.feedback.comment')"
        :help-text="$tc('merchant-quote-agent.feedback.commentHelp')"
        :model-value="text"
        :maxlength="2000"
        @update:model-value="(value) => { text = value; }"
    />

    <template #modal-footer>
        <mt-button size="small" variant="secondary" @click="$emit('close')">
            {{ $tc('global.default.cancel') }}
        </mt-button>
        <mt-button size="small" variant="primary" :disabled="payload === null || isSaving" @click="save">
            {{ $tc('merchant-quote-agent.feedback.save') }}
        </mt-button>
    </template>
</sw-modal>
{% endblock %}
```

- [ ] **Step 2: Review card** — `component/merchant-quote-agent-draft-review/index.ts`

```ts
import template from './merchant-quote-agent-draft-review.html.twig';
import { formatCurrency, formatPercent } from '../../decision.ts';
import { REVIEW_PRIVILEGE, editsPayload, errorCode, exceedsCap, wasEdited } from '../../review.ts';

/**
 * Draft Mode's review card: live against drafted, editable, then Send or
 * Reject. Everything it shows comes from one GET; Preview returns the same
 * shape after writing the edits into the draft version.
 */
Shopware.Component.register('merchant-quote-agent-draft-review', {
    template,

    inject: ['syncService', 'acl'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        decisionId: { type: String, required: true },
    },

    emits: ['reviewed', 'rejected'],

    data() {
        return {
            view: null,
            form: null,
            redraft: null,
            replyTouched: false,
            isLoading: false,
            isSaving: false,
            blockedBy: null,
        };
    },

    computed: {
        base() {
            return `_action/merchant-quote-agent/decision/${this.decisionId}`;
        },

        canReview() {
            return this.acl.can(REVIEW_PRIVILEGE);
        },

        overCap() {
            return this.view?.pricing === 'discount' && exceedsCap(this.form?.discountPercent ?? null, this.view.maxDiscountPercent);
        },

        edited() {
            return this.view !== null && this.form !== null && wasEdited(this.view, this.form);
        },

        blocked() {
            return this.blockedBy !== null || this.view?.stale === true;
        },

        blockedMessage() {
            const code = this.blockedBy ?? (this.view?.stale ? 'stale' : null);

            return code ? this.$tc(`merchant-quote-agent.review.error.${code}`) : '';
        },
    },

    watch: {
        decisionId() {
            this.load();
        },
    },

    created() {
        this.load();
    },

    methods: {
        formatCurrency,
        formatPercent,

        options() {
            return { headers: this.syncService.getBasicHeaders() };
        },

        adopt(view: any) {
            this.view = view;
            this.form = {
                reply: view.reply,
                discountPercent: view.discountPercent.draft,
                linePrices: Object.fromEntries(view.lines.map((line: any) => [line.id, line.draft])),
                expiresAt: view.expiresAt.draft,
            };
            this.replyTouched = false;
            this.redraft = null;
        },

        async load() {
            this.isLoading = true;
            this.blockedBy = null;

            try {
                const response = await this.syncService.httpClient.get(`${this.base}/draft`, this.options());
                this.adopt(response.data);
            } catch (error) {
                this.fail(error);
            } finally {
                this.isLoading = false;
            }
        },

        async preview() {
            this.isSaving = true;

            try {
                const response = await this.syncService.httpClient.post(
                    `${this.base}/preview`,
                    editsPayload(this.view, this.form),
                    this.options(),
                );
                const reply = this.form.reply;
                const touched = this.replyTouched;
                this.adopt(response.data);

                // Never overwrite what the merchant typed; offer the re-draft beside it.
                if (touched) {
                    this.redraft = response.data.reply;
                    this.form.reply = reply;
                    this.replyTouched = true;
                }
            } catch (error) {
                this.fail(error);
            } finally {
                this.isSaving = false;
            }
        },

        useRedraft() {
            this.form.reply = this.redraft;
            this.redraft = null;
            this.replyTouched = false;
        },

        async send() {
            this.isSaving = true;
            const edited = this.edited;

            try {
                await this.syncService.httpClient.post(
                    `${this.base}/send`,
                    { reply: this.form.reply, ...editsPayload(this.view, this.form) },
                    this.options(),
                );
                this.createNotificationSuccess({ message: this.$tc('merchant-quote-agent.review.sent') });
                this.$emit('reviewed', { edited });
            } catch (error) {
                this.fail(error);
            } finally {
                this.isSaving = false;
            }
        },

        async reject() {
            this.isSaving = true;

            try {
                await this.syncService.httpClient.post(`${this.base}/reject`, {}, this.options());
                this.createNotificationInfo({ message: this.$tc('merchant-quote-agent.review.rejected') });
                this.$emit('rejected');
            } catch (error) {
                this.fail(error);
            } finally {
                this.isSaving = false;
            }
        },

        fail(error: any) {
            const code = errorCode(error);

            if (code === 'stale' || code === 'not_pending' || code === 'unavailable') {
                this.blockedBy = code;

                return;
            }

            const message = error?.response?.data?.message;
            this.createNotificationError({
                message: code === 'invalid' && typeof message === 'string'
                    ? message
                    : this.$tc(`merchant-quote-agent.review.error.${code === 'busy' ? 'busy' : 'generic'}`),
            });
        },
    },
});
```

`merchant-quote-agent-draft-review.html.twig`:

```twig
{% block merchant_quote_agent_draft_review %}
<sw-card class="mqa-review" :title="$tc('merchant-quote-agent.review.title')" :is-loading="isLoading">
    <template v-if="view && form">
        <p class="mqa-review__intro">
            {{ view.pricing === null ? $tc('merchant-quote-agent.review.introClarification') : $tc('merchant-quote-agent.review.intro') }}
        </p>

        <mt-banner v-if="blocked" variant="attention">{{ blockedMessage }}</mt-banner>
        <mt-banner v-else-if="!canReview" variant="info">{{ $tc('merchant-quote-agent.review.readOnly') }}</mt-banner>

        <section v-if="view.pricing !== null" class="mqa-review__changes">
            <h3 class="mqa-review__heading">{{ $tc('merchant-quote-agent.review.changesTitle') }}</h3>

            <table v-if="view.pricing === 'lines'" class="mqa-review__lines">
                <thead>
                    <tr>
                        <th scope="col">{{ $tc('merchant-quote-agent.review.columnLine') }}</th>
                        <th scope="col" class="mqa-num">{{ $tc('merchant-quote-agent.review.columnQuantity') }}</th>
                        <th scope="col" class="mqa-num">{{ $tc('merchant-quote-agent.review.columnNow') }}</th>
                        <th scope="col" class="mqa-num">{{ $tc('merchant-quote-agent.review.columnDraft') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in view.lines" :key="line.id">
                        <td>{{ line.label || line.id }}</td>
                        <td class="mqa-num">{{ line.quantity }}</td>
                        <td class="mqa-num">{{ formatCurrency(line.live, view.currencyIso) }}</td>
                        <td class="mqa-num">
                            <mt-number-field
                                size="small"
                                :model-value="form.linePrices[line.id]"
                                :min="0"
                                :digits="2"
                                :disabled="!canReview || blocked || isSaving"
                                :aria-label="line.label || line.id"
                                @update:model-value="(value) => { form.linePrices[line.id] = value; }"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>

            <div v-else class="mqa-review__discount">
                <mt-number-field
                    :label="$tc('merchant-quote-agent.review.discount')"
                    :model-value="form.discountPercent"
                    :min="0"
                    :max="100"
                    :digits="2"
                    :disabled="!canReview || blocked || isSaving"
                    @update:model-value="(value) => { form.discountPercent = value; }"
                />
                <p v-if="overCap" class="mqa-review__hint">
                    {{ $t('merchant-quote-agent.review.overCap', { cap: formatPercent(view.maxDiscountPercent) }) }}
                </p>
            </div>

            <label class="mqa-review__date">
                <span>{{ $tc('merchant-quote-agent.review.validUntil') }}</span>
                <input
                    v-model="form.expiresAt"
                    type="date"
                    :disabled="!canReview || blocked || isSaving"
                >
            </label>

            <dl class="mqa-review__totals">
                <dt>{{ $tc('merchant-quote-agent.review.totalNet') }}</dt>
                <dd class="mqa-num">
                    <s>{{ formatCurrency(view.totals.live.net, view.currencyIso) }}</s>
                    {{ formatCurrency(view.totals.draft.net, view.currencyIso) }}
                </dd>
                <dt>{{ $tc('merchant-quote-agent.review.totalGross') }}</dt>
                <dd class="mqa-num">
                    <s>{{ formatCurrency(view.totals.live.gross, view.currencyIso) }}</s>
                    {{ formatCurrency(view.totals.draft.gross, view.currencyIso) }}
                </dd>
            </dl>

            <mt-button
                v-if="canReview"
                size="small"
                variant="secondary"
                :disabled="blocked || isSaving"
                @click="preview"
            >
                {{ $tc('merchant-quote-agent.review.updatePreview') }}
            </mt-button>
        </section>

        <mt-textarea
            :label="$tc('merchant-quote-agent.review.replyLabel')"
            :model-value="form.reply"
            :disabled="!canReview || blocked || isSaving"
            @update:model-value="(value) => { form.reply = value; replyTouched = true; }"
        />

        <div v-if="redraft" class="mqa-review__redraft">
            <p class="mqa-run__message">{{ redraft }}</p>
            <mt-button size="small" variant="secondary" @click="useRedraft">
                {{ $tc('merchant-quote-agent.review.useRedraft') }}
            </mt-button>
        </div>

        <div v-if="canReview" class="mqa-review__actions">
            <mt-button variant="secondary" :disabled="isSaving || blockedBy === 'not_pending'" @click="reject">
                {{ $tc('merchant-quote-agent.review.reject') }}
            </mt-button>
            <mt-button variant="primary" :disabled="blocked || isSaving || form.reply.trim() === ''" @click="send">
                {{ $tc('merchant-quote-agent.review.send') }}
            </mt-button>
        </div>
    </template>
</sw-card>
{% endblock %}
```

- [ ] **Step 3: Register both components** — in the module `index.ts` add after the strategy-select import:

```ts
import './component/merchant-quote-agent-draft-review';
import './component/merchant-quote-agent-feedback-modal';
```

- [ ] **Step 4: Wire the detail page.** In `page/merchant-quote-agent-detail/index.ts`:

- `data()`: add `feedbackFor: null, feedbackPrompt: ''`.
- `computed`: add

```ts
        /** The newest pass, when it is a draft the merchant still has to act on. */
        pendingDraftId() {
            const last = this.recordRounds[this.recordRounds.length - 1];

            return last?.reviewStatus === 'pending' ? last.id : null;
        },
```

- `methods`: add

```ts
        openFeedback(round, prompt = '') {
            this.feedbackFor = round;
            this.feedbackPrompt = prompt;
        },

        onReviewed({ edited }) {
            const round = this.recordRounds.find((r) => r.id === this.pendingDraftId);
            this.load();

            if (edited && round) {
                this.openFeedback(round, this.$tc('merchant-quote-agent.feedback.offerAfterEdit'));
            }
        },

        onRejected() {
            const round = this.recordRounds.find((r) => r.id === this.pendingDraftId);
            this.load();

            if (round) {
                this.openFeedback(round);
            }
        },

        onFeedbackSaved() {
            this.feedbackFor = null;
            this.load();
        },
```

- `formatRun()`: add to the returned object

```ts
                reviewStatus: round.reviewStatus ?? null,
                reviewStatusLabel: round.reviewStatus ? this.$tc(`merchant-quote-agent.review.status.${round.reviewStatus}`) : null,
                reviewStatusVariant: reviewStatusVariant(round.reviewStatus ?? null),
                sentReply: round.sentReply && round.sentReply !== round.replyToBuyer ? round.sentReply : null,
                feedbackReasons: (round.feedbackReasons ?? []).map((r) => this.$tc(`merchant-quote-agent.feedback.reasons.${r}`)),
                feedbackComment: round.feedbackComment || null,
```

(import `reviewStatusVariant` from `'../../review.ts'`).

In the detail twig:

- Right after the summary card's `{% endblock %}` (before the stream card), add:

```twig
            {% block merchant_quote_agent_detail_draft_review %}
            <merchant-quote-agent-draft-review
                v-if="pendingDraftId"
                :decision-id="pendingDraftId"
                @reviewed="onReviewed"
                @rejected="onRejected"
            />
            {% endblock %}
```

- Inside the run's `<dl>`, after the reply block:

```twig
                                <template v-if="entry.run.reviewStatusLabel">
                                    <dt class="mqa-run__fact-label">{{ $tc('merchant-quote-agent.disposition.awaitingReview') }}</dt>
                                    <dd class="mqa-run__fact-value">
                                        <mt-badge :variant="entry.run.reviewStatusVariant">{{ entry.run.reviewStatusLabel }}</mt-badge>
                                    </dd>
                                </template>

                                <template v-if="entry.run.sentReply">
                                    <dt class="mqa-run__fact-label">{{ $tc('merchant-quote-agent.review.sentReplyLabel') }}</dt>
                                    <dd class="mqa-run__fact-value mqa-run__message">{{ entry.run.sentReply }}</dd>
                                </template>

                                <template v-if="entry.run.feedbackReasons.length || entry.run.feedbackComment">
                                    <dt class="mqa-run__fact-label">{{ $tc('merchant-quote-agent.feedback.savedLabel') }}</dt>
                                    <dd class="mqa-run__fact-value">
                                        <ul v-if="entry.run.feedbackReasons.length" class="mqa-changes">
                                            <li v-for="reason in entry.run.feedbackReasons" :key="reason">{{ reason }}</li>
                                        </ul>
                                        <span v-if="entry.run.feedbackComment" class="mqa-run__message">{{ entry.run.feedbackComment }}</span>
                                    </dd>
                                </template>
```

Use a dedicated label snippet `merchant-quote-agent.review.statusLabel` = "Review" / "Prüfung" for the first `<dt>` instead of reusing the disposition key (add it to both snippet files).

- After the `</dl>` of each non-noop run (before `<details class="mqa-tech">`):

```twig
                            <mt-button
                                v-if="acl.can('merchant_quote_agent_drafts.review')"
                                size="small"
                                variant="secondary"
                                class="mqa-run__feedback"
                                @click="openFeedback(entry.run.raw)"
                            >
                                {{ entry.run.feedbackReasons.length || entry.run.feedbackComment ? $tc('merchant-quote-agent.feedback.edit') : $tc('merchant-quote-agent.feedback.open') }}
                            </mt-button>
```

(add `'acl'` to the page's `inject`.)

- Before `</sw-card-view>`:

```twig
            <merchant-quote-agent-feedback-modal
                v-if="feedbackFor"
                :decision-id="feedbackFor.id"
                :reasons="feedbackFor.feedbackReasons ?? []"
                :comment="feedbackFor.feedbackComment ?? ''"
                :prompt="feedbackPrompt"
                @close="feedbackFor = null"
                @saved="onFeedbackSaved"
            />
```

- [ ] **Step 5: Styles** — append to `merchant-quote-agent.scss`, semantic tokens only:

```scss
.mqa-review {
    &__intro { color: var(--color-text-secondary-default); margin-bottom: 16px; }
    &__heading { font-size: 14px; margin: 16px 0 8px; }
    &__lines { width: 100%; border-collapse: collapse; margin-bottom: 12px;
        th, td { padding: 6px 8px; border-bottom: 1px solid var(--color-border-secondary-default); text-align: left; }
    }
    &__hint { color: var(--color-text-attention-default); margin-top: 4px; }
    &__date { display: flex; flex-direction: column; gap: 4px; margin: 12px 0; max-width: 220px; }
    &__totals { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 12px 0;
        s { color: var(--color-text-secondary-default); margin-right: 8px; }
    }
    &__redraft { background: var(--color-elevation-surface-sunken); padding: 12px; margin: 8px 0; }
    &__actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
}

.mqa-num { text-align: right; font-variant-numeric: tabular-nums; }

.mqa-feedback {
    &__prompt { margin-bottom: 12px; }
    &__reasons { border: 0; padding: 0; margin: 0 0 16px; }
    &__legend { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
}

.mqa-run__feedback { margin: 8px 0; }
```

If `--color-text-attention-default` does not exist in the running admin, use the memory's verified pairs (`positive` / `critical` / `attention` / `accent`) — check in the container before committing.

- [ ] **Step 6: Template syntax check and admin gates.**
Run: `composer run quality:admin && composer run quality:admin:shop`
Expected: node checks pass; vue-tsc + ESLint clean (if `quality:admin:shop` reports unrelated `merchant_quote_agent_*` entity errors, run `docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/console cache:clear'` and re-run — see the note on syncs racing).

- [ ] **Step 7: See it.** Seed a pending draft on the local shop (run `DraftModeFlowTest::draftAQuote` logic via a one-off `bin/console` script, or switch `draftMode` on for the shop's sales channel with `bin/console system:config:set MerchantQuoteAgentPlugin.config.draftMode true` and post a buyer comment through the storefront), then:

```bash
./scripts/sync-to-shop.sh
```

```bash
docker exec merchant-quote-shop sh -c 'cd /var/www/html && bin/console database:migrate MerchantQuoteAgentPlugin --all && bin/console cache:clear && bin/build-administration.sh'
```

Open `http://localhost:8095/admin` (admin / shopware) → Orders › Quote agent → filter "Draft awaiting review" → the quote. Screenshot with playwright-core against the cached Chromium (`~/Library/Caches/ms-playwright/chromium-<n>/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing`, viewport 1440×3400). Check: card shows now vs draft, editing the discount + Update preview changes totals and offers the re-draft, Send posts and the card disappears, Reject opens the feedback modal, feedback shows under the pass, dark theme readable. Send the screenshots to the user.

- [ ] **Step 8: Commit**

```bash
git add src/Resources/app/administration
git commit -m "feat(admin): review card and feedback modal for Draft Mode

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Docs, spec update, full gate

**Files:**
- Modify: `docs/for-merchants.md` (new "Draft Mode" section), `docs/superpowers/specs/2026-09-23-draft-mode-design.md` (record the deviations), `README.md` only if it lists settings

- [ ] **Step 1: Merchant docs** — add a "Draft Mode" section to `docs/for-merchants.md`: what the toggle does, where drafts appear (list filter, detail card), what Send does (merchant is the author; Flow Builder mails as usual), what Reject does, staleness, the "Quote agent: review drafts" permission, that the escalation notice to the buyer is off in Draft Mode, that the A2CN mandate advertises 0% auto-grant (cached copies expire), and the feedback form + where it shows up in the export.

- [ ] **Step 2: Spec** — add a "Deviations recorded during planning" section to the spec with the seven items from this plan's header, and correct the Data table (nine columns, `review_fingerprint`).

- [ ] **Step 3: Full gate.** Run: `composer run quality`
Expected: green. Also `composer run quality:maintainability` for visibility (advisory).

- [ ] **Step 4: Commit**

```bash
git add docs
git commit -m "docs: Draft Mode for merchants; record planning deviations in the spec

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Self-review notes (plan vs spec)

- Setting, per channel, default off → Task 2. Agent behaviour table (offer/counter/clarify/escalate/no-op) → Tasks 5 + 2 (buyer notice). No banner → Task 5. AskMirror unchanged → Task 5 (routing rule). Fingerprint stamped as today → unchanged handler. Merchant notification via escalation channels → Task 5. Superseding → Task 5 (+ deviation 5). A2CN 0 bps → Task 6.
- Preview / Send / Reject / Feedback → Tasks 7–8; stale check → Tasks 4, 7, 8 (+ deviation 2); lock → Task 7; admin-user author → Tasks 1 (forContext), 8, 9.
- Data (columns, protection, eraser) → Task 3. Export classification → Task 3. Docs → Tasks 3, 12.
- Admin list filter/badge, review card, edits, over-cap hint, preview re-draft without overwriting, 409 handling, feedback everywhere, auto-open after reject, offer after edited send, ACL, snippets, review.ts + check → Tasks 10–11.
- Testing: unit per task; integration on both lanes → Tasks 1, 9; admin checks + real page → Task 11.
