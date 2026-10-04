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
 * The three things that can silently break here are all invisible to a
 * type-checker, and two of them were real:
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
 * Two accessors, no parameters, on purpose: `tests/e2e/**` is linted with the
 * plain-ES2020 parser, where a parameter annotation is a parse error, and JSDoc
 * does not type a parameter in a `.ts` file either (TS7006, measured — see
 * `eslint.config.js`). A zero-parameter function needs neither.
 */
function providerHeaderName() {
    const match = fs.readFileSync(PROVIDER, 'utf-8').match(/const TEST_ACTOR_HEADER = '([^']*)'/);

    if (!match) {
        throw new Error(`TEST_ACTOR_HEADER not found in ${PROVIDER} — the cross-language contract this file pins cannot be read`);
    }

    return match[1];
}

/**
 * The provider's accepted actor shape as a JS RegExp, built from the pattern
 * string itself rather than re-parsed into alphabet + length: the PHP source is
 * already a regex body (`^[A-Za-z0-9._-]{1,32}$`), and re-deriving its parts
 * here would be a second parser to keep in sync for no gain.
 */
function providerActorRegExp() {
    // `\/` … `\/` around the body: the PHP literal is delimited by slashes, and a
    // `[^']*` capture would swallow the closing delimiter as well (measured —
    // the extracted RegExp then ended in a literal `\/` and matched nothing).
    const match = fs.readFileSync(PROVIDER, 'utf-8').match(/const TEST_ACTOR_PATTERN = '\/(.+)\/'\s*;/);

    if (!match) {
        throw new Error(`TEST_ACTOR_PATTERN not found in ${PROVIDER} — the cross-language contract this file pins cannot be read`);
    }

    return new RegExp(match[1]);
}

describe('throttle actor header', () => {
    it('uses the header name the backend reads', () => {
        expect(THROTTLE_ACTOR_HEADER).toBe(providerHeaderName());
    });

    it('produces actor ids inside the alphabet and length the backend accepts', () => {
        // `/^[A-Za-z0-9._-]{1,32}$/` — read from the provider, not retyped here.
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
        const helpersDir = path.join(HERE, 'helpers');

        // BOTH call-site kinds are scanned, and they are scanned as ONE inventory:
        // the helper modules (the fixture creators, the original burst source) and
        // the specs that build their own context inline. Scanning only the helpers
        // left the follow-up's eleven spec-local wirings ungated — a green test
        // beside eleven unwired contexts.
        //
        // `.spec.ts` and NOT `.test.ts`: this file is itself a `.test.ts` and
        // contains both patterns in prose, so scanning it would count the
        // checker. `helpers/throttle-actor.ts` IS scanned, and passes because its
        // occurrences live in block comments — see the strip below.
        const files = [
            ...fs
                .readdirSync(helpersDir)
                .filter((file) => file.endsWith('.ts'))
                .map((file) => ({ dir: helpersDir, kind: 'helper', file, label: `helpers/${file}` })),
            ...fs
                .readdirSync(HERE)
                .filter((file) => file.endsWith('.spec.ts'))
                .map((file) => ({ dir: HERE, kind: 'spec', file, label: file })),
        ];

        // Comments are dropped first: the helper that DEFINES this header
        // documents the call shape in prose, and prose is not a call site. Good
        // enough here because the stripped text is only ever searched for
        // `request.newContext(`, which no comment in these files contains
        // (measured 2026-10-04 — every hit is a real call site). Only whole-line
        // `//` comments are dropped; a trailing one would survive and over-count
        // `total`, which fails loudly instead of passing silently.
        const contexts = files.flatMap((entry) => {
            const source = fs
                .readFileSync(path.join(entry.dir, entry.file), 'utf-8')
                .replace(/\/\*[\s\S]*?\*\//g, '')
                .replace(/^\s*\/\/.*$/gm, '');
            const total = (source.match(/request\.newContext\(/g) ?? []).length;
            const wired = (source.match(/extraHTTPHeaders: throttleActorHeaders\(\)/g) ?? []).length;

            return total > 0 ? [{ ...entry, total, wired }] : [];
        });

        // Fail-closed on the SCAN, not only on the wiring: `toBeGreaterThan(0)`
        // over the whole inventory would still be green if the spec-file pass
        // silently found nothing (wrong directory, renamed suffix), because the
        // three helper files alone would satisfy it. Each pass must contribute.
        // MEASURED 2026-10-04: 18 contexts, 9 files — 3 helpers, 6 specs.
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