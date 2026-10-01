import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    LEGACY_CAPTURE_STORE_DIR,
    formatStaleStoreHint,
    inspectStaleCaptureStore,
} from '../../../scripts/stale-store.mjs';
import {
    LEGACY_CAPTURE_STORE_DIR as LEGACY_DIR_FROM_PATHS,
    committedStaleStorePaths,
    reportStaleStore,
    StaleCaptureStoreReporter,
    staleStoreBanner,
} from './stale-store-reporter';
import type { FullConfig, Suite } from '@playwright/test/reporter';
import { CAPTURE_STORE_DIR } from './store-paths';

/**
 * The stale ui-review store warning — the check that a checkout which still
 * carries a capture batch at the OLD path is told about it (Position 12).
 *
 * ## What is being prevented
 *
 * The store was moved by hand, out of `test-results/ui-review/` into
 * `test-artifacts/ui-review/`. Nothing versioned the move, because the directory
 * is gitignored. So every checkout with a store from before the move keeps a
 * **stale copy** at the old path, and nothing warned, migrated or cleaned it. A
 * reviewer who opens it sees a complete, plausible-looking, OUTDATED review batch
 * — full-page PNGs, section bands, sidecars with band counts and timestamps — with
 * nothing wrong-looking about it. That is the most expensive shape of legacy: not an
 * error, but a correctly-looking wrong finding, reviewable by accident because
 * opening the batch is the first step of §7's loop.
 *
 * ## Why a hint and not a cleanup
 *
 * A gitignored directory is forgotten the moment nobody looks at it, and deleting a
 * batch silently destroys the one copy somebody may still be comparing against.
 * So: detect, name, point at the current store, give the exit route — and stay
 * completely silent when there is nothing to say. The last part is load-bearing and
 * is asserted below, because a warning that fires on every run is a warning nobody
 * reads.
 *
 * ## Why this is a vitest file and not only a Playwright spec
 *
 * The check has to be a gate that runs on every change. `pnpm test:screenshots`
 * needs a dev server, a backend and a database, and its reporter only speaks once
 * per run. `vitest.config.ts` collects every `*.test.ts` under `scripts/` and under
 * `tests/`, so both halves of this — the pure check (`scripts/stale-store.mjs`) and
 * the reporter that speaks it (`tests/screenshots/helpers/stale-store-reporter.ts`)
 * — are driven here over a SYNTHETIC store in a temp dir.
 *
 * One constraint shaped that, and it is measured: this file must NOT import a
 * module with a runtime `@playwright/test` import. Under Vitest that import costs
 * **121 s** on this machine (plain `node` importing the same package: **526 ms**).
 * That is why the config's reporter registration is asserted from the config's
 * SOURCE rather than by importing `playwright.screenshots.config.ts` — importing it
 * would drag in `defineConfig` and `devices` and cost two minutes per run.
 */

const created: string[] = [];

/** A temp directory that cleans itself up, so a failed assertion leaves nothing behind. */
function makeDir(): string {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stale-store-test-'));
    created.push(dir);
    return dir;
}

afterEach(() => {
    vi.restoreAllMocks();
    while (created.length > 0) {
        const dir = created.pop();
        if (dir !== undefined) {
            fs.rmSync(dir, { recursive: true, force: true });
        }
    }
});

interface Capture {
    route: string;
    bands: number;
    capturedAt: string;
}

/**
 * Writes one capture into `<root>/<state>/<viewport>` — the shape
 * `helpers/capture-store.ts` produces: full-page PNG, sidecar, and an optional
 * `prev/` generation beside them.
 */
function writeCapture(root: string, state: string, viewport: string, capture: Capture): void {
    const dir = path.join(root, state, viewport);
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(
        path.join(dir, `${capture.route}.meta.json`),
        `${JSON.stringify({
            route: capture.route,
            state,
            viewport,
            file: `${capture.route}.png`,
            bands: capture.bands,
            scrollHeightPx: 2000,
            runKey: 'run-old',
            capturedAt: capture.capturedAt,
            dataset: {},
            entityIds: {},
            contentCount: null,
            pathname: `/${capture.route}`,
        })}\n`,
    );
    fs.writeFileSync(path.join(dir, `${capture.route}.png`), 'not-a-real-png');
}

interface StoreFixture {
    /** A directory that stands in for `frontend/`. */
    repoRoot: string;
    /** The committed relative paths, resolved inside `repoRoot`. */
    paths: { legacyRoot: string; legacyDir: string; currentRoot: string; currentDir: string };
}

/**
 * A checkout-shaped fixture: `<repoRoot>/test-results/ui-review` holding the stale
 * batch and `<repoRoot>/test-artifacts/ui-review` holding the current one.
 *
 * Shaped like the real thing rather than two loose temp dirs so that the reporter
 * can be exercised by mocking `process.cwd()` alone — the reporter resolves the
 * committed paths itself, and that resolution IS the thing under test.
 */
function makeCheckout(stale: Capture[], opts: { withPrevious?: boolean } = {}): StoreFixture {
    const repoRoot = makeDir();
    const legacyRoot = path.join(repoRoot, LEGACY_CAPTURE_STORE_DIR);
    const currentRoot = path.join(repoRoot, CAPTURE_STORE_DIR);
    fs.mkdirSync(legacyRoot, { recursive: true });
    fs.mkdirSync(currentRoot, { recursive: true });
    for (const capture of stale) {
        writeCapture(legacyRoot, 'filled', 'desktop', capture);
    }
    if (opts.withPrevious === true) {
        writeCapture(path.join(legacyRoot, 'filled', 'desktop', 'prev'), 'filled', 'desktop', {
            route: 'prev-generation',
            bands: 99,
            capturedAt: '2026-01-01T00:00:00.000Z',
        });
    }
    return {
        repoRoot,
        paths: { legacyRoot, legacyDir: LEGACY_CAPTURE_STORE_DIR, currentRoot, currentDir: CAPTURE_STORE_DIR },
    };
}

/** A current store the report can describe, so only the stale block differs. */
function fillCurrentStore(currentRoot: string): void {
    writeCapture(currentRoot, 'filled', 'desktop', {
        route: 'home',
        bands: 2,
        capturedAt: '2026-10-01T10:00:00.000Z',
    });
}

/**
 * The finding for a fixture that is known to hold one.
 *
 * `expect(finding).not.toBeNull()` asserts; it does not narrow, so every access
 * below it would be `possibly null`. A helper that THROWS is the narrowing form,
 * and it fails just as loudly on a fixture that unexpectedly holds nothing.
 */
function mustFind(fixture: StoreFixture): NonNullable<ReturnType<typeof inspectStaleCaptureStore>> {
    const finding = inspectStaleCaptureStore(fixture.paths);
    if (finding === null) {
        throw new Error(`expected a stale store at ${fixture.paths.legacyRoot}, found nothing`);
    }
    return finding;
}

/** The banner for a fixture that is known to hold one. */
function bannerOf(fixture: StoreFixture): string {
    return formatStaleStoreHint(mustFind(fixture));
}

/** The report script's own path, resolved from this test's directory. */
function scriptPath(): string {
    return path.resolve(process.cwd(), 'scripts/ui-review-captures.mjs');
}

const ONE_CAPTURE: Capture[] = [{ route: 'home', bands: 4, capturedAt: '2026-09-20T10:00:00.000Z' }];

describe('the stale store is detected, and only when there is something to say', () => {
    it('says nothing at all when the old path does not exist — the fresh-checkout case', () => {
        const fixture = makeCheckout([]);
        const finding = inspectStaleCaptureStore({ ...fixture.paths, legacyRoot: path.join(fixture.repoRoot, 'gone') });
        expect(finding).toBeNull();
        expect(staleStoreBanner({ ...fixture.paths, legacyRoot: path.join(fixture.repoRoot, 'gone') })).toBeNull();
    });

    it('says nothing when the old path is an EMPTY directory', () => {
        // Not a theoretical case: Playwright's own wipe leaves the directory behind
        // with nothing in it. A check keyed on "exists" would shout on every machine
        // that ever ran the old config, and a warning that always fires is a warning
        // nobody reads — the failure mode a hint has to design against.
        const fixture = makeCheckout([]);
        expect(inspectStaleCaptureStore(fixture.paths)).toBeNull();
    });

    it('says nothing when the old path is a FILE, which is not a store', () => {
        const file = path.join(makeDir(), 'ui-review');
        fs.writeFileSync(file, 'not a directory');
        expect(
            inspectStaleCaptureStore({ legacyRoot: file, legacyDir: 'x', currentRoot: '/y', currentDir: 'y' }),
        ).toBeNull();
    });

    it('describes what it found: captures, bands, files and the newest capture time', () => {
        // A reviewer deciding whether to open the old path needs to be told it holds a
        // full batch and how old it is, not merely that "something" is there. Those are
        // exactly the numbers `scripts/ui-review-captures.mjs` would have printed.
        const fixture = makeCheckout([
            { route: 'home', bands: 4, capturedAt: '2026-09-20T10:00:00.000Z' },
            { route: 'konto', bands: 3, capturedAt: '2026-09-28T09:12:44.000Z' },
        ]);
        const finding = mustFind(fixture);
        expect(finding.captures).toBe(2);
        expect(finding.totalBands).toBe(7);
        expect(finding.files, 'one PNG and one sidecar per capture').toBe(4);
        expect(finding.newestCapturedAt).toBe('2026-09-28T09:12:44.000Z');
    });

    it('counts prev/ as files but NOT as captures, so the batch is not doubled or mis-dated', () => {
        // `prev/` holds the PREVIOUS generation. Counting it as captures would double
        // the batch and report a date from a generation nobody would look at.
        const fixture = makeCheckout(ONE_CAPTURE, { withPrevious: true });
        const finding = mustFind(fixture);
        expect(finding.captures).toBe(1);
        expect(finding.totalBands).toBe(4);
        expect(finding.files, '2 for the current generation, 2 for the archived one').toBe(4);
        expect(finding.newestCapturedAt).toBe('2026-09-20T10:00:00.000Z');
    });

    it('a corrupt sidecar does not silence the warning', () => {
        // A batch nobody can parse is if anything a stronger reason to look at the
        // CURRENT store — the alternative (throwing) would turn a hint into a failed
        // report, which is the failure the other half of this project keeps measuring.
        const fixture = makeCheckout(ONE_CAPTURE);
        fs.writeFileSync(
            path.join(fixture.paths.legacyRoot, 'filled', 'desktop', 'broken.meta.json'),
            '{ not json',
        );
        const finding = mustFind(fixture);
        expect(finding.files).toBe(3);
        expect(finding.captures).toBe(2);
        expect(finding.totalBands, 'the unreadable sidecar contributes no band count').toBe(4);
    });
});

describe('the warning names BOTH paths and gives an exit route', () => {
    it('says which path is stale, which is current, and prints both absolutely', () => {
        // A hint that only names the old path leaves the reader to guess where the real
        // one is; one that only names the new one does not say which directory to stop
        // looking at.
        const fixture = makeCheckout(ONE_CAPTURE);
        const banner = bannerOf(fixture);
        expect(banner).toContain(LEGACY_CAPTURE_STORE_DIR);
        expect(banner).toContain(fixture.paths.legacyRoot);
        expect(banner).toContain(CAPTURE_STORE_DIR);
        expect(banner).toContain(fixture.paths.currentRoot);
    });

    it('says NOT to review it, and how to get rid of it', () => {
        // The position's own shape: "a hint with an exit route". Without the command
        // the warning is only an accusation; without "nicht reviewen" it is a note
        // somebody reads past.
        const fixture = makeCheckout(ONE_CAPTURE);
        const banner = bannerOf(fixture);
        expect(banner).toMatch(/NICHT REVIEWEN/);
        expect(banner).toContain(`rm -rf ${LEGACY_CAPTURE_STORE_DIR}`);
        expect(banner, 'it has to report what it found, or the reader cannot judge it').toContain(
            '1 Captures',
        );
        expect(banner).toContain('2026-09-20T10:00:00.000Z');
    });

    it('is louder than the report it precedes — a banner rule above and below', () => {
        const fixture = makeCheckout(ONE_CAPTURE);
        const lines = bannerOf(fixture).split('\n');
        const rule = lines[0];
        expect(rule).toMatch(/^!+$/);
        expect(
            rule.length,
            'a rule wider than 80 columns, so it cannot be read as a row of the report',
        ).toBeGreaterThan(80);
        expect(lines[lines.length - 2]).toBe(rule);
    });

    it('reportStaleStore writes once and reports that it wrote', () => {
        // The return value is how a caller tells "checked, nothing to say" from
        // "checked and shouted" without parsing the output.
        const fixture = makeCheckout(ONE_CAPTURE);
        const written: string[] = [];
        expect(reportStaleStore(fixture.paths, (text) => written.push(text))).toBe(true);
        expect(written).toHaveLength(1);
        expect(written[0]).toContain('VERALTETER');

        const empty = makeCheckout([]);
        const quiet: string[] = [];
        expect(reportStaleStore(empty.paths, (text) => quiet.push(text))).toBe(false);
        expect(quiet).toEqual([]);
    });
});

describe('the two declarations of the legacy path are the same string', () => {
    it('`store-paths.ts` and `stale-store.mjs` agree', () => {
        // They CANNOT share one declaration: `tsconfig.node.json` type-checks
        // `playwright.screenshots.config.ts` and has no `allowJs`, so the config's import
        // chain cannot reach into a `.mjs`, while `scripts/ui-review-captures.mjs` has to
        // stay runnable as plain `node scripts/*.mjs`. Two literals therefore, tied
        // together by a comparison here — a comment cannot tie two literals.
        expect(LEGACY_CAPTURE_STORE_DIR).toBe(LEGACY_DIR_FROM_PATHS);
    });

    it('the legacy path really is the old one, and the current one really is the new one', () => {
        // Asserting the RELATIONSHIP rather than restating it, so a future move of either
        // path is caught here too — the same shape `scripts/ui-review-captures.test.ts`
        // uses for `DEFAULT_DIR`.
        expect(LEGACY_CAPTURE_STORE_DIR).toBe('test-results/ui-review');
        expect(CAPTURE_STORE_DIR).toBe('test-artifacts/ui-review');
        expect(LEGACY_CAPTURE_STORE_DIR).not.toBe(CAPTURE_STORE_DIR);
    });

    it("the reporter's committed paths are this checkout's, resolved absolutely", () => {
        const paths = committedStaleStorePaths();
        expect(paths.legacyDir).toBe(LEGACY_CAPTURE_STORE_DIR);
        expect(paths.currentDir).toBe(CAPTURE_STORE_DIR);
        expect(path.isAbsolute(paths.legacyRoot)).toBe(true);
        expect(path.isAbsolute(paths.currentRoot)).toBe(true);
        expect(paths.legacyRoot.endsWith(LEGACY_CAPTURE_STORE_DIR)).toBe(true);
        expect(paths.currentRoot.endsWith(CAPTURE_STORE_DIR)).toBe(true);
    });
});

/**
 * Calls `onBegin` the way Playwright does.
 *
 * Both arguments are ignored by the reporter — that is the point of the signature
 * `onBegin(config, suite)`, which is NOT the `FullResult` many reporters receive —
 * so they are passed as explicit casts rather than faked objects: a fake
 * `FullResult` would be a second, wrong statement about the signature.
 */
function fireOnBegin(reporter: StaleCaptureStoreReporter): void {
    reporter.onBegin(undefined as unknown as FullConfig, undefined as unknown as Suite);
}

describe('the screenshot run says it out loud', () => {
    const REPORTER_ENTRY = "['./tests/screenshots/helpers/stale-store-reporter.ts']";

    it('the reporter is REGISTERED FIRST — otherwise the check would be dead code', () => {
        // Read from the config's SOURCE, not by importing it: importing
        // `playwright.screenshots.config.ts` pulls in `defineConfig`/`devices` from
        // `@playwright/test`, which costs 121 s under Vitest (measured). The entry is a
        // tuple whose NAME is a path, so `tsc` does not follow it either — which is
        // exactly why this structural check is the one place that can notice the path
        // being dropped or typo'd.
        const config = fs.readFileSync(
            path.resolve(process.cwd(), 'playwright.screenshots.config.ts'),
            'utf8',
        );
        const start = config.indexOf('reporter: [');
        expect(start, 'the screenshot config has no reporter array to check').toBeGreaterThan(-1);
        const end = config.indexOf('\n    ],', start);
        expect(end, 'the reporter array is not terminated the way this check reads it').toBeGreaterThan(start);
        const block = config.slice(start, end);

        const at = block.indexOf(REPORTER_ENTRY);
        expect(at, `the screenshot config must register ${REPORTER_ENTRY}`).toBeGreaterThan(-1);
        expect(
            block.indexOf("['html'"),
            'it has to come BEFORE the HTML reporter — it is the only one writing to the terminal',
        ).toBeGreaterThan(at);
        // And the file it names must be the one under test, or the wiring is theatre.
        expect(
            fs.existsSync(path.resolve(process.cwd(), './tests/screenshots/helpers/stale-store-reporter.ts')),
        ).toBe(true);
    });

    it('the reporter writes the banner to stdout, once, and names both paths', () => {
        // The end-to-end shape a reviewer experiences. `process.cwd()` is mocked at a
        // synthetic checkout rather than using this one, because a developer's
        // `test-results/ui-review/` may hold a REAL stale batch that a test has no
        // business reading or deleting.
        const fixture = makeCheckout([
            { route: 'home', bands: 4, capturedAt: '2026-09-20T10:00:00.000Z' },
            { route: 'konto', bands: 2, capturedAt: '2026-09-21T10:00:00.000Z' },
        ]);
        vi.spyOn(process, 'cwd').mockReturnValue(fixture.repoRoot);
        const spy = vi.spyOn(process.stdout, 'write').mockImplementation(() => true);

        fireOnBegin(new StaleCaptureStoreReporter());

        const printed = spy.mock.calls.map((call) => String(call[0]));
        expect(printed).toHaveLength(1);
        expect(printed[0]).toContain('VERALTETER');
        expect(printed[0]).toContain('NICHT REVIEWEN');
        expect(printed[0]).toContain('2 Captures');
        expect(printed[0]).toContain(LEGACY_CAPTURE_STORE_DIR);
        expect(printed[0]).toContain(CAPTURE_STORE_DIR);
    });

    it('the reporter stays completely silent when there is no stale store', () => {
        // Not "one line" and not "one blank line": NOTHING. A normal run has to be
        // indistinguishable from before this check existed — mocked at a synthetic
        // checkout so the assertion cannot be broken by whatever THIS one holds.
        const fixture = makeCheckout([]);
        vi.spyOn(process, 'cwd').mockReturnValue(fixture.repoRoot);
        const spy = vi.spyOn(process.stdout, 'write').mockImplementation(() => true);

        fireOnBegin(new StaleCaptureStoreReporter());

        expect(spy).not.toHaveBeenCalled();
    });
});

describe('the report script names the stale store before the numbers', () => {
    function run(args: string[]) {
        return spawnSync(process.execPath, [scriptPath(), ...args], { encoding: 'utf8' });
    }

    it('prints the block, names both paths, and still exits 0', () => {
        // Exit code is unchanged on purpose: the script's own contract is "exit 0
        // always — a difference in band counts is information for a human". A stale
        // store is information for a human too, and a non-zero exit would make a
        // warning that appears on a reviewer's machine look like a broken harness.
        const fixture = makeCheckout(ONE_CAPTURE);
        fillCurrentStore(fixture.paths.currentRoot);

        const result = run([
            '--dir',
            fixture.paths.currentRoot,
            '--legacy',
            fixture.paths.legacyRoot,
        ]);
        expect(result.status).toBe(0);
        expect(result.stdout).toContain('VERALTETER ui-review-STORE');
        expect(result.stdout).toContain('NICHT REVIEWEN');
        expect(result.stdout).toContain(`rm -rf ${fixture.paths.legacyRoot}`);
        expect(result.stdout).toContain(fixture.paths.currentRoot);
        expect(result.stdout, 'it has to come BEFORE the report, or it reads as a footnote').toMatch(
            /^!!!!/,
        );
        expect(result.stdout.indexOf('VERALTETER')).toBeLessThan(result.stdout.indexOf('Store        :'));
    });

    it('says NOTHING when the old path is empty, and the report is unchanged', () => {
        const fixture = makeCheckout([]);
        fillCurrentStore(fixture.paths.currentRoot);

        const result = run([
            '--dir',
            fixture.paths.currentRoot,
            '--legacy',
            fixture.paths.legacyRoot,
        ]);
        expect(result.status).toBe(0);
        expect(result.stdout).not.toContain('VERALTETER');
        expect(result.stdout).not.toContain('NICHT REVIEWEN');
        expect(result.stdout, 'the first line is still the report itself').toMatch(/^Store {8}: /);
        expect(result.stdout).toContain('Bänder total : 2');
    });

    it('--json keeps stdout parsable and puts the finding IN the payload', () => {
        // The banner goes to STDERR under `--json`. Printing it to stdout would make the
        // output unparsable — which is its own kind of "looks fine, is wrong" — and
        // omitting it from the payload would make a machine consumer blind to it.
        const fixture = makeCheckout(ONE_CAPTURE);
        fillCurrentStore(fixture.paths.currentRoot);

        const result = run([
            '--dir',
            fixture.paths.currentRoot,
            '--legacy',
            fixture.paths.legacyRoot,
            '--json',
        ]);
        expect(result.status).toBe(0);
        expect(result.stderr).toContain('VERALTETER');
        const payload = JSON.parse(result.stdout) as {
            staleStore: { legacyDir: string; captures: number } | null;
            rows: unknown[];
        };
        const stale = payload.staleStore;
        if (stale === null) {
            throw new Error('the payload carried no stale store even though the banner was printed');
        }
        expect(stale.legacyDir).toBe(fixture.paths.legacyRoot);
        expect(stale.captures).toBe(1);
        expect(payload.rows).toHaveLength(1);
    });

    it('--json says null when there is nothing stale, rather than omitting the key', () => {
        // A key that appears only in the bad case is a key a consumer cannot
        // distinguish from "not implemented".
        const fixture = makeCheckout([]);
        fillCurrentStore(fixture.paths.currentRoot);

        const result = run([
            '--dir',
            fixture.paths.currentRoot,
            '--legacy',
            fixture.paths.legacyRoot,
            '--json',
        ]);
        const payload = JSON.parse(result.stdout) as { staleStore: unknown };
        expect(payload.staleStore).toBeNull();
    });

    it('--legacy needs a value, and the default is "the committed old path"', async () => {
        // The default has to be null (resolved against cwd) rather than a second
        // literal: a literal here would be a THIRD declaration of the old path, and the
        // agreement test above would no longer cover the value that is actually used.
        const { parseArgs } = await import('../../../scripts/ui-review-captures.mjs');
        expect(parseArgs([]).legacy).toBeNull();
        expect(parseArgs(['--legacy', 'somewhere']).legacy).toBe('somewhere');
        expect(() => parseArgs(['--legacy'])).toThrow(/--legacy braucht einen Wert/);
    });
});