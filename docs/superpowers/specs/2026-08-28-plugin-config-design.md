# Plugin config: bands, model access, kill switch

Issue #5. Per-sales-channel configuration in `config.xml`, a typed reader that
turns it into the policy layer's own value objects, and one enforced rollout
control: the kill switch.

Depends on #2 (the policy layer, which supplies every band's shape) and #4
(the servicing loop, which is where the settings are read and enforced).
Feeds #18, which consumes the model access, the strategy prompt and the
rules-only toggle.

## Scope

**In:** `config.xml`; a settings reader; validation with whole-config refusal;
the `enabled` kill switch, enforced; the loud-empty-key rule; a minimal
escalation writer that #18 reuses.

`rulesOnlyMode` sits across the line: its behaviour — decide without calling
the model — is #18's, but #5 must read it, because it is the one state in which
a missing API key is legitimate rather than a misconfiguration.

**Out, deliberately:**

- **Dry-run and customer scoping.** #5 originally listed both. Neither is
  enforceable until #18's pipeline exists — dry-run means "prepare an offer and
  do not write it", and there is no offer to prepare. Shipping the fields inert
  is worse than omitting them: a merchant who ticks "dry run" and watches the
  agent write to a quote anyway has been actively misled. `config.xml` is
  declarative, so adding the fields later needs no migration and costs nothing.
  Scoping additionally needs `customerId` on `QuoteIdentity` plus a
  customer-group lookup, neither of which the bridge reads today.
- **The LLM client, the counter-offer band, decision records.** #18 and #19.
- **Deleting the iframe admin module.** It lives in
  `agentic-commerce-lab/merchant-quote-agent`, not this repository. Verified:
  nothing in `src/` or `tests/` references an iframe, HMAC verification or
  `sw-app-loaded`.

## What already exists

The bands do not need designing. `Policy\Data\NegotiationPolicy` is the shop's
single source of truth for negotiation, it is already nested
(`price` / `delivery` / `payment` / `bundle`), it already has a `fromArray()`
that handles the nesting, and every decider takes it as a parameter.

What it lacks is anyone to populate it. `valueCeiling` and `validityDays` are
genuinely consumed — by `QuoteBandDecider`, `ValueCeilingViolation`,
`CurrencyMismatchViolation` and `ExpirationOfferVerifier` — and simply never
carry a merchant's number. Two fields are read by nothing at all:
`counterOfferMaxPercent`, whose band `PriceBandClassifier` documents as
unreachable and #18 wires, and `replyTone`.

The constraints are declared and unused too: `Range(0, 100)` on both percents
and on `VolumeTier::$discountPercent`, `PositiveOrZero` on the ceiling, the
lead-time minimum and `validityDays`, `Currency` on the ceiling's ISO code,
`Positive` on `VolumeTier::$minQty`. `symfony/validator` is already a
dependency and nothing runs it.

So this issue adds one thing the plugin has never had — a path from
`system_config` to those objects — and adds no dependency to do it.

## Fields

All per sales channel, all in `src/Resources/config/config.xml`, rendered by
Shopware with no frontend code of ours. Sales-channel values fall back to the
global value, which is Shopware's own semantics and the reason a merchant can
configure once and override for a pilot channel.

**Agent**

| Key | Type | Default |
| --- | --- | --- |
| `enabled` | bool | `false` |
| `rulesOnlyMode` | bool | `false` |

`enabled` defaults off. A fresh install that starts answering customers is the
wrong default for a pilot, and the kill switch is the control the whole rollout
story rests on. The onboarding copy has to say so, because an agent that is
silent by design looks identical to one that is broken.

**Model access**

| Key | Type | Default |
| --- | --- | --- |
| `llmApiKey` | password | — |
| `llmBaseUrl` | text | `https://api.openai.com/v1` |
| `negotiationStrategy` | textarea | — |

The base URL is what lets a merchant point at Azure, their own gateway or a
self-hosted model, which is the mitigation for the residual outbound call. The
strategy textarea is stored here and consumed by #18, which appends it to the
verbatim base prompt as a delimited "Merchant strategy" section and hashes the
composition. It can never move a cap; the bands are the guardrail.

To state in the install documentation rather than imply: `config.xml` stores the
key in `system_config`, obscured by the password field but **not encrypted at
rest** — the same posture as every other secret a Shopware plugin holds.

**Price bands** → `QuoteLimits`

| Key | Type | Default |
| --- | --- | --- |
| `maxDiscountPercent` | float | `0` |
| `counterOfferMaxPercent` | float | — |
| `maxQuoteValueNet` | float | — |
| `maxQuoteValueCurrency` | text | — |
| `validityDays` | int | `0` |
| `replyTone` | text | — |

`maxDiscountPercent: 0` means every price ask escalates. Combined with the
unset non-price dimensions below, a configured-but-untouched install escalates
everything, which is the safe direction and the second thing the onboarding
copy has to explain.

`maxQuoteValueCurrency` is a plain ISO text field validated by
`Assert\Currency`, not an entity select. An `sw-entity-single-select` bound to
`currency` yields an entity id, and `QuoteValueCeiling` wants an ISO string, so
the select would buy nicer UX at the price of an id-to-ISO read on every
settings load. Not worth it for a field a merchant sets once.

Every net field is labelled "(net)" in the admin. The policy layer is net
throughout; leaving that implicit is how a ceiling gets compared against the
wrong number.

**Delivery** → `DeliveryPolicy`, blank means escalate

| Key | Type |
| --- | --- |
| `deliveryFreeShippingAboveNet` | float |
| `deliveryMaxShippingWaiverNet` | float |
| `deliveryExpeditedAllowed` | bool |
| `deliveryCommittedLeadTimeDaysMin` | int |

A checkbox cannot express "unset", but it does not need to:
`ExpeditedDecider` reads `$policy->expeditedAllowed ?? false`, so null and
false already mean the same thing.

**Payment** → `PaymentPolicy`

| Key | Type |
| --- | --- |
| `paymentAllowedTerms` | multi-select over `PaymentTerm` |
| `paymentMaxNetDays` | int |
| `paymentMinDepositPercent` | float |

**Bundle** → `BundlePolicy`

| Key | Type |
| --- | --- |
| `bundleVolumeTiers` | textarea, one `minQty:discountPercent` per line |

A textarea because `config.xml` cannot express a repeatable field. This is the
one place the plugin parses free text a merchant typed, so it is the one place
a parse can fail — see below.

## Assembly

New namespace `src/Config/`, four units:

- **`ModelAccess`** — readonly `apiKey`, `baseUrl`.
- **`QuoteAgentSettings`** — readonly `enabled`, `rulesOnly`, `policy`
  (`NegotiationPolicy`), `llm` (`?ModelAccess`), `strategyPrompt` (`?string`).
  Plain PHP with no Shopware in it, so #18 stays Shopware-free while consuming
  it.
- **`VolumeTierParser`** — textarea to `list<VolumeTier>`. Blank lines and
  surrounding whitespace are ignored; anything else that is not
  `<int>:<number>` is a failure, not a skipped line.
- **`QuoteAgentSettingsReader`** — `SystemConfigService` +
  `ValidatorInterface` in, `forSalesChannel(?string): QuoteAgentSettings` out.

The reader's only real work is shaping flat `system_config` keys into the
nested array `NegotiationPolicy::fromArray()` already accepts, and emitting a
sub-policy **only when at least one of its fields is set** — so a blank
delivery section yields `delivery: null` and `DeliveryDecider` answers "no
delivery policy configured" rather than a subtly different per-field reason.

## Invalid configuration

One invalid state, one behaviour, whatever the cause. The reader throws
`InvalidQuoteAgentConfiguration` carrying a list of human-readable problems,
raised for any of:

1. A `ConstraintViolationList` from validating the assembled `NegotiationPolicy`
   — this is why the existing `Assert` attributes are worth running rather than
   re-checking by hand: they already produce `price.maxDiscountPercent: should
   be between 0 and 100`, which is exactly what the error log needs to be
   actionable.
2. A malformed volume-tier line, reported with its line number.
3. `enabled` and not `rulesOnlyMode` and an empty `llmApiKey`. Rules-only is a
   deliberate merchant choice that needs no model, so it is the one state in
   which a missing key is not a misconfiguration. An empty key is never a
   silent fall back to deterministic decisions — that is the exact behaviour
   this issue exists to remove.

**Validation runs only when `enabled` is true.** A disabled agent is silent
about everything, including its own bad config; the merchant switched it off
and does not want it talking. `forSalesChannel()` therefore reads `enabled`
first and returns early.

## Enforcement

Both checks live in `ServiceQuoteHandler::servicePass()`, immediately after
`fetchSnapshot()` and beside the terminal-state gate #27 added — same position,
same shape, same reason: it is the first point that has a sales-channel id.

The trigger is the tempting place and the wrong one. `QuoteServicingTrigger`
performs **no database reads** by design, and neither the comment event nor the
state event carries a sales-channel id. Enforcing there would add a read to
every buyer comment in the request path. The cost of handler-side enforcement
is that a disabled shop still queues one message per trigger and drops it;
that volume equals quote-comment volume, and the queue row is evidence rather
than waste.

To keep `ServiceQuoteHandler` at five constructor parameters — mago's
`excessive-parameter-list` threshold — the reader and the escalator are not
injected separately. One collaborator, **`ServicingPreflight`**, owns both and
answers the handler's actual question:

```
check(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): ?QuoteAgentSettings
```

`null` means "do not service this quote", and the preflight has already done
whatever logging or escalation that case deserves:

| State | Result | Merchant-visible |
| --- | --- | --- |
| `enabled` false | `null` | nothing — debug log only |
| Invalid config | `null` | escalation comment on the quote + `error` log naming the fields |
| Otherwise | the settings | — |

The handler returns **before stamping** in both `null` cases. Nothing was
serviced, and a stamp would suppress the next genuine trigger — the same rule
the null-pipeline branch already follows.

`QuoteAgentSettings` is then passed to the pipeline:

```
QuoteServicingPipelineInterface::service(QuoteSnapshot, QuoteGatewayInterface, QuoteAgentSettings)
```

A third parameter on an interface with no implementations yet, so it is free
now and would not be later.

## Escalation

Nothing in the plugin can currently write an escalation to a quote.
`QuoteDecision::escalate()` produces a *decision*; the actuator is #18's "reply
or escalate". #5 needs a fraction of it, so it builds that fraction properly
rather than inlining a comment:

**`QuoteEscalator`** takes a `QuoteEscalationReason` and writes one agent
comment through `QuoteGatewayInterface::addComment()`. The comment carries
`AgentContext::STATE`, so it does not re-trigger servicing. #18 routes its own
escalations through the same service instead of growing a second path.

`QuoteEscalationReason` gains one case: `NotConfigured`.

**Escalate once per quote per reason.** A misconfigured shop with a talkative
buyer would otherwise get one comment per comment. The escalator records the
reason in `merchant_quote_agent_escalated` on the quote's `customFields` and
skips when it already holds that reason. A successful servicing pass clears it
in the same `updateQuote` that writes the fingerprint stamp and nulls the
attempt counter, so a fixed configuration escalates again if it breaks again. This reuses the marker mechanism
`ServicingFingerprint::MARKER_KEY` and `ServiceQuoteHandler::ATTEMPTS_KEY`
already established, and `QuoteWriter` shallow-merges `customFields`, so it
cannot disturb the A2CN act chain.

## Testing

**Unit**

- `VolumeTierParser`: well-formed lines, blank lines and whitespace, and each
  malformed shape, asserting the line number reaches the message.
- `QuoteAgentSettingsReader` against an array-backed fake `SystemConfigService`:
  a full config maps to the expected `NegotiationPolicy`; a blank sub-policy
  section yields `null` rather than an all-null object; each invalid case
  throws and names its field; `enabled: false` returns early and does **not**
  validate.
- The key rule as its own table: enabled + key, enabled + rules-only + no key,
  enabled + no key, disabled + no key.
- `ServicingPreflight`: the three outcomes above.
- `QuoteEscalator`: writes once, skips on a repeat, and the marker clears.

**Integration, against the live `merchant-quote-shop`**

- **`config.xml` actually loads and Shopware exposes every key.** This is the
  test that earns its place: a malformed `config.xml` fails silently, the
  plugin simply has no settings, and every unit test still passes. Assert each
  key resolves through the real `SystemConfigService`, and that a
  sales-channel override wins over the global value.
- The kill switch end to end: a real buyer comment on a disabled sales channel
  queues a message that the handler drops without touching the quote.
- A misconfigured sales channel escalates: one comment appears, a second
  trigger adds none.

## Done when

- Every band in `NegotiationPolicy` is reachable from the admin, per sales
  channel. `valueCeiling` and `validityDays` carry a merchant's numbers for the
  first time, and `counterOfferMaxPercent` and `replyTone` are stored ready for
  #18 to read.
- The merchant supplies their own API key and base URL; no plugin-owned
  credential and no environment variable remain in the path.
- An empty API key on an enabled, non-rules-only channel escalates the quote
  and logs the reason. It never silently decides deterministically.
- Invalid config escalates rather than half-applying.
- The kill switch stops servicing, silently, per sales channel.
- `QuoteAgentSettings` reaches the pipeline seam, so #18 has its model access,
  strategy prompt and rules-only flag without reading config itself.
