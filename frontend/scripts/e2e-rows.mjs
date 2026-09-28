#!/usr/bin/env node
/**
 * Count the rows the E2E suite leaves in the dev database, per table.
 *
 * ## Why this exists
 *
 * The teardown's own reporting answers "did the purge reclaim what it matched",
 * which is a question about the *purge*. Nobody could answer the other question:
 * how many `E2E %` rows does a full run leave behind, per table, when the purge
 * is right and the fixtures are still wrong? The measured answer was 759 users
 * and 116 media rows, and the number nobody had was the one that mattered —
 * whether a *spec* leaks or merely inherits.
 *
 * ## What it counts, and what it deliberately does not
 *
 * Every entity kind the suite can create, keyed by the E2E marker its rows
 * carry. `users` and `user_media` are reachable only through the *email* prefix
 * the fixture helpers stamp (`sub-`, `approve-`, `badge-`, `auth-`, `profile-`,
 * `admin-users-` … `@example.test`), because a user has no name marker at all —
 * the display names repeat across runs. That is the honest join key, and it is
 * why the user numbers are reported separately: **there is no DELETE route for
 * a user**, so those rows are a measured gap, not a bug in this script.
 *
 * `blacklists` has no NAME column (it is `email`/`domain`/`note`), so it is
 * counted by its EMAIL, which lands in the same `example.test` fixture namespace
 * every user address does. It used to be counted as a table TOTAL, which made the
 * number a property of the measurement window rather than of the suite.
 *
 * ## Usage
 *
 *     node scripts/e2e-rows.mjs            # human table
 *     node scripts/e2e-rows.mjs --json     # machine-readable, for diffing
 *
 * Run it before and after a run and diff: the delta is what that run leaked.
 */

import { execFileSync } from 'node:child_process';

const CONTAINER = process.env.E2E_DB_CONTAINER ?? 'accriditation_db';
const DB_USER = process.env.E2E_DB_USER ?? 'accriditation';
const DB_NAME = process.env.E2E_DB_NAME ?? 'accriditation';

/**
 * One row per entity kind: the label, the SQL that counts it, and whether the
 * join key is a name marker or an email prefix. `E2E` is the share of the
 * `example.test` domain the fixture helpers use — the seeded admin is
 * `admin@example.com` and the review harness uses its own domain, so neither is
 * counted here.
 */
const MEASURES = [
    {
        table: 'users',
        via: 'email',
        sql: `SELECT count(*) FROM users WHERE email ~ '^(sub|approve|badge|auth|profile|admin-users)[-@]' OR email LIKE '%@example.test'`,
    },
    {
        table: 'user_media',
        via: 'email (owner)',
        sql: `SELECT count(*) FROM user_media m JOIN users u ON u.id = m.user_id WHERE u.email ~ '^(sub|approve|badge|auth|profile|admin-users)[-@]' OR u.email LIKE '%@example.test'`,
    },
    {
        table: 'mandant_domains',
        via: 'name (owner mandant)',
        sql: `SELECT count(*) FROM mandant_domains d JOIN mandants m ON m.id = d.mandant_id WHERE m.name LIKE 'E2E %' OR m.slug LIKE 'e2e-%'`,
    },
    {
        table: 'badge_images',
        via: 'original_name',
        sql: `SELECT count(*) FROM badge_images WHERE original_name LIKE 'e2e-%'`,
    },
    {
        table: 'badge_templates',
        via: 'name',
        sql: `SELECT count(*) FROM badge_templates WHERE name LIKE 'E2E %'`,
    },
    {
        table: 'categories',
        via: 'name',
        sql: `SELECT count(*) FROM categories WHERE name LIKE 'E2E %'`,
    },
    {
        table: 'events',
        via: 'title/competition',
        sql: `SELECT count(*) FROM events WHERE title LIKE 'E2E %' OR title LIKE 'Portal-Test %' OR competition LIKE 'E2E %'`,
    },
    {
        table: 'teams',
        via: 'name',
        sql: `SELECT count(*) FROM teams WHERE name LIKE 'E2E %'`,
    },
    {
        table: 'venues',
        via: 'name',
        sql: `SELECT count(*) FROM venues WHERE name LIKE 'E2E %'`,
    },
    {
        table: 'mandants',
        via: 'name/slug',
        sql: `SELECT count(*) FROM mandants WHERE name LIKE 'E2E %' OR slug LIKE 'e2e-%'`,
    },
    {
        table: 'accreditations',
        via: 'name (owner category)',
        sql: `SELECT count(*) FROM accreditations a JOIN categories c ON c.id = a.category_id WHERE c.name LIKE 'E2E %'`,
    },
    {
        table: 'sub_accreditations',
        via: 'name (owner category)',
        sql: `SELECT count(*) FROM sub_accreditations s JOIN accreditations a ON a.id = s.accreditation_id JOIN categories c ON c.id = a.category_id WHERE c.name LIKE 'E2E %'`,
    },
    {
        table: 'applications',
        via: 'name (owner category)',
        sql: `SELECT count(*) FROM applications p JOIN accreditations a ON a.id = p.accreditation_id JOIN categories c ON c.id = a.category_id WHERE c.name LIKE 'E2E %'`,
    },
    {
        table: 'sub_applications',
        via: 'name (owner category)',
        sql: `SELECT count(*) FROM sub_applications sa JOIN sub_accreditations s ON s.id = sa.sub_accreditation_id JOIN accreditations a ON a.id = s.accreditation_id JOIN categories c ON c.id = a.category_id WHERE c.name LIKE 'E2E %'`,
    },
    {
        table: 'blacklists',
        // CORRECTED 2026-09-28. This was `NO MARKER (total)` — a bare
        // `SELECT count(*) FROM blacklists` — which is a count of the WINDOW, not
        // of the suite: any entry created inside a measurement window by anything
        // at all (a manual test, a seed, a previous run) shows up as a delta that
        // this script then attributes to the run being measured.
        //
        // The marker is the email, and that is a property of the TABLE rather than
        // a convenience: `blacklists` has no name column (`email`/`domain`/`note`
        // only) and every fixture helper mints its addresses in the `example.test`
        // share of the fixture namespace — the same marker the `users` measure
        // uses above. A domain-only entry (no email) is invisible here, which is
        // why the label names the marker instead of claiming completeness. The
        // suite creates none.
        via: 'email (fixture namespace)',
        sql: `SELECT count(*) FROM blacklists WHERE email LIKE '%@example.test'`,
    },
];

/** Run one count through the dev database's container. */
function count(sql) {
    const out = execFileSync(
        'docker',
        ['exec', CONTAINER, 'psql', '-U', DB_USER, '-d', DB_NAME, '-t', '-A', '-c', sql],
        { encoding: 'utf8' },
    );
    return Number.parseInt(out.trim(), 10);
}

const measured = MEASURES.map((measure) => ({
    table: measure.table,
    via: measure.via,
    count: count(measure.sql),
}));

if (process.argv.includes('--json')) {
    process.stdout.write(`${JSON.stringify(measured, null, 2)}\n`);
} else {
    const width = Math.max(...measured.map((row) => row.table.length));
    const total = measured.reduce((sum, row) => sum + row.count, 0);
    for (const row of measured) {
        process.stdout.write(`${row.table.padEnd(width)}  ${String(row.count).padStart(5)}   (${row.via})\n`);
    }
    process.stdout.write(`${'-'.repeat(width + 24)}\n`);
    process.stdout.write(`${'TOTAL'.padEnd(width)}  ${String(total).padStart(5)}\n`);
}
