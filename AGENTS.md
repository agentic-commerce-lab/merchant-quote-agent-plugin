# Project Conventions

This project runs the ACL quality gate (strict mode). Follow these conventions when writing
or refactoring code; CI enforces the blocking gates. Full rationale lives in the quality-gate
policy, not here.

## Commands

- Format + lint a change: `composer run format:check && composer run lint` (both cover `src` and `tests`)
- Add type checking for code changes: `composer run typecheck` (Mago analyze — scoped to `src` only; over `tests` it reports hundreds of test idioms and no real bug)
- Behaviour: `composer run test` (unit, no kernel). `composer run test:integration` needs the test shop — see the README.
- Administration module changes: `composer run quality:admin` (assert-based self-checks; there is no JS test runner)
- Architecture / import / cleanup changes: `composer run quality:depcheck`
- Broad refactor or gate change: `composer run quality`
- Advisory (non-blocking) visibility: `composer run quality:maintainability` (cognitive complexity + method length)
- Run the narrowest useful check for the change; rely on CI as the authority.

`composer run quality:boundaries` (Mago guard) exists but is a no-op: no layers
are declared in `mago.toml`, and it is deliberately out of the `quality`
aggregate until they are.

## PHP

- `declare(strict_types=1)` in every file. Mago analyze runs at full strictness — do not bypass it with `mixed` or unsafe casts.
- Keep code within the gate thresholds: cyclomatic complexity 10, nesting depth 4, parameters 5, ~400 lines/file.
- Target PHP 8.3 (`mago.toml` `php-version`, `composer.json` `require.php` / `config.platform.php`).

## Logging

- Use a PSR-3 logger; never `echo`/`var_dump`/`print_r`/`dd` in application code (Mago `no-debug-symbols` blocks them; allowed in scripts, CLIs, migrations, config).
- Structured context, intentional levels, no secrets/PII; log the exception (with `getPrevious()`), not just the message.

## Error handling

- Throw `Throwable` subclasses (domain exceptions) only — no strings, arrays, or other values.
- Preserve the original error via the `$previous` constructor argument when wrapping; narrow caught values before use.
- No `return`/`throw`/`break`/`continue` from `finally`.

## Shared contracts

- Boundary data is validated at runtime with **Symfony Validator** (constraint attributes on the `Policy\Data` DTOs) and **cuyz/valinor** (`ArrayMapper`, and the model answers in `ModelAnswerSerializer`). Use those two; do not add a third.
- Define DTOs once and reuse them instead of duplicating request/response shapes. `Policy` deliberately keeps its own snapshot DTOs, converted at the edge by `Negotiation\SnapshotAdapter` — that is a boundary, not duplication.
- Keep contract classes small and domain-oriented.

## Database & persistence

- Two stacks, both in use, each with its own job. **Shopware's DAL** owns the audit entity (`Audit\QuoteDecisionRecord`, an attribute entity) and every read of a Shopware or SwagCommercial entity. **Doctrine DBAL** owns the tables the DAL cannot express — the A2CN evidence tables and the pending-authorization store.
- Attribute entities carry no schema generator, so every table is hand-written in `src/Migration` and must stay in step with the class that reads it. Ship schema, migration and application changes together.
- Do not use a DAL attribute argument, or any core API, newer than the support floor in `CoreFloorCompatibilityTest`. An unknown attribute argument is an `Error` during the container build, which takes the whole shop down rather than just this plugin.

## Environment config

- Merchant configuration is read through `Config\QuoteAgentSettingsReader` (`SystemConfigService`) and arrives as a validated `QuoteAgentSettings`; never read `system_config` directly from application code.
- `LOCK_DSN` is injected as a container parameter rather than read with `getenv()`, because core defines it in its own `framework.yaml`.
- Keep `.env*` at the project root; never commit real secrets. The test shop's `.env` lives outside the repository, in `~/.cache/merchant-quote-shop/`.

## Structure & constants

- High cohesion, loose coupling: each module/namespace owns one related responsibility; depend on a module's public entry point, not its internals.
- Two boundaries are enforced by tests rather than by the linter: `src/Negotiation` must not import Shopware beyond `IllegalTransitionException` (`NamespacePurityTest`), and `src/Policy` imports none at all. Everything that touches SwagCommercial goes through `src/Bridge` — see [ADR 0001](docs/adr/0001-runtime-plugin-dependencies.md).
- Place code at the smallest cohesive boundary that owns it; prefer domain/feature namespaces over `Util`/`Helper`/`Common` dumping grounds.
- Before adding a repeated literal, URL, limit, timeout, flag key, or identifier, reuse the existing constant or typed config.
- Reuse before reinventing: for non-trivial functionality, prefer a well-maintained Composer package (stdlib first, then existing deps / internal shared code) over a bespoke implementation — but don't add a dependency for something a few lines already cover.

# Review Instructions

When reviewing code here (PR/branch review, or a requested "quality pass" or "clean up"), invoke the `acl-quality-gate` skill and follow its quality-pass workflow: run the in-scope checks from **Commands** above, then read the change against the conventions in this file — tools can't judge whether logging, error handling, contracts, or structure are *correct*, only a read can. Treat CI as the final authority; never relax a threshold to make a review pass — record genuine pre-existing debt as a baseline instead.
