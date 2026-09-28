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

const E2E_DIR = path.resolve(process.cwd(), 'tests/e2e');

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
    { table: 'blacklists', sql: `SELECT count(*) FROM blacklists` },
];

/** Every table's current count, as a plain object keyed by table. */
function snapshot() {
    const out = {};
    for (const measure of MEASURES) {
        const raw = execFileSync(
            'docker',
            ['exec', process.env.E2E_DB_CONTAINER ?? 'accriditation_db', 'psql',
             '-U', process.env.E2E_DB_USER ?? 'accriditation',
             '-d', process.env.E2E_DB_NAME ?? 'accriditation', '-t', '-A', '-c', measure.sql],
            { encoding: 'utf8' },
        );
        out[measure.table] = Number.parseInt(raw.trim(), 10);
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

/** Run one spec in one arm. The global teardown is DISABLED on purpose. */
function runSpec(specFile, arm) {
    // `E2E_PURGE=off` switches the serial name sweep off for BOTH arms, so the
    // delta is what the SPEC left rather than what the sweep reclaimed afterwards
    // — otherwise both arms read zero and the attribution is meaningless.
    //
    // The probe-driven spec is excluded: it spawns a CHILD Playwright run of its
    // own, which would double-count and add ~15 s per spec.
    const env = {
        ...process.env,
        E2E_PURGE: 'off',
        E2E_OWNERSHIP: arm === 'control' ? 'off' : 'on',
    };
    const args = [
        'playwright', 'test', `tests/e2e/${specFile}`,
        '--project=Desktop Chrome', '--reporter=line',
        '--grep-invert', 'ownership ledger gives a half-failed test',
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
    .filter((file) => file.endsWith('.spec.ts') && file !== 'namespace-isolation.spec.ts')
    .filter((file) => requested.length === 0 || requested.some((name) => file.includes(name)))
    .sort();

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
