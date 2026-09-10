# Silent Escalation Toggle

Date: 2026-09-10

## Status

Approved.

## Context

When a quote requires human intervention (e.g. discount ask above configured ceilings, model failures, structural changes, or non-price asks), `QuoteEscalator` currently posts a customer-facing comment on the quote in the storefront:
> "A member of our team will review this quote personally and get back to you."

Merchants using an automated agent may not want it to be obvious to the buyer that an AI agent is servicing the quote. Posting an immediate boilerplate escalation comment signals automation. Merchants requested a configuration toggle to control whether the buyer receives an automated comment when a quote escalates to a human, or whether it escalates completely silently from the buyer's perspective.

## Decisions

1. **New configuration setting in `config.xml`**:
   - Key: `notifyBuyerOnEscalation`
   - Type: `bool`
   - Default: `false` (silent by default, per merchant preference)
   - Scope: Per sales channel, falling back to global configuration.
   - Placement: In a dedicated `<card><title>Escalation</title></card>` section in `src/Resources/config/config.xml`.

2. **Configuration reading**:
   - `QuoteAgentSettingsReader::KEYS` includes `'notifyBuyerOnEscalation'`.
   - `QuoteAgentSettingsFactory` reads the raw value via `RawConfigValue::bool($raw, 'notifyBuyerOnEscalation') ?? false`.
   - `QuoteAgentSettings` stores `public bool $notifyBuyerOnEscalation = false` and preserves it across `withPolicy()`.

3. **Escalation actuation in `QuoteEscalator`**:
   - `QuoteEscalator` takes `?QuoteAgentSettingsSource $settingsSource = null` in its constructor (wired in `services.php`).
   - `QuoteEscalator::escalate` accepts an optional `?bool $notifyBuyer = null` parameter (defaults to checking `$this->settingsSource` for the sales channel if not explicitly passed).
   - If notification is disabled (`false`):
     - Skip `$gateway->addComment($quoteId, self::BUYER_MESSAGE)`.
     - Still stamp `merchant_quote_agent_escalated` on `customFields` to prevent comment loops.
     - Still trigger `$this->notifier?->notify(EscalationNotice::of($snapshot, $reason))` so internal merchant staff / deal desk are notified.
   - If configuration is invalid (`InvalidQuoteAgentConfiguration`), fall back to `false` (silent).

4. **Preserving parameter limits**:
   - By resolving the setting within `QuoteEscalator` via `QuoteAgentSettingsSource`, existing call sites (`OfferRound::escalated`, `AskGate::refuse`, `NegotiationPipeline`, `NegotiationFailure`) do not need to expand their parameter lists, preserving Mago's strict 5-parameter limit.

## Testing

1. **Unit tests (`QuoteEscalatorTest`)**:
   - Verify that when `notifyBuyer` is `false`, no comment is added via `$gateway->addComment()`, but the custom field marker and update are still recorded.
   - Verify that when `notifyBuyer` is `true`, the comment is posted as before.
   - Verify that when `settingsSource` is configured, it respects the sales channel setting.
2. **Settings tests (`QuoteAgentSettingsReaderTest`, `QuoteAgentSettingsFactoryTest`)**:
   - Verify `notifyBuyerOnEscalation` parses `true`, `false`, and defaults to `false` when absent.
3. **Integration tests (`ServicingConfigGateTest`, `QuoteEscalatorIntegrationTest`)**:
   - Verify `config.xml` parses and exposes `notifyBuyerOnEscalation`.
