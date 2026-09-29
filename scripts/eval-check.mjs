#!/usr/bin/env node
/**
 * The eval pipeline's deterministic stages (spec 2026-09-28-claude-code-evals-design).
 * Called by scripts/eval.sh; every verb reads and writes files under a run
 * directory, so any stage can be re-run on its own.
 *
 *   node scripts/eval-check.mjs check <runDir>        -> checks.json
 */
import { readFileSync, realpathSync, renameSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HARD, checkNegotiation, groupNegotiations } from './eval/checks.mjs';
import { loadScenarioDir } from './eval/scenarios.mjs';

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

const verbs = { check };

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
