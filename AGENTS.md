# Project Conventions

This project runs the ACL quality gate (strict mode). Follow these conventions when writing
or refactoring code; CI enforces the blocking gates. Full rationale lives in the quality-gate
policy, not here.

## Commands

- Format + lint a change: `composer run format:check && composer run lint`
- Add type checking for code changes: `composer run typecheck` (Mago analyze)
- Architecture / import / cleanup changes: `composer run quality:depcheck` (and `composer run quality:boundaries` if layers are configured)
- Broad refactor or gate change: `composer run quality`
- Advisory (non-blocking) visibility: `composer run quality:maintainability` (cognitive complexity + method length)
- Run the narrowest useful check for the change; rely on CI as the authority.

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

- Validate boundary data at runtime (e.g. Symfony Validator, webmozart/assert, or cuyz/valinor once one is adopted); define DTOs once and reuse them instead of duplicating request/response shapes.
- Keep contract classes small and domain-oriented.

## Database & persistence

- No persistence stack is wired up yet. When one is added (e.g. Doctrine ORM / Shopware's DAL), use it consistently and ship schema, migration, and application changes together in the database-owned module — no raw SQL scattered through app code.

## Environment config

- Read config through the framework config / a typed accessor once one exists; never scatter raw `getenv()`/`$_ENV` reads through application code.
- Keep `.env*` at the project root; never commit real secrets.

## Structure & constants

- High cohesion, loose coupling: each module/namespace owns one related responsibility; depend on a module's public entry point, not its internals. (Mago guard enforces declared layer boundaries when configured.)
- Place code at the smallest cohesive boundary that owns it; prefer domain/feature namespaces over `Util`/`Helper`/`Common` dumping grounds.
- Before adding a repeated literal, URL, limit, timeout, flag key, or identifier, reuse the existing constant or typed config.
- Reuse before reinventing: for non-trivial functionality, prefer a well-maintained Composer package (stdlib first, then existing deps / internal shared code) over a bespoke implementation — but don't add a dependency for something a few lines already cover.

# Review Instructions

When reviewing code here (PR/branch review, or a requested "quality pass" or "clean up"), invoke the `acl-quality-gate` skill and follow its quality-pass workflow: run the in-scope checks from **Commands** above, then read the change against the conventions in this file — tools can't judge whether logging, error handling, contracts, or structure are *correct*, only a read can. Treat CI as the final authority; never relax a threshold to make a review pass — record genuine pre-existing debt as a baseline instead.
