Write the report for a negotiation eval run of the Merchant Quote Agent Shopware plugin. The run played scenarios as an external UCP buyer against the deployed shop. Its files are in `{{RUN_DIR}}`:

- `verdict.json`: the authoritative result, per scenario and check, with status, pass counts and per-rep reasons. Report it; do not re-judge anything.
- `runs.jsonl`: one decision-record pass per line. Field names match `merchant_quote_agent_decision`.
- `judgments/*.json`: the judge's rubric answers and extracted figures, per negotiation (`<scenario>-<rep>.json`).
- `scenarios/*.json`: what each scenario asks and expects.
- `run.json`: the shop, deployed plugin version, model, reps and product.

Every check (H1–H10, J1–J5) is defined in `docs/evals-design.md`.

Output Markdown only, with these sections in order:

1. **Headline:** `<passing>/<total> scenarios pass — exit <code> — run <runId>`, then one line with the shop, the plugin version and the model.
2. **Table:** one row per scenario, with columns H1 H2 H3 H4 H5 H6 H7 H8 H9 H10 J1 J2 J3 J4 J5. Copy each cell (`3/3`, `1/3`, `n/a`, `err`) from `verdict.json`.
3. **Tags:** one row per tag in `verdict.json`'s `tags`, with columns tag, scenarios, passing and pass rate (`passRate`), copied as given.
4. **Usage:** from `verdict.json`'s `usage`. First one line for `usage.total`: passes, prompt and completion tokens, buyer wait (`buyerLatency` median and max), shop pass duration (`passDuration` median and max) and judge cost in USD (`judgeCostUsd`); write "n/a" for any of them that is null. Latencies are in ms, as the file gives them. Then a table with one row per scenario from `usage.scenarios`, same columns. Copy every number; never re-derive, sum or price one. The tokens are the shop's model, which has no price here.
5. **For each scenario that did not pass,** a section containing:
   - the failing checks and their reasons, verbatim;
   - the failing round's buyer ask and agent reply, from `runs.jsonl`;
   - a **Likely cause** paragraph. The run tested the *deployed* plugin, so start from the code path the check exercises, as the spec names it. Read those files in this checkout, and note whether `git log -5 -- <file>` shows recent changes there. Name a file and line where the code explains the failure. Otherwise write "Nothing in the code read explains this".
6. **Judge errors:** every `err` cell, listed separately from agent failures.

Rules: never change a status, never call a failure flaky, and quote numbers exactly as the files give them.
