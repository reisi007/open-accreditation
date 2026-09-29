#!/usr/bin/env node
/**
 * Per-spec leak attribution: run one spec, diff the database, repeat.
 *
 * ## Why a full-run count is not enough
 *
 * A full run answers "how much did the suite leave", which is the number the
 * build agent asked for. It does not answer "WHICH spec left it", and that is
 * the number that decides where work goes next. The name sweep at the end of a
 * run masks the answer for every kind it can name — the delta reads zero whether
 * the spec cleaned up or not.
 *
 * So this harness measures each spec against a fresh baseline, and it measures
 * it TWICE:
 *
 * - **armed** — the ownership ledger is on (the current tree);
 * - **control** — the same spec with the per-test teardown disabled, via
 *   `E2E_OWNERSHIP=off`.
 *
 * The two numbers are the point. A spec that leaks in both arms leaks into the
 * serial sweep's net; a spec that leaks only in the control arm is a spec the
 * ledger now covers. Reporting only the armed number would call both "clean",
 * which is the "mostly clean" answer the whole exercise is against.
 *
 * ## Why the control arm is a switch and not a code edit
 *
 * Reverting 20 spec files per spec to measure them would be 20 chances to lose
 * work. The switch is read by the helper itself, so both arms run the SAME code
 * path up to the teardown — the only difference is whether the teardown runs.
 *
 * ## Cost
 *
 * One Playwright invocation per spec per arm, serially. That is deliberately
 * slow: §6 forbids running suites concurrently, and the login throttle (40/min
 * per IP) makes a parallel measurement wrong as well as unfair.
 *
 *     node scripts/e2e-per-spec-leaks.mjs                  # every spec, both arms
 *     node scripts/e2e-per-spec-leaks.mjs badge approvals   # named specs only
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { DevStackRefusal, devStackRefusals, fetchOk, liveDevStackRefusals } from './e2e-target-guard.mjs';

const E2E_DIR = path.resolve(process.cwd(), 'tests/e2e');

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5173';

/**
 * ## Why this tool is gated, and why the gate is the FIRST thing it does
 *
 * Everything below writes: dozens of Playwright invocations, each running the
 * suite's full create/delete mechanics against a real database. `E2E_BASE_URL`
 * was inherited unchanged and the whole repository had no guard, so pointing
 * this at a shared host was one environment variable away — and would report a
 * table of green deltas while doing it.
 *
 * Called before the first snapshot and before the first Playwright process, so a
 * refusal means NOTHING WAS TOUCHED. That ordering is the whole value of the
 * guard: a refusal that arrives after the invocations is a report, not a
 * protection. The check itself, and why it needs a human acknowledgement as well
 * as a loopback host, is documented in `e2e-target-guard.mjs`.
 */
async function assertDisposableStack() {
    const reasons = devStackRefusals(BASE_URL, process.env.E2E_LEAKS_TARGET);
    if (reasons.length === 0) {
        reasons.push(
            ...(await liveDevStackRefusals(BASE_URL, {
                fetchImpl: fetchOk,
                databaseHoldsSeededAdmin,
            })),
        );
    }
    if (reasons.length > 0) {
        throw new DevStackRefusal(reasons);
    }
}

/** Kinds the ownership ledger can address. Users are counted, never deleted. */
const MEASURES = [
    { table: 'users', sql: `SELECT count(*) FROM users WHERE email LIKE '%@example.test' AND email <> 'admin@example.com'` },
    { table: 'user_media', sql: `SELECT count(*) FROM user_media m JOIN users u ON u.id=m.user_id WHERE u.email LIKE '%@example.test' AND u.email <> 'admin@example.com'` },
    { table: 'mandant_domains', sql: `SELECT count(*) FROM mandant_domains d JOIN mandants m ON m.id=d.mandant_id WHERE m.name LIKE 'E2E %' OR m.slug LIKE 'e2e-%'` },
    { table: 'badge_images', sql: `SELECT count(*) FROM badge_images WHERE original_name LIKE 'e2e-%'` },
    { table: 'badge_templates', sql: `SELECT count(*) FROM badge_templates WHERE name LIKE 'E2E %'` },
    { table: 'categories', sql: `SELECT count(*) FROM categories WHERE name LIKE 'E2E %'` },
    { table: 'events', sql: `SELECT count(*) FROM events WHERE title LIKE 'E2E %' OR title LIKE 'Portal-Test %' OR competition LIKE 'E2E %'` },
    { table: 'teams', sql: `SELECT count(*) FROM teams WHERE name LIKE 'E2E %'` },
    { table: 'venues', sql: `SELECT count(*) FROM venues WHERE name LIKE 'E2E %'` },
    { table: 'mandants', sql: `SELECT count(*) FROM mandants WHERE name LIKE 'E2E %' OR slug LIKE 'e2e-%'` },
    { table: 'accreditations', sql: `SELECT count(*) FROM accreditations a JOIN categories c ON c.id=a.category_id WHERE c.name LIKE 'E2E %'` },
    { table: 'sub_accreditations', sql: `SELECT count(*) FROM sub_accreditations s JOIN accreditations a ON a.id=s.accreditation_id JOIN categories c ON c.id=a.category_id WHERE c.name LIKE 'E2E %'` },
    { table: 'applications', sql: `SELECT count(*) FROM applications p JOIN accreditations a ON a.id=p.accreditation_id JOIN categories c ON c.id=a.category_id WHERE c.name LIKE 'E2E %'` },
    { table: 'sub_applications', sql: `SELECT count(*) FROM sub_applications sa JOIN sub_accreditations s ON s.id=sa.sub_accreditation_id JOIN accreditations a ON a.id=s.accreditation_id JOIN categories c ON c.id=a.category_id WHERE c.name LIKE 'E2E %'` },
    // The E2E namespace, NOT the table total. MEASURED 2026-09-28: this line read
    // `SELECT count(*) FROM blacklists`, so a blacklist entry created INSIDE the
    // measurement window by anything at all — a colleague's manual test, a
    // migration seed, the tool's own previous run — produced a delta that is
    // attributed to the spec under measurement. The count is a property of the
    // WINDOW, not of the spec.
    //
    // The marker is the email, and that is a fact about the table rather than a
    // convenience: `blacklists` has no name column at all (it is
    // `email`/`domain`/`note`), and every fixture helper mints its addresses in
    // the `example.test` share of the fixture namespace — the same marker `users`
    // is counted by above. A domain-only blacklist row (no email) is invisible to
    // this measure; the suite creates none, and a marker that matched everything
    // would reintroduce the phantom delta this fixes.
    { table: 'blacklists', sql: `SELECT count(*) FROM blacklists WHERE email LIKE '%@example.test'` },
];

/** One scalar out of the dev database, as a number. */
function queryNumber(sql) {
    const raw = execFileSync(
        'docker',
        ['exec', process.env.E2E_DB_CONTAINER ?? 'accriditation_db', 'psql',
         '-U', process.env.E2E_DB_USER ?? 'accriditation',
         '-d', process.env.E2E_DB_NAME ?? 'accriditation', '-t', '-A', '-c', sql],
        { encoding: 'utf8' },
    );
    return Number.parseInt(raw.trim(), 10);
}

/**
 * Is this the project's dev database? The live half of the guard, and the one
 * thing that distinguishes "a loopback host" from "this project's loopback host":
 * a stack serving this app without this project's seeded bootstrap admin is a
 * different environment, and counting rows in it is what the gate exists to stop.
 */
function databaseHoldsSeededAdmin() {
    return Promise.resolve(
        queryNumber(`SELECT count(*) FROM users WHERE email = 'admin@example.com'`) > 0,
    );
}

/** How many blacklist rows the E2E email namespace currently holds. */
function blacklistRowsInNamespace() {
    return queryNumber(`SELECT count(*) FROM blacklists WHERE email LIKE '%@example.test'`);
}

/** Every table's current count, as a plain object keyed by table. */
function snapshot() {
    const out = {};
    for (const measure of MEASURES) {
        out[measure.table] = queryNumber(measure.sql);
    }
    return out;
}

/** The per-table delta, keeping only what moved. */
function diff(before, after) {
    const moved = {};
    for (const key of Object.keys(after)) {
        const delta = after[key] - before[key];
        if (delta !== 0) {
            moved[key] = delta;
        }
    }
    return moved;
}

/**
 * Run one spec in one arm. The global teardown is DISABLED on purpose — except
 * for the final cleanup run, which is the one place it is wanted.
 *
 * `options.purge` exists for that single call and nothing else: the control arm
 * runs the suite with the ledger OFF, so its rows stay — that is the measurement,
 * not an accident, and the cleanup is what makes the tool leave the database the
 * way it found it.
 */
function runSpec(specFile, arm, options = {}) {
    // `E2E_PURGE=off` switches the serial name sweep off for BOTH measurement
    // arms, so the delta is what the SPEC left rather than what the sweep
    // reclaimed afterwards — otherwise both arms read zero and the attribution
    // is meaningless.
    //
    // The probe-driven specs are excluded BY FILE, in the spec list below — they
    // spawn a CHILD Playwright run of their own, which would double-count and add
    // ~15 s per spec.
    //
    // BY FILE, and not by the `--grep-invert` on a test title that used to sit on
    // these args. That filter was unreachable MEASURED: it named the title of a
    // test inside `ownership.spec.ts`, a file the list below already drops, so it
    // could only ever have matched something in a file this tool does not
    // measure. Worse, it LOOKED load-bearing: a title grep is not a file
    // exclusion, it silently stops matching the day somebody renames the test,
    // and a reader of this function was entitled to believe the driver was
    // protected by it. `ownership.spec.ts` is also the one spec that must never
    // run under `E2E_OWNERSHIP=off` — that would neuter its own child's
    // teardown — and the file list is now the single place that exclusion lives
    // and can be read.
    const env = {
        ...process.env,
        E2E_PURGE: options.purge === true ? 'on' : 'off',
        E2E_OWNERSHIP: arm === 'control' ? 'off' : 'on',
    };
    const args = [
        'playwright', 'test', `tests/e2e/${specFile}`,
        '--project=Desktop Chrome', '--reporter=line',
    ];
    try {
        const stdout = execFileSync('npx', args, { encoding: 'utf8', stdio: 'pipe', env });
        return { status: 0, tail: stdout.split('\n').slice(-4).join('\n') };
    } catch (error) {
        return { status: error.status ?? 1, tail: String(error.stdout ?? '').split('\n').slice(-6).join('\n') };
    }
}

const requested = process.argv.slice(2);
const specs = fs
    .readdirSync(E2E_DIR)
    .filter((file) => file.endsWith('.spec.ts'))
    // Three specs are excluded because they are META-experiments, not
    // fixture-creating feature specs: `namespace-isolation` tests the harness's
    // own wiring, `ownership` and `child-lifetime` each spawn a CHILD Playwright
    // run of their own (which would double-count and add seconds per spec).
    .filter((file) => !['namespace-isolation.spec.ts', 'ownership.spec.ts', 'child-lifetime.spec.ts'].includes(file))
    .filter((file) => requested.length === 0 || requested.some((name) => file.includes(name)))
    .sort();

// ── The gate, before anything is touched (see `e2e-target-guard.mjs`) ──────
// Awaited at MODULE level, i.e. before the first `snapshot()` below and before
// the first `runSpec`. A refusal exits non-zero with a message that says nothing
// was written, which is the difference between a guard and a report.
try {
    await assertDisposableStack();
} catch (error) {
    if (error instanceof DevStackRefusal) {
        process.stderr.write(`${error.message}\n`);
        process.exit(2);
    }
    throw error;
}

const report = [];
for (const spec of specs) {
    const arms = {};
    for (const arm of ['armed', 'control']) {
        const before = snapshot();
        const result = runSpec(spec, arm);
        const after = snapshot();
        arms[arm] = { delta: diff(before, after), status: result.status, tail: result.tail };
    }
    report.push({ spec, ...arms });
    const fmt = (delta) => {
        const entries = Object.entries(delta);
        return entries.length === 0 ? 'clean' : entries.map(([table, n]) => `${table} ${n > 0 ? '+' : ''}${n}`).join(', ');
    };
    // A run that FAILED is not a measurement of anything: a spec that dies in its
    // login step creates no fixtures at all, so its delta reads "clean" for the
    // wrong reason. That is the same false pass this harness exists to avoid, so a
    // non-zero exit is marked INVALID rather than reported as clean.
    const mark = (arm) => (arms[arm].status === 0 ? ' ' : '!');
    process.stdout.write(
        `${spec.padEnd(30)} armed[${String(arms.armed.status).padStart(2)}]${mark('armed')}: ` +
            `${fmt(arms.armed.delta).padEnd(50)} control[${String(arms.control.status).padStart(2)}]${mark('control')}: ` +
            `${fmt(arms.control.delta)}\n`,
    );
}
process.stdout.write('\n  [ ] = valid measurement     [!] = INVALID, the run failed (its delta proves nothing)\n');

fs.writeFileSync(path.resolve(process.cwd(), 'test-results/e2e-per-spec-leaks.json'), JSON.stringify(report, null, 2));
process.stdout.write('\nwrote test-results/e2e-per-spec-leaks.json\n');

/**
 * ## The tool cleans up after itself, and says whether it did
 *
 * The control arm runs the suite with the ledger OFF, which means its rows stay
 * — that is the measurement, not an accident. But a measurement tool that leaves
 * its residue in the dev database is a tool whose residue is indistinguishable
 * from the next tool's finding: the run after this one counts what this one left,
 * and neither number can be trusted.
 *
 * The cleanup is the suite's OWN serial name sweep, reached by one extra run of
 * the cheapest fixture-free spec with the purge enabled — a re-implementation
 * here would be a second copy of `E2E_PURGE_SWEEPS` to keep in step with the
 * first, and this file is not the place that ordering lives.
 *
 * `routing.spec.ts` is the spec used for it, and the choice is measured, not
 * arbitrary: it calls no fixture helper, so it adds nothing of its own, and its
 * only job is to make the run end — which is what runs the sweep.
 */
const CLEANUP_SPEC = 'routing.spec.ts';

const beforeCleanup = snapshot();
const cleanup = runSpec(CLEANUP_SPEC, 'armed', { purge: true });
const afterCleanup = snapshot();
const reclaimed = diff(afterCleanup, beforeCleanup);
const fmtDelta = (delta) => {
    const entries = Object.entries(delta);
    return entries.length === 0 ? 'nothing to reclaim' : entries.map(([table, n]) => `${table} ${n}`).join(', ');
};
if (cleanup.status !== 0) {
    process.stdout.write(
        `\n  CLEANUP FAILED (exit ${cleanup.status}) — the control arm's rows are still in the dev database.\n` +
            `${cleanup.tail}\n`,
    );
    process.exitCode = 1;
} else {
    process.stdout.write(`\n  cleanup: ran ${CLEANUP_SPEC} with the serial purge → reclaimed ${fmtDelta(reclaimed)}\n`);
    // The serial purge reclaims everything with a NAME marker. `blacklists` has
    // no name column (`email`/`domain`/`note` only), so the sweep cannot see it —
    // and the tool would otherwise leave one row per control arm behind: the
    // residue that breaks `approvals.spec.ts`'s "1 Eintrag" assertion on the NEXT
    // run. Deleting the E2E email namespace here is the tool's own end cleanup,
    // scoped to exactly the marker the suite mints.
    const purgedBlacklists = blacklistRowsInNamespace();
    if (purgedBlacklists > 0) {
        execFileSync(
            'docker',
            ['exec', process.env.E2E_DB_CONTAINER ?? 'accriditation_db', 'psql',
             '-U', process.env.E2E_DB_USER ?? 'accriditation',
             '-d', process.env.E2E_DB_NAME ?? 'accriditation', '-t', '-A',
             '-c', `DELETE FROM blacklists WHERE email LIKE '%@example.test'`],
            { encoding: 'utf8' },
        );
        process.stdout.write(
            `  cleanup: deleted ${purgedBlacklists} blacklist row(s) the name sweep cannot address ` +
                '(no name column — Position 24.3)\n',
        );
    }
    const stillThere = Object.entries(afterCleanup).filter(([table, n]) => n > 0 && table !== 'blacklists');
    if (stillThere.length > 0) {
        // Reported, not hidden: `users` has no delete route and grows by design (a
        // separate board position), and `user_media` follows it. Naming the
        // remainder is the only way a reader can tell "the cleanup worked" from
        // "the cleanup did nothing and happened to look clean".
        process.stdout.write(
            '  cleanup: rows the purge cannot address remain: ' +
                `${stillThere.map(([table, n]) => `${table} ${n}`).join(', ')}\n`,
        );
    }
}
