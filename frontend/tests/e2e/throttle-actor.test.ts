import { describe, expect, it } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { THROTTLE_ACTOR_HEADER, throttleActorHeaders, throttleActorId } from './helpers/throttle-actor';

/**
 * The wire contract of the throttle-actor header (position 49), pinned from
 * BOTH sides: the harness that sends it and the Laravel provider that reads it.
 *
 * ## Why a test that reads the PHP source as text
 *
 * The things that can silently break here are all invisible to a type-checker,
 * and three of them were real:
 *
 * 1. **A rename on either side.** The header name is a string constant in two
 *    languages. Nothing links them, so a rename in one place leaves a header
 *    the backend never reads — a silent no-op with a green suite.
 * 2. **A value the backend discards.** The provider accepts only
 *    `[A-Za-z0-9._-]` up to 32 chars; a shape outside it falls back to the
 *    shared per-ip bucket, i.e. back to the old behaviour, quietly.
 * 3. **The wrong option key.** MEASURED 2026-10-04 (Playwright 1.63, echo
 *    server): `request.newContext({ baseURL, ...throttleActorHeaders() })`
 *    compiles, lints, type-checks and sends NOTHING — the spread produces a
 *    top-level `X-Test-Actor` option that Playwright ignores, so the request
 *    arrived without the header. That is the whole reason test 3 exists.
 * 4. **Two regex engines reading one pattern.** The provider's shape is a PCRE
 *    literal in PHP source; this file compiles the SAME string into a JS
 *    `RegExp`. The two agree on the alphabet and disagree on the ANCHOR: PCRE
 *    `$` also matches before a trailing newline, JavaScript's `$` (without `/m`)
 *    does not — and PCRE's strict `\z` has no JS spelling at all
 *    (`new RegExp('\\z')` matches a literal `z`). MEASURED 2026-10-04 with the
 *    provider still on `/^[A-Za-z0-9._-]{1,32}$/`: PHP accepted `"w1\n"` and put
 *    the whole string, newline included, into the cache key, while this file's
 *    JS `RegExp` rejected it. Both sides were "tested" and they did not mean the
 *    same thing.
 *
 * Reading the provider as text is how this repo already relates to a contract
 * it cannot import (`namespace-isolation.spec.ts` reads the specs as text for
 * the same reason). The alternative — duplicating the pattern here — is a
 * second truth that is wrong the moment one side moves.
 *
 * Deliberately NOT tested here: that Playwright actually puts the header on the
 * wire. That needs a real `request.newContext` and a server to receive it; it
 * was measured with an out-of-tree probe against an echo server (both the
 * worker-index and the no-worker-index branch), and it cannot be re-measured by
 * this file without importing `@playwright/test` into Vitest — 121 s, measured,
 * per `helpers/teams-enabled.ts`.
 */
const HERE = path.dirname(fileURLToPath(import.meta.url));
const PROVIDER = path.join(HERE, '..', '..', '..', 'backend', 'app', 'Providers', 'AppServiceProvider.php');

/**
 * This file, as a path relative to `tests/e2e`. Excluded from its own inventory
 * because it quotes both search patterns in prose — a checker that counts itself
 * is a checker whose totals mean nothing.
 */
const SCANNER = 'throttle-actor.test.ts';

/**
 * Two accessors, no parameters, on purpose: `tests/e2e/**` is linted with the
 * plain-ES2020 parser, where a parameter annotation is a parse error, and JSDoc
 * does not type a parameter in a `.ts` file either (TS7006, measured — see
 * `eslint.config.js`). A zero-parameter function needs neither.
 */
function providerSource() {
    return fs.readFileSync(PROVIDER, 'utf-8');
}

function providerHeaderName() {
    const match = providerSource().match(/const TEST_ACTOR_HEADER = '([^']*)'/);

    if (!match) {
        throw new Error(`TEST_ACTOR_HEADER not found in ${PROVIDER} — the cross-language contract this file pins cannot be read`);
    }

    return match[1];
}

/**
 * The provider's accepted actor shape as the raw regex BODY, i.e. what sits
 * between the `/` delimiters in the PHP literal.
 */
function providerActorPattern() {
    // `\/` … `\/` around the body: the PHP literal is delimited by slashes, and a
    // `[^']*` capture would swallow the closing delimiter as well (measured —
    // the extracted RegExp then ended in a literal `\/` and matched nothing).
    const match = providerSource().match(/const TEST_ACTOR_PATTERN = '\/(.+)\/'\s*;/);

    if (!match) {
        throw new Error(`TEST_ACTOR_PATTERN not found in ${PROVIDER} — the cross-language contract this file pins cannot be read`);
    }

    return match[1];
}

/**
 * The same body as a JS RegExp.
 *
 * The one rewrite is `\z` → `$`, and it is not cosmetic: PCRE's `\z` (absolute
 * end of subject) is the anchor the provider now uses, and JavaScript has no
 * spelling for it — `new RegExp('\\z')` is an identity escape and matches a
 * literal `z`, which would reject EVERY actor id (measured: with the provider on
 * `\z` and no translation here, `throttleActorId()` fails to match the pattern the
 * provider itself enforces). JavaScript's `$` without the `/m` flag is the exact
 * equivalent (absolute end of input), so this rewrite is what buys the symmetry
 * two engines, one rule.
 *
 * The reverse direction is deliberately NOT translated: a JS `$` in the provider
 * would silently mean something stricter here than there, which is the very
 * divergence this rewrite exists to remove.
 * `it('anchors the provider pattern with \z, not with a PCRE $')` below fails if
 * that ever comes back.
 */
function providerActorRegExp() {
    return new RegExp(providerActorPattern().replace(/\\z/g, '$'));
}

/**
 * Every `.ts` file under `tests/e2e`, RECURSIVELY, minus this file.
 *
 * ## The two exclusions, and why neither is an allow-list entry
 *
 * - **This file** — named explicitly, because it quotes both search patterns in
 *   prose and would otherwise count itself.
 * - **The ui-review screenshot harness** — excluded BY DIRECTORY, not by name:
 *   it lives in `tests/screenshots/`, so the walk rooted at `tests/e2e` cannot
 *   reach it and no list entry can go stale. It is a separate Playwright config
 *   with its own justification, written out in `helpers/throttle-actor.ts`.
 *
 * Browser contexts (`browser.newContext`, 4 sites in `a11y.spec.ts` and
 * `admin-mobile-layout.spec.ts`) are not an exclusion: the scan looks for
 * `request.newContext`, so it cannot mistake one for the other.
 */
function inventory() {
    // Breadth-first over directories, and deliberately NOT a recursive function:
    // this directory is parsed as plain ES2020, so neither a parameter annotation
    // nor an `interface` is available, and a recursive arrow function makes TS
    // give up on its parameters (`TS7006`, measured). `dirs` is therefore seeded
    // with the root, which is what gives it an inferred `string[]` element type —
    // a bare `[]` is `any[]` under `strict` (`TS7034`, also measured).
    const dirs = [HERE];

    for (let index = 0; index < dirs.length; index += 1) {
        for (const entry of fs.readdirSync(dirs[index], { withFileTypes: true })) {
            if (entry.isDirectory()) {
                dirs.push(path.join(dirs[index], entry.name));
            }
        }
    }

    return dirs
        .flatMap((dir) => {
            const relative = path.relative(HERE, dir);

            return fs
                .readdirSync(dir, { withFileTypes: true })
                .filter((entry) => !entry.isDirectory() && entry.name.endsWith('.ts'))
                .map((entry) => ({
                    path: path.join(dir, entry.name),
                    label: `${relative === '' ? '' : `${relative}/`}${entry.name}`,
                }));
        })
        .filter((file) => file.label !== SCANNER)
        .map((file) => ({
            ...file,
            // `helpers/*` (the fixture creators), a top-level `*.spec.ts` (the specs
            // that build a context inline), and everything else — the top-level
            // `*.test.ts` / config / setup files plus the whole `ownership-probe/`
            // tree. The third bucket is what makes a non-recursive walk visible.
            kind: file.label.startsWith('helpers/')
                ? 'helper'
                : file.label.includes('/') || !file.label.endsWith('.spec.ts')
                  ? 'other'
                  : 'spec',
        }))
        .sort((a, b) => (a.label < b.label ? -1 : 1));
}

describe('throttle actor header', () => {
    it('uses the header name the backend reads', () => {
        expect(THROTTLE_ACTOR_HEADER).toBe(providerHeaderName());
    });

    it('produces actor ids inside the alphabet and length the backend accepts', () => {
        // `/^[A-Za-z0-9._-]{1,32}\z/` — read from the provider, not retyped here.
        const accepted = providerActorRegExp();

        const original = process.env.TEST_WORKER_INDEX;
        try {
            // Playwright worker branch (`TEST_WORKER_INDEX` set).
            process.env.TEST_WORKER_INDEX = '3';
            expect(throttleActorId()).toMatch(accepted);

            // Teardown branch: no worker index, pid alone.
            delete process.env.TEST_WORKER_INDEX;
            expect(throttleActorId()).toMatch(accepted);
        } finally {
            if (original === undefined) {
                delete process.env.TEST_WORKER_INDEX;
            } else {
                process.env.TEST_WORKER_INDEX = original;
            }
        }
    });

    it('rejects a trailing newline on both sides of the wire contract', () => {
        // The PHP half of this is `RateLimitTestActorKeyTest::
        // test_an_actor_header_ending_in_a_newline_is_rejected` — same value, same
        // requirement, one engine per side. Neither half can see the other, which
        // is why the pair exists: the PHP engine is PCRE, this one is V8, and the
        // pattern is the only thing they share.
        const accepted = providerActorRegExp();

        expect(accepted.test('w1')).toBe(true);
        expect(accepted.test('w1\n')).toBe(false);
        expect(accepted.test('w1\nx')).toBe(false);
    });

    it('anchors the provider pattern with \\z, not with a PCRE $', () => {
        const pattern = providerActorPattern();

        // The anchor is the whole difference between the two engines, so it is
        // pinned as text rather than only as behaviour: `\z` at the end, and no
        // bare `$` at the end. A provider moved back to `$` fails HERE even if
        // somebody "fixes" the JS side to compensate — the compensation is the bug.
        expect(pattern).toMatch(/\\z$/);
        expect(pattern).not.toMatch(/\$$/);

        // Fail-closed on the OTHER direction of the same class of mistake. The
        // translation above handles exactly one construct; any further backslash
        // escape in the PHP literal would be read by V8 as something else (an
        // identity escape, or a valid JS-only shorthand), and the resulting
        // RegExp would be a second, silently different contract. With `\z`
        // accounted for, no backslash may remain.
        expect(pattern.replace(/\\z/g, '')).not.toMatch(/\\/);
    });

    it('names a worker and a process, and nothing else', () => {
        const original = process.env.TEST_WORKER_INDEX;
        try {
            process.env.TEST_WORKER_INDEX = '3';
            expect(throttleActorId()).toBe(`w3-p${process.pid}`);

            delete process.env.TEST_WORKER_INDEX;
            expect(throttleActorId()).toBe(`p${process.pid}`);
        } finally {
            if (original === undefined) {
                delete process.env.TEST_WORKER_INDEX;
            } else {
                process.env.TEST_WORKER_INDEX = original;
            }
        }
    });

    it('returns exactly one header, under the agreed name', () => {
        expect(throttleActorHeaders()).toEqual({ [THROTTLE_ACTOR_HEADER]: throttleActorId() });
    });

    it('every request context the E2E harness builds passes it under extraHTTPHeaders', () => {
        // RECURSIVE, and the depth is the point. The previous version walked two
        // directories by hand — `helpers/` and the top level — so `ownership-probe/`
        // was blind, and a brand-new unwired `request.newContext` in one of its five
        // files would have left this test green. MEASURED 2026-10-04 as the
        // counter-check: one `request.newContext({ baseURL })` appended to
        // `ownership-probe/probe-record.ts` turns this test red with that label.
        const files = inventory();

        // Comments are dropped first: the helper that DEFINES this header
        // documents the call shape in prose, and prose is not a call site. Good
        // enough here because the stripped text is only ever searched for
        // `request.newContext(`, which no comment in these files contains
        // (measured 2026-10-04 — every hit is a real call site). Only whole-line
        // `//` comments are dropped; a trailing one would survive and over-count
        // `total`, which fails loudly instead of passing silently.
        const contexts = files.flatMap((entry) => {
            const source = fs
                .readFileSync(entry.path, 'utf-8')
                .replace(/\/\*[\s\S]*?\*\//g, '')
                .replace(/^\s*\/\/.*$/gm, '');
            const total = (source.match(/request\.newContext\(/g) ?? []).length;
            const wired = (source.match(/extraHTTPHeaders: throttleActorHeaders\(\)/g) ?? []).length;

            return total > 0 ? [{ ...entry, total, wired }] : [];
        });

        // Fail-closed on the SCAN, not only on the wiring: `toBeGreaterThan(0)`
        // over the whole inventory would still be green if a whole branch of the
        // walk silently found nothing (wrong directory, renamed suffix), because
        // the helper files alone would satisfy it. Each branch must contribute.
        // MEASURED 2026-10-04: 47 files scanned — 10 helpers, 26 top-level specs,
        // 11 below that (6 top-level non-spec + the 5 files of `ownership-probe/`);
        // 18 contexts in 9 of them (3 helpers, 6 specs).
        for (const kind of ['helper', 'spec', 'other']) {
            expect(
                files.filter((entry) => entry.kind === kind).length,
                `no ${kind} file was scanned at all — the scan is broken, not clean`,
            ).toBeGreaterThan(0);
        }

        // …and the two branches that actually carry call sites must still carry
        // CONTEXTS, which is a stronger statement than "a file was read".
        expect(
            contexts.filter((entry) => entry.kind === 'helper').length,
            'no helper request context was scanned at all — the scan is broken, not clean',
        ).toBeGreaterThan(0);
        expect(
            contexts.filter((entry) => entry.kind === 'spec').length,
            'no spec-local request context was scanned at all — the scan is broken, not clean',
        ).toBeGreaterThan(0);

        for (const { label, total, wired } of contexts) {
            expect(wired, `${label} builds ${total} request context(s) but wires the throttle actor into ${wired}`).toBe(total);
        }
    });
});
