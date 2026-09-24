# Run trace capture — PR 2: skipped runs

Base: PR 1 (`claude/run-data-collection-export-5d2fb4`). Binding spec: `docs/superpowers/specs/2026-09-23-run-trace-capture-design.md`, Delivery → PR 2.

## Goal

Every handler or preflight exit that produces no decision row writes a durable `skip` trace event. The existing export emits outside-pass events as JSONL lines after decision lines. `--outcome` suppresses event lines.

## Constraints

- Preserve Messenger retry/parking and quote mutation behavior. Existing log lines stay.
- Audit writes never fail a servicing delivery. Store through the system-scope DAL repository, log failures, and never retry a delivery because recording failed.
- Keep `meta` to closed machine values (`source`, `reason`, `trigger`, `attempt`); no buyer text in a default export. `content` is null for `skip`.
- Keep the existing five-parameter constructor limit in `ServiceQuoteHandler` and `ServicingPreflight`; inject a `ServicingJournal` in place of their logger. It delegates PSR-3 calls and writes skip traces through `TraceWriter`.
- Leave `QuoteServicingTrigger`'s DAL-event filter untouched. PR 3 owns observer exits.

## Tasks

1. **Trace writer and vocabulary.** Add `TraceKind::Skip`, `Servicing\SkipReason`, and `Servicing\SkipSource`. Add an outside-pass draft and a `TraceWriter` that normalizes `meta`, caps content, writes once, and catches/logs every failure. Unit-test payload, null decision/position, and failure isolation. Extend `RecorderOwnershipTest` to pin only `DecisionRecordWriter` and `TraceWriter` inserting trace rows.
2. **Handler exits.** Add typed skip context and `ServicingJournal`. Record `no_gateway`, `lock_busy`, `quote_not_found`, `stale_trigger`, `nothing_new`, `no_pipeline`, `crash_budget`, and `attempt_write_failed` at their existing exits. Use null trigger for an invalid enum string. Keep the existing throws, returns, lock release, and logs. Write focused tests for each exit and no event for a successful pass.
3. **Preflight exits.** Record `terminal_state` and `kill_switch` before the existing returns. Record `refusal_write_failed` only when `recordRefusal()` throws; successful misconfiguration escalations keep their decision row and no skip event. Test all three cases and the no-throw audit guarantee.
4. **Outside-pass export.** After decision lines, stream trace rows whose `decisionId` is null and `occurredAt` falls in the half-open range. Emit `{record: "event", id, quote, customer, kind, occurredAt, meta, content?}` with pseudonymized ids and the existing free-text gate. With `--outcome`, emit decision lines only and report that event lines were excluded. Test order, range, id masking, default content exclusion, and filter behavior.
5. **Shop proof and review.** Test an outside-pass DAL row and its export in the test shop, including a viewer context. Run unit tests, focused integration, format/lint/type checks, the full quality gate, dependency check, and source review against `AGENTS.md`. Commit, push, and open a PR with base `claude/run-data-collection-export-5d2fb4`.

## Out of scope

`http`, `assistant_tool`, `seller_act`, and observer skip sites belong to PR 3. This PR does not backfill historic skipped deliveries.
