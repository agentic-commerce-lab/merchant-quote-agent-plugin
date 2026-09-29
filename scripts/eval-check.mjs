#!/usr/bin/env node
/**
 * The eval pipeline's deterministic stages (spec 2026-09-28-claude-code-evals-design).
 * Called by scripts/eval.sh; every verb reads and writes files under a run
 * directory, so any stage can be re-run on its own.
 *
 *   node scripts/eval-check.mjs check <runDir>                   -> checks.json
 *   node scripts/eval-check.mjs transcripts <runDir>             -> transcripts/<id>-<rep>.txt
 *   node scripts/eval-check.mjs unwrap <raw> <out.json>          -> the judgment JSON, or <out.json>.error
 *   node scripts/eval-check.mjs canary <judgment.json> <labels.json>  -> exit 1 on a mismatch
 *   node scripts/eval-check.mjs verdict <runDir>                 -> verdict.json and the table; exits 0 pass, 1 fail, 2 judge error
 */
import { existsSync, mkdirSync, readFileSync, readdirSync, realpathSync, renameSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HARD, checkNegotiation, groupNegotiations } from './eval/checks.mjs';
import { loadScenarioDir } from './eval/scenarios.mjs';
import { canaryMismatches, formatTable, transcript, unwrapJudgeResult, verdict } from './eval/verdict.mjs';

export function readJsonl(path) {
    return readFileSync(path, 'utf8').split('\n').filter((line) => line.trim() !== '').map((line) => JSON.parse(line));
}

/** The scenarios copied into the run directory at bench time, validated by the same code the buyer used. */
export function loadScenarios(runDir) {
    return loadScenarioDir(join(runDir, 'scenarios'));
}

export function writeAtomically(path, contents) {
    writeFileSync(`${path}.tmp`, contents);
    renameSync(`${path}.tmp`, path);
}

function check(runDir) {
    const scenarios = new Map(loadScenarios(runDir).map((scenario) => [scenario.id, scenario]));
    const results = groupNegotiations(readJsonl(join(runDir, 'runs.jsonl'))).map((negotiation) => {
        const scenario = scenarios.get(negotiation.scenarioId);
        if (!scenario) throw new Error(`runs.jsonl names scenario "${negotiation.scenarioId}", which ${runDir}/scenarios does not have`);
        return { scenarioId: negotiation.scenarioId, rep: negotiation.rep, checks: checkNegotiation(scenario, negotiation) };
    });
    writeAtomically(join(runDir, 'checks.json'), `${JSON.stringify(results, null, 2)}\n`);
    const failures = results.flatMap((r) => HARD.filter((id) => r.checks[id].status === 'fail').map((id) => `${r.scenarioId}#${r.rep} ${id}: ${r.checks[id].reason}`));
    console.log(`checks: ${results.length} negotiations, ${failures.length} hard-check failures`);
    for (const failure of failures) console.log(`  ${failure}`);
}

function transcripts(runDir) {
    const scenarios = new Map(loadScenarios(runDir).map((s) => [s.id, s]));
    const dir = join(runDir, 'transcripts');
    mkdirSync(dir, { recursive: true });
    let written = 0;
    for (const negotiation of groupNegotiations(readJsonl(join(runDir, 'runs.jsonl')))) {
        if (negotiation.failure) continue; // H7 already fails it; there is nothing to judge
        const scenario = scenarios.get(negotiation.scenarioId);
        if (!scenario) throw new Error(`runs.jsonl names scenario "${negotiation.scenarioId}", which ${runDir}/scenarios does not have`);
        writeAtomically(join(dir, `${negotiation.scenarioId}-${negotiation.rep}.txt`), transcript(scenario, negotiation));
        written++;
    }
    console.log(`transcripts: ${written}`);
}

function unwrap(rawPath, outPath) {
    const raw = existsSync(rawPath) ? readFileSync(rawPath, 'utf8') : '';
    const result = unwrapJudgeResult(raw);
    if (result.error) {
        writeAtomically(`${outPath}.error`, `${result.error}\n`);
        console.error(`judge error for ${outPath}: ${result.error}`);
        return;
    }
    writeAtomically(outPath, `${JSON.stringify(result.judgment, null, 2)}\n`);
}

function canary(judgmentPath, labelsPath) {
    if (!existsSync(judgmentPath)) {
        console.error(`canary: no judgment (${existsSync(`${judgmentPath}.error`) ? readFileSync(`${judgmentPath}.error`, 'utf8').trim() : 'claude -p produced nothing'})`);
        process.exit(1);
    }
    const mismatches = canaryMismatches(JSON.parse(readFileSync(judgmentPath, 'utf8')), JSON.parse(readFileSync(labelsPath, 'utf8')).labels);
    for (const mismatch of mismatches) console.error(`canary: ${mismatch}`);
    process.exit(mismatches.length === 0 ? 0 : 1);
}

function verdictVerb(runDir) {
    const meta = JSON.parse(readFileSync(join(runDir, 'run.json'), 'utf8'));
    const judgments = new Map();
    const dir = join(runDir, 'judgments');
    for (const name of existsSync(dir) ? readdirSync(dir) : []) {
        const match = name.match(/^(.+)-(\d+)\.json$/);
        if (match) judgments.set(`${match[1]}#${match[2]}`, JSON.parse(readFileSync(join(dir, name), 'utf8')));
    }
    const result = verdict({ scenarios: loadScenarios(runDir), reps: meta.reps, rows: readJsonl(join(runDir, 'runs.jsonl')), judgments });
    writeAtomically(join(runDir, 'verdict.json'), `${JSON.stringify(result, null, 2)}\n`);
    console.log(formatTable(result));
    process.exit(result.exitCode);
}

const verbs = { check, transcripts, unwrap, canary, verdict: verdictVerb };

// Same guard as bench-score.mjs: realpath so a relative or symlinked argv[1]
// still matches this file's absolute URL, and importing it runs nothing.
if (process.argv[1] && realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const [verb, ...args] = process.argv.slice(2);
    if (!verbs[verb]) {
        console.error(`usage: eval-check.mjs <${Object.keys(verbs).join('|')}> ...`);
        process.exit(64);
    }
    verbs[verb](...args);
}
