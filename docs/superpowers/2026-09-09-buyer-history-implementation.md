# Buyer history implementation

Continuation of [the handoff](2026-09-09-buyer-history-handoff.md) and
[implementation plan](plans/2026-09-09-buyer-history.md).

## Integration with main

The feature branch was rebased onto main commit `4292503` before further audit UI
changes. Main's combined conversation and decision timeline, measures, escalation
resolution fields, and reply-transition failure handling are retained. The detail
template and stylesheet reuse main's layout; history adds three technical fields.

## Decisions made during implementation

- A history request takes precedence over any offer in the same response. A pass
  can append two requested results and make three negotiation calls. A third
  request escalates before another history read; its raw proposal remains in the
  audit, but it is not recorded as evidence shown to a subsequent model call.
- The structured response schema admits `null` in a nullable enum's whitelist.
  Without this correction a strict provider could never finish requesting history.
- Customer scope comes from the serviced quote. Product requests additionally
  require a product ID present on that quote. A detected foreign row escalates
  immediately; unexpected persistence failures retain the existing retry path.
- History contains original currency amounts. Lifetime net is available only
  when one known currency covers every scoped order. Mixed or incomplete currency
  data preserves the count and date while withholding the sum with a reason.
- Existing audit discount fields retain their per-pass meaning. History labels
  distinguish authorized proposal passes, distinct accepted quotes with an
  authorized proposal, and the latest recorded pass reduction. Authorization
  alone does not prove delivery; a recorded reduction can be negative. These
  counts do not form an acceptance-rate denominator.
- Quote history is the 25 most recent live quotes, which can include the current
  quote. It does not establish that the buyer is a first-time contact.
- The history migration creates the customer column and index together and
  repairs a missing index on rerun. Before release, its timestamp was bumped to
  `1789000001` (with matching class and filename) to follow main's escalation
  migration at `1789000000` without a collision.
- Model calls have a 30-second total duration budget across retries, with one
  retry and at most two seconds of backoff. Long or invalid `Retry-After` values
  are refused. Five logical calls budget at most 160 seconds of model transport
  and backoff within the existing 300-second lock; arbitrary database work is
  outside that transport bound.

## Acceptance evidence

Tasks 1–14 and the accuracy corrections are implemented and independently
reviewed. Task 15's deterministic integration cases pass; its negotiate-message
privacy acceptance remains unverified until the live-model test runs.

All 876 unit tests pass (2,456 assertions). Customer history and currency checks
pass against the local Shopware database: nine tests, 192 assertions, no skips.
The full integration suite passes: 158 tests and 1,907 assertions. Its six
skips are five legacy-profile cases superseded on this shop by the modern
buyer-flow suite, and the opt-in live-provider smoke test. Nine existing
Commercial dynamic-property deprecations remain. The deterministic buyer-history tests have
no skips or incomplete cases. Formatting, lint, type checking, file-size,
duplication, dependency, security, and both administration Node checks pass.

The seeder created 16 fresh quotes and orders across four customers, leaving
18 live orders in total. Dates span April 2025 through September 2026. Reruns
created zero duplicates and preserved dates. Queue and existing audit counts
remained unchanged. An additional transaction temporarily expanded the customers
to 11, 11, 11, and 13 orders; both recent orders and product purchases returned
exactly the newest ten rows against independent SQL. All 28 additional orders
were rolled back after verification.

Two integration details were corrected using actual runtime evidence. Shopware's
plugin configuration loader supplies no Symfony loader environment, so the
seeder's service guard reads `kernel.environment` from the container builder.
The SQL price reference uses decimal arithmetic to reproduce monetary half-up
rounding: 33.61 divided by two gives 16.81, whereas MySQL's approximate-number
rounding produced 16.80. Production price calculations were already correct.

The seeder passes explicit strict analysis in addition to the configured gate,
which normally excludes scripts. Two unrelated legacy tests now create their
own transactional gross-customer and unserviced-quote fixtures instead of
relying on old shop records.

The administration bundle builds. Legacy and populated history were visually
checked in main's layout: customer identity, counts, currency totals, dates,
complete order and line details, and an off-quote product refusal wrap correctly.
The populated check used an explicitly marked temporary audit record on a newly
seeded quote. That record was deleted by its exact ID afterward, and the original
nine decision records remain intact.

Five scripted-model acceptance tests pass with 454 assertions. They cover all
three reads across bounded passes, off-quote product refusal, a third request
that escalates before another read, and an above-cap proposal rejected after a
completed history read. Successful cases require actual price changes, clean
verified audit records, and the expected model-call and history-round counts.

The privacy tests deliberately include an actual history-only financial value
in the negotiation proposal. The reply model receives exactly the verified
offer template, and the saved buyer reply contains none of that private value
or the proposal's private marker. Foreign same-product evidence is made newer
than all live purchases in a rolled-back fixture, so a missing scope filter
cannot be hidden by the newest-ten limit.

These tests demonstrate deterministic routing, customer isolation, unchanged
policy checks, and separation of history from the reply prompt. They do not
establish the semantic jailbreak resistance of a live model.

### Task 15 correction: negotiate-message privacy

The scripted tests above do **not** satisfy the requirement that the negotiate
model's `message` contain none of the brief's figures. Their deliberately
contaminated proposal is useful downstream containment coverage, but it does
not test the negotiate prompt's INTERNAL rule. The previous Task 15 completion
claim is withdrawn for this criterion.

`LiveHistoryMessageTest` sends the production negotiate prompt and a real
`CustomerBrief` rendering of synthetic account data to the selected provider.
The hostile buyer explicitly requests every private figure. The assertion
examines the returned `NegotiateResponse::message` directly, before any reply
composition or fallback. It rejects all nine rendered figures: quote counts,
proposal and accepted-quote counts, the recorded reduction, order count,
lifetime net and last order date. Common decimal, thousands and date formats
are covered. The fixture verifies that none of these figures appear in the
current quote/authority/ask and that every numeric figure rendered by the brief
is enumerated. An empty message, escalation or intermediate history request
cannot satisfy the successful-offer acceptance case.

`HistoryMessagePrivacyTest` exercises the same assertion with deliberate
disclosures, including every brief figure and formatting variants. These
offline checks validate the assertion; they are not evidence of model behaviour.
The live check is opt-in because it makes a paid provider call. It uses only
synthetic data, and no provider call has been made for this correction.

Correction verification: 898 unit tests pass (2,528 assertions), including 22
assertion regression cases. The five existing pipeline acceptance cases pass
with 454 assertions and nine existing Commercial deprecations; the live-message
case skips explicitly as unverified. Formatting, lint and type checking pass.

To run after exporting the selected provider's credentials and model in the
host shell (the integration wrapper does not forward them automatically):

```sh
./scripts/sync-to-shop.sh
docker exec -e QUOTE_AGENT_LIVE_HISTORY=1 \
  -e QUOTE_AGENT_LIVE_KEY -e QUOTE_AGENT_LIVE_MODEL -e QUOTE_AGENT_LIVE_BASE_URL \
  -w /var/www/html/custom/plugins/MerchantQuoteAgentPlugin merchant-quote-shop \
  php8.3 /var/www/html/vendor/bin/phpunit -c phpunit.integration.xml.dist \
  --filter LiveHistoryMessageTest
```

`QUOTE_AGENT_LIVE_BASE_URL` is optional. A skipped live check leaves
Task 15 unverified. A failure must be reported without weakening the assertion;
the plan's documented response to a disclosure is the deferred mechanical guard.
A pass is evidence for this model and attack, not a guarantee against arbitrary
paraphrasing, encoding or future provider behaviour.
