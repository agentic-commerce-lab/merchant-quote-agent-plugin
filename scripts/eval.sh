#!/usr/bin/env bash
# Negotiation evals, run and judged by Claude Code, played over UCP against
# the deployed shop. Spec: docs/evals-design.md
#
#   composer run eval:setup                               # once: buyer key, profile, browser consent
#   composer run eval                                     # canary, UCP bench, check, judge, verdict, report
#   composer run eval -- --from=judge var/eval/<runId>    # re-judge without new negotiations
#
# Needs EVAL_ADMIN_CLIENT_ID, EVAL_ADMIN_CLIENT_SECRET, EVAL_PRODUCT_ID and
# EVAL_NGROK_DOMAIN. Optional: EVAL_SHOP_URL (https://sw-ag.dev), EVAL_REPS (3),
# EVAL_PARALLEL (4), EVAL_PASS_TIMEOUT (180), EVAL_STANDDOWN_WAIT (60),
# EVAL_PROFILE_PORT (8787), EVAL_TAGS (floor,rounding: only scenarios carrying
# any listed tag; default all), EVAL_TAX_STATUS (gross|net, default gross: the
# price space `{unit*f}` renders in), EVAL_JUDGE_MODEL / EVAL_REPORT_MODEL
# (sonnet), EVAL_JUDGE_BUDGET_USD (0.50 per call), EVAL_REPORT_BUDGET_USD (2),
# EVAL_JUDGE_PARALLEL (4).
#
# Exit: 0 every scenario passes, 1 a scenario failed, 2 inconclusive (a judge
# error, a misgraded canary, no rows), 64 usage/preflight. Always from the
# verdict, never from a model.
set -euo pipefail
cd "$(dirname "$0")/.."

STAGES=(bench check judge verdict report)
FROM=bench
RUN_DIR=""
for arg in "$@"; do
  case "$arg" in
    --from=*) FROM="${arg#--from=}" ;;
    var/eval/*) RUN_DIR="${arg%/}" ;;
    *) echo "Unknown argument: $arg" >&2; exit 64 ;;
  esac
done
if [ "$FROM" = bench ] && [ -n "$RUN_DIR" ]; then
  echo "a run directory needs --from=<stage> (check, judge, verdict or report)" >&2
  exit 64
fi
case " ${STAGES[*]} " in *" $FROM "*) ;; *) echo "--from must be one of: ${STAGES[*]}" >&2; exit 64 ;; esac
if [ "$FROM" != bench ] && [ ! -f "$RUN_DIR/runs.jsonl" ]; then
  echo "--from=$FROM needs an existing run directory (var/eval/<runId> with runs.jsonl)" >&2
  exit 64
fi

export JUDGE_MODEL="${EVAL_JUDGE_MODEL:-sonnet}"
export JUDGE_BUDGET="${EVAL_JUDGE_BUDGET_USD:-0.50}"
REPORT_MODEL="${EVAL_REPORT_MODEL:-sonnet}"
REPORT_BUDGET="${EVAL_REPORT_BUDGET_USD:-2}"
PARALLEL="${EVAL_JUDGE_PARALLEL:-4}"

# True when stage $1 runs, i.e. it is --from or comes after it.
runs() {
  local seen=0 stage
  for stage in "${STAGES[@]}"; do
    [ "$stage" = "$FROM" ] && seen=1
    [ "$stage" = "$1" ] && { [ "$seen" = 1 ]; return; }
  done
  return 1
}

# judge <transcript> <out-without-extension>: one isolated, tool-less call.
judge() {
  rm -f "$2.json" "$2.json.error"
  claude -p --model "$JUDGE_MODEL" \
    --system-prompt "$(cat scripts/eval/judge.prompt.md)" \
    --output-format json --json-schema "$(cat scripts/eval/judge.schema.json)" \
    --tools "" --restricted --strict-mcp-config --no-session-persistence \
    --max-budget-usd "$JUDGE_BUDGET" < "$1" > "$2.raw" 2> "$2.stderr" || true
  node scripts/eval-check.mjs unwrap "$2.raw" "$2.json"
}
# Stays bash-3.2-safe (macOS /bin/bash): xargs below runs the same first `bash` on PATH.
export -f judge

canary() {
  local dir name
  dir="$(mktemp -d)"
  for name in clean broken; do
    node -e 'process.stdout.write(JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")).transcript)' \
      "tests/Bench/eval-canary/$name.json" > "$dir/$name.txt"
    judge "$dir/$name.txt" "$dir/$name"
    node scripts/eval-check.mjs canary "$dir/$name.json" "tests/Bench/eval-canary/$name.json" \
      || { echo "The judge misgraded canary '$name' -- stopping before any negotiation. Files: $dir" >&2; exit 2; }
  done
  echo "canary: the judge grades both labelled transcripts correctly"
}

if runs bench; then
  command -v claude >/dev/null || { echo "Missing: claude on PATH" >&2; exit 64; }
  command -v ngrok >/dev/null || { echo "Missing: ngrok on PATH" >&2; exit 64; }
  node scripts/eval/buyer.mjs preflight   # exits 64 naming the first failing item
  canary
  RUN_ID="eval-$(date -u +%Y%m%d-%H%M%S)-$(od -An -N3 -tx1 /dev/urandom | tr -d ' \n')"
  RUN_DIR="var/eval/$RUN_ID"
  mkdir -p "$RUN_DIR"
  node scripts/eval/buyer.mjs scenarios "$RUN_DIR"   # EVAL_TAGS narrows the copy; later stages read only it
  echo "bench: $RUN_DIR"
  # A signal (Ctrl-C: 130) stops the run; any other failure leaves the verdict to decide.
  node scripts/eval/buyer.mjs run "$RUN_DIR" \
    || { rc=$?; [ "$rc" -ge 128 ] && exit "$rc"; echo "The UCP bench exited non-zero; the verdict decides from what it wrote." >&2; }
  [ -s "$RUN_DIR/runs.jsonl" ] || { echo "No rows in $RUN_DIR/runs.jsonl." >&2; exit 2; }
fi

if runs check; then
  node scripts/eval-check.mjs check "$RUN_DIR"
fi

if runs judge; then
  [ "$FROM" = bench ] || canary   # the bench path already ran it, before any negotiation
  rm -rf "$RUN_DIR/judgments" "$RUN_DIR/transcripts"
  mkdir -p "$RUN_DIR/judgments"
  node scripts/eval-check.mjs transcripts "$RUN_DIR"
  find "$RUN_DIR/transcripts" -name '*.txt' -print0 \
    | xargs -0 -P "$PARALLEL" -I{} bash -c 'judge "$1" "$2/judgments/$(basename "$1" .txt)"' _ {} "$RUN_DIR" \
    || true   # a crashed judge leaves no judgment, which the verdict counts as err
fi

status=0
if runs verdict; then
  node scripts/eval-check.mjs verdict "$RUN_DIR" || status=$?
else
  status="$(node -p 'require(require("path").resolve(process.argv[1],"verdict.json")).exitCode' "$RUN_DIR")" || exit 2
  case "$status" in 0|1|2) ;; *) exit 2 ;; esac
fi

if runs report; then
  if sed "s|{{RUN_DIR}}|$RUN_DIR|g" scripts/eval/report.prompt.md \
    | claude -p --model "$REPORT_MODEL" --tools "Read,Grep,Bash" \
        --allowedTools "Read Grep Bash(git diff:*) Bash(git log:*)" \
        --restricted --strict-mcp-config --no-session-persistence \
        --max-budget-usd "$REPORT_BUDGET" > "$RUN_DIR/report.md.tmp"; then
    mv "$RUN_DIR/report.md.tmp" "$RUN_DIR/report.md"
    echo "report: $RUN_DIR/report.md"
  else
    echo "The report call failed; the verdict table above stands." >&2
  fi
fi

exit "$status"
