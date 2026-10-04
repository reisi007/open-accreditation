import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import {
    E2E_PURGE_MARKERS,
    E2E_PURGE_MARKER_ENTRIES,
    E2E_PURGE_SWEEPS,
} from './helpers/admin-data';
import { E2E_OWNED_FK_EDGES, E2E_OWNED_TEARDOWN } from './helpers/ownership';
import {
    ALL_REVIEW_NAMES,
    ALL_REVIEW_SLUGS,
    ALL_REVIEW_USER_PREFIXES,
} from '../screenshots/helpers/fixture-names';

/**
 * Every `.ts` file of BOTH harnesses, as paths relative to the frontend root,
 * sorted. Recursive, and **iteratively**, so that collecting them needs no nested
 * function — and **parameter-free**, see below.
 *
 * ## Why there is no `walk(dir)` helper, and no JSDoc types either
 *
 * This directory is linted with the PLAIN-JS parser (`eslint.config.js` gives
 * `tests/e2e/**` no TS parser) but is still built by the strict `tsc -b`. So a
 * parameter *annotation* is an ESLint parse error, and an un-annotated parameter
 * is an implicit `any` — a hard `tsc` error. A JSDoc `@param` does not bridge
 * the gap either: TypeScript ignores JSDoc types in `.ts` files. (Measured while
 * building this: a fully JSDoc-typed helper produced six `TS7006` errors.)
 *
 * So the rule this file follows is the one `helpers/admin-data.ts` has always
 * followed — **no function parameters at all** — and the walk is a work list
 * rather than a recursion. Both harness trees are needed (the logo invariant is
 * cross-directory), so one constant covers both and the E2E-only set filters it.
 *
 * The fixtures in `helpers/admin-data.ts` are in scope on purpose: they construct
 * names as well, and two of the three prefixes F1 found leaked live there.
 */
const HARNESS_TS_FILES = (() => {
    const pending = ['tests/e2e', 'tests/screenshots'].map((root) => path.resolve(process.cwd(), root));
    const found = [];
    for (;;) {
        const dir = pending.pop();
        if (dir === undefined) {
            break;
        }
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            if (entry.isDirectory()) {
                pending.push(full);
            } else if (entry.name.endsWith('.ts')) {
                found.push(path.relative(process.cwd(), full));
            }
        }
    }
    return found.sort();
})();

/** The E2E suite's own sources — the name scan is about this suite alone. */
const E2E_TS_FILES = HARNESS_TS_FILES.filter((file) => file.startsWith('tests/e2e/'));

/**
 * A file's source with its COMMENTS removed, so a docblock that merely NAMES a
 * function cannot vouch for calling it.
 *
 * This is not defensive tidiness — it is a measured false pass. The first version
 * of the logo guard below searched the raw text for
 * `acquirePrimaryMandantLogoLock(`, and the F3 fix's own docblock in
 * `dataset.ts` contains that exact string, so DELETING the lock left the guard
 * green. That is F2's shape reproduced in a new test: a comment asserting a
 * guarantee no code provides.
 *
 * Deliberately conservative, and the limit is stated rather than assumed: block
 * comments and whole-line `//` comments are removed; a `//` trailing a statement
 * on the same line, and a comment-opener or -closer sequence inside a string
 * literal, are not. Both would need a real tokenizer, and neither occurs in the
 * files this scans — a `//` in these harnesses is a URL inside a string on a
 * code line, which is exactly the case the whole-line-only rule leaves alone.
 * (Writing the closing delimiter literally in that first docblock ended the
 * comment early and cost a debugging round — the same class of trap the stripper
 * exists to avoid.)
 *
 * The two-step replace is written out at each call site rather than wrapped in a
 * helper: a helper takes a parameter, and this directory allows none.
 */
const BLOCK_COMMENT = /\/\*[\s\S]*?\*\//g;
const LINE_COMMENT = /^\s*\/\/.*$/gm;

/**
 * Every stamped name prefix the E2E suite constructs, mapped to its source
 * locations. A template literal counts only when its literal head carries the E2E
 * or portal namespace, so `${PORTAL_FIXTURE_KEY}`-style building blocks and
 * ordinary prose do not enter the set; the source is comment-stripped first, for
 * the reason `BLOCK_COMMENT` carries.
 *
 * At module scope because TWO `describe` blocks need it: the coverage guard below,
 * and the marker-typo check in the sweep block — the second one needs the same
 * list to know which markers are still alive.
 */
function stampedNames() {
    const found = new Map();
    for (const file of E2E_TS_FILES) {
        const source = fs.readFileSync(path.resolve(process.cwd(), file), 'utf8');
        const lines = source.replace(BLOCK_COMMENT, '').replace(LINE_COMMENT, '').split('\n');
        for (let index = 0; index < lines.length; index += 1) {
            for (const match of lines[index].matchAll(/`([^`]*\$\{[^`]*)`/g)) {
                const prefix = match[1].split('${')[0];
                if (!/\b(E2E |Portal-)/.test(prefix)) {
                    continue;
                }
                if (!found.has(prefix)) {
                    found.set(prefix, []);
                }
                found.get(prefix).push(`${file}:${index + 1}`);
            }
        }
    }
    return found;
}

/**
 * The invariant that keeps the two Playwright suites out of each other's rows.
 *
 * ## The damage this prevents, all of it MEASURED
 *
 * - One full E2E run deleted the ui-review dataset's badge templates
 *   (321/322 → 0) — the E2E teardown sweeps `E2E Ausweis*`, and the review's
 *   templates were named `E2E Ausweis-… ui-review`.
 * - One screenshot run broke the following E2E run at `badge.spec.ts:70` with a
 *   Playwright StrictMode violation, for the same reason in the other direction.
 * - The screenshot run additionally called `purgeAllE2EArtifacts()` itself, so a
 *   design-QA pass silently reaped the functional suite's leftovers.
 * - `E2E Akkreditierung ui-review` is a SUBSTRING of `E2E Akkreditierung
 *   ui-review Druck`, and `hasText` is a substring match: the desktop `apply`
 *   capture clicked the Druck accreditation (id 201) while the mobile one loaded
 *   id 200 by URL. Two artifacts of one route, two datasets, invisible in the
 *   sidecars.
 * - `badge.spec.ts`'s `afterAll` swept `E2E Ausweis*` from the Mobile Chrome
 *   worker — where the test is SKIPPED but the hook still runs — and deleted the
 *   template the Desktop Chrome worker was about to export with, which is why
 *   `badge.spec.ts:80` was 2/2 red on a clean database and green in isolation.
 *
 * ## Why this is a test and not a review note
 *
 * A namespace is a promise about the FUTURE: the next fixture somebody adds
 * could sit inside the other suite's marker again, and nothing in either suite
 * would notice until a run destroyed something. So the promise is checked here,
 * in the suite that runs in CI, against the OTHER suite's own marker table — a
 * table that has no second copy, because `purgeAllE2EArtifacts()` reads its
 * markers from the very constant imported here.
 *
 * Deliberately browser-free and API-free: no dev server, no database, no login.
 * It must be the cheapest possible check, or it will be skipped.
 *
 * ## And the direction it used to be blind in
 *
 * Everything in this first `describe` asks **review → E2E**: can the review's
 * names be reached by the E2E purge? It says nothing about **E2E → E2E**: can the
 * teardown reach the rows the E2E specs themselves create? This file was cited as
 * the proof that the marker table was complete, and the citation was worth
 * nothing — no test in the repo ever compared the table with the names the suite
 * constructs.
 *
 * F1 measured the cost on a database that had absorbed many full runs: **54
 * leaked `teams` and 53 leaked `venues`, and not one row matching the
 * `E2E Heimverein ` marker the table did list.** The two `describe` blocks below
 * ask the other question, and the second of them also pins the sweep ORDER and
 * the per-KIND wiring, which no test covered either.
 */
test.describe('the E2E suite and the ui-review harness own disjoint namespaces', () => {
    const nameMarkers = [
        ...E2E_PURGE_MARKERS.badgeTemplateNames,
        ...E2E_PURGE_MARKERS.badgeImageNames,
        ...E2E_PURGE_MARKERS.categoryNames,
        ...E2E_PURGE_MARKERS.eventTitles,
        ...E2E_PURGE_MARKERS.eventCompetitions,
        ...E2E_PURGE_MARKERS.teamNames,
        ...E2E_PURGE_MARKERS.venueNames,
        ...E2E_PURGE_MARKERS.mandantNames,
    ];
    const slugMarkers = E2E_PURGE_MARKERS.mandantSlugs;

    test('no review fixture name can be matched by an E2E purge marker', () => {
        // `startsWith`, because that is how the teardown matches. A review name
        // inside the E2E namespace means one of the two suites can delete the
        // other's fixture.
        for (const name of ALL_REVIEW_NAMES) {
            for (const marker of nameMarkers) {
                expect(
                    name.startsWith(marker),
                    `review fixture name "${name}" starts with the E2E purge marker "${marker}" — ` +
                        'the E2E teardown would reclaim it. Rename it in tests/screenshots/helpers/fixture-names.ts.',
                ).toBe(false);
            }
        }
    });

    test('no review slug or user local-part can be matched by an E2E purge marker', () => {
        for (const slug of [...ALL_REVIEW_SLUGS, ...ALL_REVIEW_USER_PREFIXES]) {
            for (const marker of slugMarkers) {
                expect(
                    slug.startsWith(marker),
                    `review fixture "${slug}" starts with the E2E slug marker "${marker}"`,
                ).toBe(false);
            }
        }
    });

    test('the two marker tables are not secretly the same value', () => {
        // A guard against the degenerate fix: making both prefixes identical
        // would make every check above pass for the wrong reason.
        expect(ALL_REVIEW_NAMES.length).toBeGreaterThan(0);
        expect(nameMarkers.length).toBeGreaterThan(0);
        for (const name of ALL_REVIEW_NAMES) {
            expect(nameMarkers).not.toContain(name);
        }
    });

    test('no review name contains another review name', () => {
        // The `apply` failure mode, as a property rather than as a story: a
        // `hasText` / `startsWith` / `includes` locator can only ever be
        // ambiguous if one name contains another. Two names that merely share a
        // prefix are fine; a name INSIDE another is not.
        //
        // The whole name set is checked, not just the names the manifest
        // addresses today. MEASURED: a narrowed version of this rule listed only
        // the addressed names and stayed GREEN while
        // `… Akkreditierung Presse Ausdruck` sat next to `… Akkreditierung
        // Presse` — the collision that produced two different `apply` captures.
        for (const name of ALL_REVIEW_NAMES) {
            for (const other of ALL_REVIEW_NAMES) {
                if (name === other) {
                    continue;
                }
                expect(
                    other.includes(name),
                    `review name "${name}" is contained in "${other}" — a substring locator could hit ` +
                        'both, which is how the desktop `apply` capture ended up showing a different ' +
                        'accreditation than the mobile one. Pick two names where neither contains the ' +
                        'other (tests/screenshots/helpers/fixture-names.ts).',
                ).toBe(false);
            }
        }
    });

    test('a spec never matches fixture names by prefix', () => {
        // The rule that would have caught `badge.spec.ts`'s `afterAll` on the day
        // it was written: prefix matching on entity names belongs to the SERIAL
        // teardown (`helpers/admin-data.ts`, the only file allowed to hold the
        // marker table), never to a per-worker spec hook. A worker-scoped prefix
        // sweep reaches into other workers' fixtures — the measured
        // 2/2-red-on-a-clean-database bug.
        const specDir = path.resolve(process.cwd(), 'tests/e2e');
        const offenders = [];
        for (const file of fs.readdirSync(specDir)) {
            if (!file.endsWith('.spec.ts')) {
                continue;
            }
            const source = fs.readFileSync(path.join(specDir, file), 'utf8');
            if (/\.startsWith\((['"`])E2E/.test(source)) {
                offenders.push(file);
            }
        }
        expect(
            offenders,
            `these specs match entity names by an "E2E" prefix: ${offenders.join(', ')}. Delete by id; ` +
                'the serial globalTeardown reclaims the markers listed in BADGE_TEMPLATE_PURGE_PREFIXES.',
        ).toEqual([]);
    });
});

/**
 * ## The direction that was missing
 *
 * Everything above asks "can the review's names be reached by the E2E purge?"
 * — review → E2E. The direction that was broken is the mirror image: **can the
 * E2E purge reach the rows the E2E specs create?** `namespace-isolation.spec.ts`
 * used to be cited as the proof that the marker table was complete, and the
 * citation was worth nothing: nothing in the repo ever compared the table with
 * the names the suite actually constructs.
 *
 * F1 measured the gap on a dev database that had absorbed many full runs:
 * **54 leaked `teams`, 53 leaked `venues`, 0 `badge_templates`, 0 `categories`,
 * 0 `events`, 0 `mandants`** — and **not one** row matching the `E2E Heimverein `
 * marker the table *did* list. Every leaked row was `E2E Team ${suffix}` or
 * `E2E Heimstadion ${suffix}`, and the venue half of it was 409'd into silence.
 *
 * So: a name this suite constructs must be reclaimable, and the check scans the
 * suite for the names rather than trusting a list of what someone remembered.
 *
 * ## Why only STAMPED names are scanned
 *
 * A name carrying a per-run stamp (`E2E Team ${suffix}`) is **unaddressable by
 * construction**: the spec throws the id away and the stamp is unguessable, so
 * once such a row survives, nothing can ever find it again — not a human, not a
 * later run, not a hand-written `DELETE`. A name WITHOUT a stamp (`E2E
 * Heimstadion`, `E2E Portal Arena`) is at least findable by name, which is a
 * weaker but not absent guarantee.
 *
 * That distinction is what keeps this scan small enough to be strict. Scanning
 * every string that merely starts with `E2E ` also collects the suite's user
 * display names (`E2E Antragsteller A`, `E2E Auth User`), a profile field
 * (`E2E Medien GmbH`) and this file's own test titles — none of which are
 * entity rows, and one of which is not reclaimable at all because there is no
 * DELETE route for users (a separate board position, deliberately untouched
 * here). Restricting to stamped template literals leaves **29 distinct
 * prefixes, every one of them a real entity name**, with no exclusion list that
 * could quietly grow to hide a real gap.
 *
 * The scan over-approximates in the safe direction: it also matches the teardown's
 * own `startsWith` matchers (`admin-data.ts:828-829`), which is why two
 * marker-looking entries appear that are not constructions. Requiring a marker
 * for those is harmless — both are registered — whereas missing a construction
 * is exactly the F1 failure.
 */
test.describe('every name the E2E suite constructs is reclaimable by the teardown', () => {
    test('THE GUARD: no stamped name is missing from the marker table', () => {
        const unregistered = [];
        for (const [prefix, sites] of stampedNames()) {
            const matched = Object.entries(E2E_PURGE_MARKERS).filter(([, markers]) =>
                markers.some((marker) => prefix.startsWith(marker)),
            );
            if (matched.length === 0) {
                unregistered.push(`  "${prefix}"  (${sites.join(', ')})`);
            }
        }
        expect(
            unregistered,
            'These names are constructed by the E2E suite but matched by NO marker in ' +
                'E2E_PURGE_MARKERS, so the serial teardown can never reclaim them. A stamped ' +
                'name is unaddressable by construction — the id is thrown away and the suffix ' +
                'is unguessable — so this is a permanent leak, one row per run. Register the ' +
                'prefix under the kind it names (tests/e2e/helpers/admin-data.ts):\n' +
                unregistered.join('\n'),
        ).toEqual([]);
    });

    test('the scan is not vacuous: it finds the whole namespace', () => {
        // If the pattern ever stopped matching, the guard above would pass
        // vacuously — the exact failure this file was cited for. Pin the two ends
        // of the result: it must be many names, and it must contain the prefixes
        // that actually leaked.
        const names = stampedNames();
        expect(names.size).toBeGreaterThanOrEqual(20);
        expect([...names.keys()]).toContain('E2E Team ');
        expect([...names.keys()]).toContain('E2E Heimstadion ');
        expect([...names.keys()]).toContain('E2E Ausweis ');
        // And the E2E kinds the spec directories are NOT allowed to invent:
        // a user display name is not an entity, and a stamp on one is a leak
        // this teardown cannot fix. Kept explicit so the decision is visible.
        expect([...names.keys()]).not.toContain('E2E Antragsteller A');
    });

    test('the scan covers the fixture helpers, not only the spec files', () => {
        // A scan limited to `*.spec.ts` would have found `E2E Heimverein ` and
        // missed the two shared venue fixtures the helpers create, i.e. it would
        // have been one file-glob away from being wrong in the silent direction.
        // The prefixes the helpers construct, collected with plain loops: a
        // `flatMap` over a `Map`'s entries would need a typed parameter, which this
        // directory cannot have.
        const fromHelpers = [];
        for (const [prefix, sites] of stampedNames()) {
            for (const site of sites) {
                if (site.startsWith('tests/e2e/helpers/')) {
                    fromHelpers.push(prefix);
                }
            }
        }
        expect(
            fromHelpers.sort(),
            'the shared helpers construct stamped names too, and they are the ones the ' +
                'screenshot harness and portal spec depend on',
        ).toContain('E2E Heimverein ');
    });
});

/**
 * ## The plan, not a second copy of the plan
 *
 * The scan above asks "is this name registered?". It cannot ask "registered for
 * the RIGHT KIND?" — a team marker filed under `venueNames` would satisfy it and
 * still leak, because the venue sweep never sees team rows. That second property
 * is what these tests pin, and they pin it by **executing** `E2E_PURGE_SWEEPS`
 * and the marker table — the same data the real purge runs — rather than by
 * restating the sweeps in test code, which would agree with a broken purge for the
 * wrong reason.
 *
 * ## Two rules here that are prose, and one that is not
 *
 * The table docblock claims a marker may be a blanket prefix sweep only when its
 * column belongs to exactly one swept collection. That claim is **not**
 * machine-checked: the two blanket markers (`badgeImageNames: ['e2e-']` and
 * `mandantSlugs: ['e2e-']`) are legal precisely because `original_name` and
 * `slug` are columns nothing else is matched on, and expressing that as a test
 * needs a definition of "blanket" that in turn needs to know what a name outside
 * the kind looks like. A first attempt at it — "no two kinds may share a marker" —
 * was measured, went red on the two markers that are correct by that rule, and
 * was deleted rather than weakened. So: stated, not checked.
 *
 * What IS checked is the direction that bites: that a marker is alive at all. A
 * typo in a marker matches nothing, deletes nothing, and leaves the run green,
 * which is the quietest failure in this whole file.
 */
test.describe('the teardown sweep is ordered and kind-accurate', () => {
    /**
     * The marker table as `[kind, markers]` pairs, read from the SAME export the
     * purge matches against.
     *
     * `Object.entries` rather than `E2E_PURGE_MARKERS[kind]`: the kinds are
     * dynamic (they come from the sweep table), and indexing a fixed object type
     * with a `string` is a hard `tsc` error in this directory — and the lookup
     * must be able to answer "is this kind even in the table?" rather than let an
     * `undefined` flow on into a `.some()`.
     */
    const MARKER_TABLE = E2E_PURGE_MARKER_ENTRIES;

    test('every sweep reads a marker kind that exists and is non-empty', () => {
        // An empty list is a silent no-op: the sweep walks its collection and
        // deletes nothing, forever, and reports success.
        const dead = [];
        for (const sweep of E2E_PURGE_SWEEPS) {
            for (const matcher of sweep.matchers) {
                let markers = null;
                for (const entry of MARKER_TABLE) {
                    if (entry[0] === matcher.markerKind) {
                        markers = entry[1];
                    }
                }
                if (markers === null || markers.length === 0) {
                    dead.push(`${sweep.resource}.${matcher.field} → ${matcher.markerKind}`);
                }
            }
        }
        expect(dead, 'a sweep whose marker kind is missing or empty matches nothing and deletes nothing').toEqual([]);
    });

    test('every marker kind in the table is read by some sweep', () => {
        // The docblock calls adding a marker "the same act as teaching the
        // teardown to reclaim it". That is only true if the table has no entry a
        // sweep never looks at — otherwise the edit looks like coverage and is
        // not.
        const read = new Set(E2E_PURGE_SWEEPS.flatMap((sweep) => sweep.matchers.map((m) => m.markerKind)));
        const unread = MARKER_TABLE.map(([kind]) => kind).filter((kind) => !read.has(kind));
        expect(unread, 'marker kinds no sweep reads — registered, but never reclaimed').toEqual([]);
    });

    test('a row named with a sweep\'s own marker is matched by that sweep', () => {
        // The matching rule, restated from `purgeAllE2EArtifacts` — the table and
        // the wiring are the single-source part, and this is the one-line
        // comparison on top of them. The `E2E_PURGE_MARKER_ENTRIES` import is what
        // keeps it honest: a marker moved to the wrong kind stops matching here,
        // which is the F1 failure expressed as a property.
        for (const sweep of E2E_PURGE_SWEEPS) {
            for (const matcher of sweep.matchers) {
                let marker = null;
                for (const entry of MARKER_TABLE) {
                    if (entry[0] === matcher.markerKind) {
                        marker = entry[1][0];
                    }
                }
                expect(marker, `${matcher.markerKind} is not in the table`).not.toBeNull();
                const row = { id: 1, [matcher.field]: `${marker}probe` };
                const matched = sweep.matchers.some((other) => {
                    const value = row[other.field];
                    if (value === null || value === undefined) {
                        return false;
                    }
                    const text = String(value);
                    return MARKER_TABLE.some(
                        ([kind, markers]) => kind === other.markerKind && markers.some((one) => text.startsWith(one)),
                    );
                });
                expect(
                    matched,
                    `${sweep.resource}.${matcher.field} must match its own marker "${marker}"`,
                ).toBe(true);
            }
        }
    });

    test('a row outside the namespace is matched by NO sweep (no over-deletion)', () => {
        // The other direction, and the one that would matter most in a shared dev
        // database: the purge must never touch a row a human created.
        //
        // `Map` rather than object literals: the sweeps read a column chosen at
        // runtime, and indexing a fixed object type with a `string` is a hard
        // `tsc` error here. `Map.get(string)` is exactly the shape a dynamic
        // column lookup wants, and it needs no type annotation.
        const foreignRows = [
            new Map([
                ['name', 'Hauptseite'],
                ['slug', 'hauptseite'],
            ]),
            new Map([
                ['name', 'TSV Beispiel e.V.'],
                ['slug', 'tsv-beispiel'],
            ]),
            new Map([
                ['name', 'Ausweis'],
                ['slug', 'ausweis'],
            ]),
            new Map([
                ['title', 'Ligaspiel Sonntag'],
                ['competition', 'Regionalliga'],
            ]),
            new Map([['original_name', 'logo.png']]),
            new Map([
                ['name', null],
                ['slug', null],
            ]),
            new Map([
                ['name', ''],
                ['slug', ''],
            ]),
        ];
        for (const row of foreignRows) {
            for (const sweep of E2E_PURGE_SWEEPS) {
                const matched = sweep.matchers.some((matcher) => {
                    const value = row.get(matcher.field);
                    if (value === null || value === undefined) {
                        return false;
                    }
                    const text = String(value);
                    return MARKER_TABLE.some(
                        ([kind, markers]) => kind === matcher.markerKind && markers.some((one) => text.startsWith(one)),
                    );
                });
                expect(
                    matched,
                    `the ${sweep.resource} sweep must not match ${JSON.stringify([...row])}`,
                ).toBe(false);
            }
        }
    });

    test('referrers are swept before the rows that reference them', () => {
        // A referenced venue is refused with a 409 naming the reference
        // (`VenueController::deleteVenue`), and a mandant that still owns teams is
        // refused with a 409 too (`MandantController::destroy`). F1's 53 refused
        // venue deletions were exactly this: the venue sweep ran, was refused,
        // and the refusal was not looked at.
        //
        // The mandant is exempt from the pair below because the sweep DETACHES
        // its teams first (a URL-shape decision in the purge, not an order one) —
        // which is why the venue pair is asserted and the mandant one is not.
        const constrainedPairs = [
            { referrer: 'teams', referee: 'venues' },
            { referrer: 'events', referee: 'venues' },
        ];
        const order = E2E_PURGE_SWEEPS.map((sweep) => sweep.resource);
        for (const pair of constrainedPairs) {
            const at = order.indexOf(pair.referrer);
            const other = order.indexOf(pair.referee);
            expect(at, `${pair.referrer} is not swept at all`).toBeGreaterThanOrEqual(0);
            expect(other, `${pair.referee} is not swept at all`).toBeGreaterThanOrEqual(0);
            expect(
                at,
                `${pair.referrer} must be swept before ${pair.referee}: the backend refuses to delete ` +
                    `a ${pair.referee} row that a ${pair.referrer} row still references (409), and an ` +
                    'unchecked 409 leaves the row behind for every future run.',
            ).toBeLessThan(other);
        }
    });

    test('the swept resources are the ones the table names', () => {
        // A sweep the table never mentions is a second list to keep in sync; a
        // table entry without a sweep is a dead entry (checked above). Pin the
        // set so adding a kind to one and not the other is visible here too.
        expect([...new Set(E2E_PURGE_SWEEPS.map((sweep) => sweep.resource))].sort()).toEqual([
            'badge-images',
            'badge-templates',
            'categories',
            'events',
            'mandants',
            'teams',
            'venues',
        ]);
    });

    test('every "stamped" marker (one ending in a space) matches a name the suite builds', () => {
        // A typo in a marker is the quietest failure there is: it matches nothing,
        // the sweep deletes nothing, and the run is green. A marker ending in a
        // space is by construction the "…and then a unique stamp" form, so it MUST
        // correspond to a construction the scan can see.
        //
        // Markers WITHOUT a trailing space are exempt by their nature and not by a
        // list: they are whole names of stable fixtures (`E2E Heimstadion`,
        // `E2E Portal Arena`), which are plain literals and therefore outside the
        // stamped-name scan on purpose. A hand-maintained exemption list would be
        // exactly the kind of place a real gap hides next time.
        const constructed = [...stampedNames().keys()];
        const typos = [];
        for (const [kind, markers] of MARKER_TABLE) {
            for (const marker of markers) {
                if (!marker.endsWith(' ') || constructed.some((prefix) => prefix.startsWith(marker))) {
                    continue;
                }
                typos.push(`${kind}: "${marker}" matches no name the suite constructs`);
            }
        }
        expect(typos, typos.join('\n')).toEqual([]);
    });
});

/**
 * ## The one row both harnesses write, and the lock that covers it
 *
 * The primary mandant's logo is the only piece of global state the E2E suite and
 * the ui-review harness both WRITE, and it carries no name marker — so neither
 * suite's namespace discipline reaches it. It is covered by exactly one artefact
 * instead: `acquirePrimaryMandantLogoLock()`, an exclusive-create file in
 * `os.tmpdir()` that both harnesses must take.
 *
 * F3: `purgeReviewFixtures()` (the review harness's dataset reset) called
 * `resetPrimaryMandantLogo()` **naked**, while every other caller took the lock —
 * `admin-mandant.spec.ts` around its upload, `portal.spec.ts` around its "no
 * logo" assertion, the E2E `globalSetup` before a run. A screenshot run beside a
 * live E2E run could therefore delete the logo while the upload test's file
 * dialog was open. The finding is structural rather than reproduced: it needs the
 * two harnesses running at once, and the suites are run sequentially by policy
 * (§6) precisely so a full run never competes with another.
 *
 * ## Why this check lives HERE and not in the screenshot suite
 *
 * The invariant spans both directories, and the screenshot suite is explicitly
 * NOT part of CI (`playwright.screenshots.config.ts` is a local design-QA loop).
 * A test there would be a test that only runs when somebody remembers. This file
 * runs in the push gate, so the screenshot harness's callers are checked from
 * there.
 *
 * ## What it does and does not prove
 *
 * It proves **every call site is in a file that takes the mutex** — a structural
 * fact, checked by the same kind of scan as the prefix rule above. It does not
 * prove the lock is held across the *whole* critical section, nor that the mutex
 * itself works; the second is what the lock's own owner-token logic in
 * `admin-data.ts` is for. Deleting the `acquire` line from `dataset.ts` turns
 * this red, which is the only claim made for it.
 */
test.describe('every writer of the primary mandant logo takes the logo mutex', () => {
    /**
     * Each harness file paired with its comment-stripped CODE, read once. The
     * pairing is what lets a test ask "does THIS file take the mutex?" without a
     * lookup helper, which would need a parameter (see `HARNESS_TS_FILES`).
     */
    const CODE_BY_FILE = HARNESS_TS_FILES.map((file) => ({
        file,
        code: fs.readFileSync(path.resolve(process.cwd(), file), 'utf8').replace(BLOCK_COMMENT, '').replace(LINE_COMMENT, ''),
        resetsLogo: false,
        takesMutex: false,
    }));

    /**
     * Classify every harness file once: does it CALL a logo reset, and does its
     * code AWAIT the mutex? Written as a pass over `CODE_BY_FILE` rather than a
     * per-site lookup, for the same no-parameters reason.
     */
    function classifyLogoWriters() {
        for (const entry of CODE_BY_FILE) {
            entry.resetsLogo = /\bawait\s+(resetPrimaryMandantLogo|resetPrimaryMandantLogoForRun)\(/.test(
                entry.code,
            );
            entry.takesMutex = entry.code.includes('await acquirePrimaryMandantLogoLock(');
        }
        return CODE_BY_FILE;
    }

    test('THE GUARD: a logo reset is never written without the mutex in the same file', () => {
        const unlocked = [];
        for (const entry of classifyLogoWriters()) {
            if (entry.resetsLogo && !entry.takesMutex) {
                unlocked.push(`  ${entry.file}  resets the logo but never awaits the mutex`);
            }
        }
        expect(
            unlocked,
            'These files reset the primary mandant logo without awaiting ' +
                'acquirePrimaryMandantLogoLock() (comments do not count — a docblock that names the ' +
                'function is not a lock). The logo carries no E2E name marker, so the namespace ' +
                'invariant does not cover it, and a screenshot run beside a live E2E run can delete ' +
                "the logo out from under admin-mandant.spec.ts's upload dialog. Wrap the call:\n" +
                '    const release = await acquirePrimaryMandantLogoLock();\n' +
                '    try { … } finally { release(); }\n' +
                unlocked.join('\n'),
        ).toEqual([]);
    });

    test('the scan is not vacuous: it finds the call sites it is meant to police', () => {
        // A scan that quietly stopped matching would make the guard above pass for
        // the wrong reason — the F1 shape. Pin the two suites' known writers, and
        // pin that the one F3 was about is among them, since "the review harness
        // does not take the lock" is only a test if the review harness is read.
        const writers = classifyLogoWriters()
            .filter((entry) => entry.resetsLogo)
            .map((entry) => entry.file)
            .sort();
        expect(writers).toEqual([
            'tests/e2e/global-setup.ts',
            'tests/e2e/helpers/admin-data.ts',
            'tests/screenshots/helpers/dataset.ts',
        ]);
        // …and that all three of them actually take the mutex, so the pin above
        // is not a list of names that happen to be there.
        expect(classifyLogoWriters().filter((entry) => entry.resetsLogo && !entry.takesMutex)).toEqual([]);
    });

    test('the mutex is released, so a throw cannot strand it', () => {
        // A stranded lock is not a deadlock forever — it is reclaimed after
        // `LOGO_LOCK_STALE_MS` — but it stalls the other suite's upload test for
        // 30 s while the row it protects is already unprotected, which is the
        // failure the lock exists to prevent, one step later.
        for (const entry of classifyLogoWriters()) {
            if (!entry.takesMutex) {
                continue;
            }
            const acquireIndex = entry.code.indexOf('await acquirePrimaryMandantLogoLock(');
            const releaseIndex = entry.code.indexOf('release', acquireIndex);
            expect(
                releaseIndex,
                `${entry.file} acquires the logo mutex but never releases it. A held lock is only ` +
                    'reclaimed after the stale window, so the other suite waits instead of proceeding.',
            ).toBeGreaterThan(acquireIndex);
        }
    });
});

/**
 * ## The shared bootstrap rows are not per-test fixtures, and must never be owned as if they were
 *
 * Three rows are SHARED master data in the primary mandant: the two venues
 * (`E2E Heimstadion`, `E2E Portal Arena`) and the bootstrap team
 * (`E2E Heimverein …`). Every spec may read them at any moment, they carry
 * stable name markers, and the serial `purgeAllE2EArtifacts()` reclaims them at
 * the end of each run — which is also why every run recreates them from
 * nothing, concurrently, in the first seconds of the run.
 *
 * `helpers/admin-data.ts` therefore creates them and registers NOTHING. That is
 * not tidiness; registering one puts a per-test `DELETE` on a row other workers
 * hold, and the FK graph makes that worse than a dangling reference:
 * `events.team_id` and `categories.team_id` are `cascade` in
 * `E2E_OWNED_FK_EDGES`, so the delete takes the NEIGHBOUR's row away in the
 * middle of its assertions. MEASURED 2026-10-04 as the reason
 * `ensurePrimaryMandantActivePortalEvent()` stopped registering the bootstrap
 * team; `--workers=1` in CI (the login-throttle pin) is the only reason the
 * `rememberOwnedRow('teams', …)` that sat there had not fired yet.
 *
 * ## What this guard does and does not prove
 *
 * It proves a structural fact about ONE file: the shared-bootstrap helper module
 * registers no `teams` and no `venues` row. It says nothing about the specs —
 * `ownership.spec.ts` legitimately creates and owns its own venue, and that is
 * the difference: a per-test row is created by the test that deletes it, a
 * shared row is created by whoever lost the race and read by everybody.
 *
 * A scan that could not fail would be the F1 shape, so the second test pins its
 * own reach: the kinds it DOES expect to find are named there.
 */
test.describe('the shared bootstrap rows are never owned by a single test', () => {
    /**
     * The shared-bootstrap helper module, comment-stripped — for `BLOCK_COMMENT`
     * and `LINE_COMMENT`, see the logo guard above: a docblock that merely
     * NAMES `rememberOwnedRow('teams', …)` must not be able to vouch for it.
     */
    const ADMIN_DATA_CODE = fs
        .readFileSync(path.resolve(process.cwd(), 'tests/e2e/helpers/admin-data.ts'), 'utf8')
        .replace(BLOCK_COMMENT, '')
        .replace(LINE_COMMENT, '');

    /** Every kind `helpers/admin-data.ts` registers, in source order. */
    function registeredKinds() {
        const kinds = [];
        for (const match of ADMIN_DATA_CODE.matchAll(/rememberOwned(?:Row|ByUser)\('([a-zA-Z]+)'/g)) {
            kinds.push(match[1]);
        }
        return [...new Set(kinds)];
    }

    test('no shared bootstrap row is registered with the per-test ledger', () => {
        const shared = [];
        for (const kind of registeredKinds()) {
            if (kind === 'teams' || kind === 'venues') {
                shared.push(kind);
            }
        }
        expect(
            shared,
            'helpers/admin-data.ts registers a SHARED bootstrap row into the per-test ownership ledger. ' +
                'The teardown then DELETEs it in the afterEach of whichever test happened to create it — ' +
                'while a sibling worker may be mid-assertion on a row that cascades from it ' +
                "(events/categories → teams is `cascade` in E2E_OWNED_FK_EDGES). These rows are shared " +
                'master data under stable E2E name markers; the serial purgeAllE2EArtifacts() reclaims them. ' +
                'Create them, do not own them.',
        ).toEqual([]);
    });

    test('the scan is not vacuous: it still finds the per-test kinds it is meant to leave alone', () => {
        // Without this, emptying the registrations (or a typo in the pattern)
        // would make the guard above pass for the wrong reason. The per-worker
        // unique rows of the very same helpers are the control group: they MUST
        // be registered, or the suite leaks them per test.
        const kinds = registeredKinds();
        for (const kind of ['categories', 'events', 'accreditations', 'userMedia']) {
            expect(kinds, `helpers/admin-data.ts registers no ${kind} row — the scan cannot see anything`).toContain(
                kind,
            );
        }
    });
});

/**
 * ## The order the per-test teardown gives rows back, checked against the schema
 *
 * `helpers/ownership.ts` reclaims each test's own rows in the order of
 * `E2E_OWNED_TEARDOWN`. That order is a correctness requirement, not a style
 * choice — and F1 is the proof: the serial name sweep's order was never checked
 * against the constraints it has to satisfy, so 53 venue deletes were refused
 * with 409 and nobody looked at the status.
 *
 * So the order is DATA (`E2E_OWNED_TEARDOWN`) and the CONSTRAINT GRAPH is data
 * (`E2E_OWNED_FK_EDGES`), and the test below walks both. That is the shape the
 * serial sweep already uses for its own table, and the reason it can be checked
 * at all.
 *
 * ### The rule, and why only two of the three onDelete modes constrain it
 *
 * - `cascade`: deleting the parent takes the child, so the order between them is
 *   free. Constraining it would be noise.
 * - `null` (`nullOnDelete`): the child SURVIVES its parent. Order matters — the
 *   child has to be dealt with on its own terms, or it is orphaned. This is
 *   `accreditations.event_id`, and it is why **categories come before events**:
 *   `accreditations.category_id` cascades, so a category delete takes its
 *   accreditations with it, whereas an event delete only nulls the pointer.
 * - `restrict`: the parent's delete is REFUSED (409) while the child exists.
 *   Order matters absolutely. This is `venues`, referenced by teams and events.
 */
test.describe('the per-test ownership teardown gives rows back in a constraint-legal order', () => {
    /** The teardown plan's kinds, in the order they are reclaimed. */
    const ORDER = E2E_OWNED_TEARDOWN.map((step) => step.kind);

    test('every teardown step is a known kind, and no kind is listed twice', () => {
        const duplicates = [];
        const seen = new Set();
        for (const kind of ORDER) {
            if (seen.has(kind)) {
                duplicates.push(kind);
            }
            seen.add(kind);
        }
        expect(duplicates, 'a kind listed twice is deleted twice — the second delete is a silent 404').toEqual([]);
    });

    test('every reclaimable step has a route, and no step is silently unreclaimable', () => {
        // `users` was, for months, the one kind with NO delete route (MEASURED:
        // the admin surface answered 405) and was therefore declared
        // `reclaimable: false` on purpose — the teardown counted and named it
        // instead of pretending to delete it. The account DELETE route
        // (`DELETE /api/admin/users/{user}`, board position 10) has landed, so
        // that gap is closed, and this assertion now forbids a NEW silent one:
        // a step must either carry a route or say why it has none.
        const wrong = [];
        for (const step of E2E_OWNED_TEARDOWN) {
            if (step.reclaimable && typeof step.route !== 'string') {
                wrong.push(`${step.kind} is reclaimable but has no route`);
            }
            if (!step.reclaimable) {
                wrong.push(
                    `${step.kind} is marked unreclaimable — the account DELETE route closed the last measured ` +
                        'gap, so this is either a missing route or a missing claim about why there is none',
                );
            }
        }
        expect(wrong, wrong.join('\n')).toEqual([]);
    });

    test('every step declares the success status ITS route answers', () => {
        // The destroy routes are NOT uniform, and a hard-coded 204 was caught the
        // only way it could be: by failing a PASSING `profile.spec.ts` with
        // "DELETE answered 200 (expected 204/404) — the row stays", a false
        // accusation about a delete that had in fact worked.
        //
        // So the expectation is data next to the route it describes, and the kinds
        // whose contract differs from the admin destroy convention are pinned here
        // by name. `userMedia` answers 200 with a `{message}` body
        // (`UserMediaController::destroy` returns a `JsonResponse`), `users`
        // answers 200 with a deletion SUMMARY (`UserController::destroy`), and
        // every other route answers 204 (`response()->noContent()`), MEASURED row
        // by row.
        //
        // A new kind with a different contract therefore has to be added to this
        // list in the same commit that adds its route, and the failure says
        // "this table is out of date" — which is the right thing to be told.
        const nonDefault = [];
        for (const step of E2E_OWNED_TEARDOWN) {
            if (step.okStatus !== 204) {
                nonDefault.push(`${step.kind}=${step.okStatus}`);
            }
        }
        expect(
            nonDefault,
            'these kinds do not answer 204 on delete. If that is still true, the contract is pinned here on ' +
                'purpose — add it, do not widen the tolerance',
        ).toEqual(['userMedia=200', 'users=200']);

        // The complement of the promise the ledger-walk test makes. That test walks
        // the plan and creates a row of each kind it can; the kinds it cannot are
        // flagged `creatableHere: false` with a stated reason, and the flag is what
        // turns "the walk silently skipped two entries" into a countable claim.
        //
        // MEASURED, and this is the number that changed: before the flag existed,
        // the walk hit its final `else { continue }` for FOUR reclaimable entries
        // (badgeImages, venues, subAccreditations, mandants) while the walk test
        // asserted `toBeGreaterThanOrEqual(5)` — so the promise "a kind in the
        // plan is covered from the day it is entered" was false for four of them
        // and nothing said so. The list below is therefore an EXACT one: it names
        // the entries whose create-and-verify round trip happens elsewhere, and
        // adding a kind to the plan without either a fixture or a flag breaks it.
        //
        // `users` joined the list when the account DELETE route landed: this walk
        // is id-based off the create response, and a user's create answers no id
        // (the handle is an email, resolved by the teardown itself). The list
        // below is in PLAN order — `users` sits just before the mandant-scoped
        // steps, after every owner-scoped one.
        const flagged = E2E_OWNED_TEARDOWN.filter((step) => step.creatableHere === false).map((step) => step.kind);
        expect(
            flagged,
            'the plan entries that no test in the ledger walk creates. Each needs the reason to sit next to it ' +
                'in E2E_OWNED_TEARDOWN — an unflagged entry is a plan row that nothing verifies, and a flagged ' +
                'entry whose reason has gone is a stale claim. NOTE the two mandant-scoped steps (teams, ' +
                'mandantDomains) are not here: they are skipped for a structural reason (they need a parent ' +
                'this file does not create) and are registered by their own specs.',
        ).toEqual(['subAccreditations', 'venues', 'badgeImages', 'users', 'mandants']);
    });

    test('a restrict or nullOnDelete parent is reclaimed AFTER its child', () => {
        // The whole point of the table. Walk the real constraint graph and assert
        // the order the teardown actually uses satisfies it — not a restatement
        // of the order, which would agree with a broken teardown for the wrong
        // reason.
        const violations = [];
        for (const edge of E2E_OWNED_FK_EDGES) {
            if (edge.onDelete === 'cascade') {
                // Free: the parent's delete takes the child with it.
                continue;
            }
            const childAt = ORDER.indexOf(edge.child);
            const parentAt = ORDER.indexOf(edge.parent);
            if (childAt < 0 || parentAt < 0) {
                continue;
            }
            const why =
                edge.onDelete === 'restrict'
                    ? 'the backend refuses to delete the parent with 409 while the child references it'
                    : 'the child survives the parent delete (nullOnDelete) and would be orphaned';
            if (!(childAt < parentAt)) {
                violations.push(
                    `${edge.child} (index ${childAt}) must be reclaimed BEFORE ${edge.parent} (index ${parentAt}): ${why}`,
                );
            }
        }
        expect(
            violations,
            'E2E_OWNED_TEARDOWN violates the FK constraints in E2E_OWNED_FK_EDGES. Reorder it:\n' +
                violations.join('\n'),
        ).toEqual([]);
    });

    test('the scan is not vacuous: the order is long and the two decisive edges are in it', () => {
        // A test that iterates an empty graph proves nothing. Pin both ends: the
        // order must be substantial, and it must contain the two pairs whose
        // violation is the measured failure — `events` before `venues` (409) and
        // `categories` before `events` (nullOnDelete).
        expect(ORDER.length).toBeGreaterThanOrEqual(12);
        // A defaulted parameter, because this directory may not ANNOTATE one
        // (plain-JS parser) and may not leave one un-annotated (strict `tsc`) —
        // see `helpers/ownership.ts`, where the same rule is measured in a table.
        const at = (kind = '') => ORDER.indexOf(kind);
        expect(at('categories'), 'categories must precede events').toBeLessThan(at('events'));
        expect(at('events'), 'events must precede venues (restrictOnDelete)').toBeLessThan(at('venues'));
        expect(at('teams'), 'teams must precede venues (restrictOnDelete)').toBeLessThan(at('venues'));
        // And the constraint table itself must not be a stub: if someone emptied
        // it, the order test above would pass vacuously.
        expect(E2E_OWNED_FK_EDGES.length).toBeGreaterThanOrEqual(20);
    });
});

/**
 * ## The rule the portal enforces by hand and we enforce by test
 *
 * In `portal.reisinger.pictures` the "each test cleans up its own fixtures"
 * rule is a CONVENTION: `E2ESessionHelper` has a `teardown()`, ~35 specs call
 * it in an `afterEach`, and **nothing checks that a fixture-creating spec has
 * one at all** — nine exceptions, verified by hand. A convention with no gate is
 * a convention that decays silently, and the decay is invisible: the spec keeps
 * passing while its rows walk into the next run.
 *
 * So this is the gate. The scan is the same technique the marker-coverage guard
 * above already uses (walk every spec, comment-stripped, and ask one question),
 * and the question here is the one nothing was asking: **does this spec create
 * rows, and if so does it give them back?**
 *
 * ### What counts as "creates rows"
 *
 * A call to a helper in `E2E_FIXTURE_CREATORS` (the table below), or a
 * registration into the ownership ledger. The creator list is DATA next to the
 * question rather than a regex over every `create*` name, so a helper that stops
 * creating rows cannot keep a spec flagged, and a new helper is one table entry
 * away from being covered.
 */
test.describe('every spec that creates fixtures gives them back itself', () => {
    /**
     * The spec-facing fixture helpers: calling one of these means the spec has
     * created rows the database now holds.
     *
     * ## Why this list and not "every `create*` in the helpers"
     *
     * Three helpers create rows and are deliberately NOT here, each for a reason
     * worth stating rather than hiding:
     *
     * - `ensurePrimaryMandantHasVenues()` — shared bootstrap master data. Every
     *   spec in the run may be reading it at the same moment, so it is not a
     *   per-test fixture at all; the serial teardown reclaims it under its stable
     *   names. It is reached through `ensurePrimaryMandantHasTeam()` and
     *   `ensurePrimaryMandantActivePortalEvent()`, both of which ARE listed.
     * - `registerUploadPortraitAndApply()` — no spec calls it; it is the inner
     *   step of `ensurePrimaryMandantApprovedApplication()`, which is listed and
     *   therefore already gated.
     * - `createBlacklistEntryApi()` — **used to be here and is now GONE**
     *   (2026-09-28). It had no caller at all: `approvals.spec.ts` creates its
     *   blacklist entry through the UI, so the helper was dead code — and the
     *   note that used to sit here claimed it "is covered regardless — it
     *   registers its own row". That sentence was true of the function and false
     *   about the suite: a helper nobody calls registers nothing, ever. §6 forbids
     *   dead code without a stated justification, so it was DELETED along with its
     *   docblock, and the table entry never existed.
     *
     * A spec that creates a row some OTHER way (a raw `api.post` in its body) is
     * caught by the second half of the check below, which also looks for a ledger
     * registration.
     */
    const FIXTURE_CREATORS = [
        'ensurePrimaryMandantHasTeam',
        'ensurePrimaryMandantAccreditation',
        'ensurePrimaryMandantSubAccreditation',
        'ensurePrimaryMandantApprovedApplication',
        'ensurePrimaryMandantWalletSetup',
        'ensurePrimaryMandantActivePortalEvent',
        'registerAndActivateUser',
        'registerAndApplyForAccreditation',
    ];

    /** Every `.spec.ts` with its comment-stripped CODE, read once. */
    const SPECS = fs
        .readdirSync(path.resolve(process.cwd(), 'tests/e2e'))
        .filter((file) => file.endsWith('.spec.ts'))
        .map((file) => ({
            file: `tests/e2e/${file}`,
            code: fs
                .readFileSync(path.resolve(process.cwd(), 'tests/e2e', file), 'utf8')
                .replace(BLOCK_COMMENT, '')
                .replace(LINE_COMMENT, ''),
        }));

    /**
     * ## The half of "creates" that no amount of looking at helpers can find
     *
     * A spec that creates a row THROUGH THE UI creates a row. The helper list
     * above cannot see that — a form submit is not a function call — and neither
     * can `rememberOwned*`, which is the very thing being checked for. So the
     * first version of the guard had a hole shaped exactly like
     * `admin-mandant.spec.ts`: that spec created a mandant, a domain and a team
     * through three forms, registered NOTHING, and was nonetheless classified as
     * a creator — because of an UNRELATED registration in a different test
     * (`:172`) — so the guard only ever asked whether a hook existed and never
     * whether it had anything to give back. MEASURED with `E2E_PURGE=off`, twice:
     * `mandants 0 → 1 → 2`, every assertion green.
     *
     * So UI creates are listed as DATA here, one row per (spec, what it creates,
     * the control it clicks), and the test below checks two things per row:
     *
     * 1. the row is REAL — the named control is in that spec's code, so a
     *    renamed or deleted button cannot leave a table entry that quietly
     *    "covers" a spec which no longer creates anything;
     * 2. the spec REGISTERS that kind — the ledger is the only thing that can
     *    give the row back, and a registration of a different kind does not
     *    count.
     *
     * Plus a third, automatic signal with no table at all
     * (`PLAN_COLLECTIONS`): a spec that POSTs to a collection route of the
     * teardown plan is a creator **whether or not it registers anything**. That
     * is what makes this file a gate rather than a note.
     *
     * ## And the table only works because the lookup it names exists ONCE
     *
     * Every row below is satisfied the same way: a submit, then
     * `findCreatedRowId(listUrl, key, value, kind)` under the EXACT name the test
     * typed, then `rememberOwnedRow('<kind>', …)` with the kind as a literal.
     * That lookup is `tests/e2e/helpers/created-row.ts` — ONE module, shared by
     * all of these specs, and the reason `admin-mandant.spec.ts` no longer keeps
     * a private copy of what it was the first user of. A second copy would be a
     * second truth about the one rule that decides whether a UI-created row can be
     * given back, and the table below could not see the difference:
     * `tests/e2e/created-row-lookup.test.ts` is what sees it.
     */
    const UI_CREATE_SITES = [
        {
            spec: 'tests/e2e/admin-mandant.spec.ts',
            kind: 'mandants',
            creates: 1,
            controls: ['Mandant erstellen', 'Teams aktivieren'],
            note: 'the "create mandant" form; the id is only in the URL, so the spec looks it up by its ' +
                'exact, worker-stamped name right after the submit.',
        },
        {
            spec: 'tests/e2e/admin-mandant.spec.ts',
            kind: 'mandantDomains',
            creates: 1,
            controls: ['Domain hinzufügen'],
            note: "the detail page's domain form, registered immediately after the submit.",
        },
        {
            spec: 'tests/e2e/admin-mandant.spec.ts',
            kind: 'teams',
            creates: 1,
            controls: ['Team speichern'],
            note: "the detail page's team form — the only create in the suite that needs a mandant-scoped " +
                'parentId, which is why the lookup takes the mandant id as an argument.',
        },
        // ── Position 22: the specs whose ledger hook existed but was EMPTY ──────
        //
        // Five specs created rows through a FORM and deleted them through the UI,
        // so their `afterEach` had nothing to give back and a mid-test failure left
        // the rows to the serial name sweep — the F1 shape the ledger exists to
        // end. The fix is one lookup under the EXACT, worker-stamped name right
        // after each submit (`helpers/created-row.ts`), and each entry below is the
        // registration that makes the ledger hook real.
        //
        // One entry PER CREATE, not one per (spec, kind): `testBlockContaining`
        // below resolves the control to the test that clicks it, so a spec that
        // creates the same kind in two tests (`admin-venue.spec.ts`, two venues)
        // needs two entries or the second test is never checked. Both entries name
        // a control that occurs in exactly one test block — see `the UI-create table
        // has no dead entry`, which fails if a control moves or disappears.
        {
            spec: 'tests/e2e/admin-category.spec.ts',
            kind: 'categories',
            creates: 2,
            controls: ['Kategorie erstellen'],
            note: 'the mandant-level category form AND the team-level one — two creates in ONE test, hence ' +
                '`creates: 2` rather than a second entry: the scan resolves a control to its enclosing TEST ' +
                'block, so a second entry on the same control would have asked the same question twice.',
        },
        {
            spec: 'tests/e2e/admin-event.spec.ts',
            kind: 'events',
            creates: 1,
            controls: ['Event erstellen'],
            note: 'the event form; the key is the title AS TYPED, because the spec renames the event two steps ' +
                'later and `editedTitle` CONTAINS `uniqueTitle` — which is also why the lookup must be exact.',
        },
        {
            spec: 'tests/e2e/admin-venue.spec.ts',
            kind: 'venues',
            creates: 1,
            controls: ['Spielort erstellen'],
            note: "the venues page's own create form, in the test that renames, deactivates and then deletes " +
                'its venue through the UI.',
        },
        {
            spec: 'tests/e2e/admin-venue.spec.ts',
            kind: 'venues',
            creates: 1,
            controls: ['neu anlegen'],
            note: "the INLINE create inside the team form — the combobox option that creates the venue instead " +
                'of selecting one. A second, separate create in the same file, which is why it needs its own ' +
                'entry; this is the `+1 venue per run` row that used to be unreachable at all.',
        },
        {
            spec: 'tests/e2e/admin-venue.spec.ts',
            kind: 'teams',
            creates: 1,
            controls: ['Team speichern'],
            note: 'the team form of the same test, and the second `teams` create in the suite. Registered WITH ' +
                'its mandant: the DELETE route is `/api/admin/mandants/{parentId}/teams`, so a missing parentId ' +
                'makes the teardown address mandant 0 and count the 404 as reclaimed.',
        },
        {
            spec: 'tests/e2e/approvals.spec.ts',
            kind: 'blacklists',
            creates: 1,
            controls: ['Blacklist-Eintrag anlegen'],
            note: 'the blacklist form, created seven approval steps before the UI delete. The serial sweep ' +
                'cannot reach this kind at all — it has no name column — so an unregistered row here was ' +
                'never merely late, it was unbounded.',
        },
        {
            spec: 'tests/e2e/approvals.spec.ts',
            kind: 'subAccreditations',
            creates: 1,
            controls: ['Sub-Akkreditierung erstellen'],
            note: 'the admin modal in the second test of the file. Keyed by `accreditation_id` rather than by a ' +
                'name because `SubAccreditationResource` has none that is unique — see the spec for the whole ' +
                'argument and why the numeric wrapper is the one used.',
        },
    ];

    /**
     * The mandant-independent collection routes of the teardown plan — the
     * addresses a spec can POST to in order to create a row the ledger knows how
     * to give back. Derived from the plan rather than written out, so a new kind
     * in `E2E_OWNED_TEARDOWN` is covered the day it is added. The
     * mandant-scoped routes are excluded because they carry a `{parentId}`
     * placeholder that no literal in a spec would contain.
     */
    const PLAN_COLLECTIONS = E2E_OWNED_TEARDOWN.filter((step) => step.route !== null)
        .map((step) => step.route)
        .filter((route) => !route.includes('{parentId}'));

    /**
     * Does this source POST to `route` as a WHOLE path literal?
     *
     * A named matcher because the rule was written TWICE — once in the guard
     * below, once in the non-vacuity test further down — and two copies of one
     * rule is one copy too many in a file whose entire subject is drift.
     *
     * The pattern is `post\(\s*(['"`])<route>\1`: a quote CLASS for the opener
     * (so single, double and backtick all count) and a BACKREFERENCE for the
     * closer. Both halves were measured against what they replaced:
     *
     * - The old `includes` with a single-quoted needle accepted ONE quote form,
     *   and its only template-literal branch required a CLOSING backtick. A
     *   double-quoted create, and a line-broken call that puts the route on the
     *   next line, were both invisible — fail-OPEN, because a spec that creates a
     *   row and registers nothing would then not be classified as a creator and
     *   the guard would pass for the wrong reason.
     * - The backreference is what keeps an ACTION route out: a POST to
     *   `/api/admin/accreditations/<id>/allocate` creates nothing and must not
     *   count. A plain prefix match would have counted it — MEASURED, it pulls in
     *   `sub-accreditation.spec.ts`, whose only create comes from a fixture helper
     *   that already classifies it.
     *
     * The route is interpolated raw, which would err toward MATCHING if a future
     * route ever carried a regex metacharacter. For this gate that is the strict
     * direction: more creators classified, never fewer.
     *
     * A VARIABLE key (`post(path, …)`) is not matched and cannot be — a static
     * scan has nothing to compare it against. That direction is fail-open, so it
     * is stated here rather than left to be discovered, and it is a REAL case in
     * this tree: `ownership.spec.ts:416` posts to a `path` it built from
     * `E2E_OWNED_TEARDOWN`. It is covered regardless, by the same spec's LITERAL
     * posts to `/api/admin/categories`, `/api/admin/events` and
     * `/api/admin/venues` — which is what the `toContain` assertion on that spec
     * pins.
     */
    function postsToCollectionRoute(code = '', route = '') {
        return new RegExp(`post\\(\\s*(['"\`])${route}\\1`).test(code);
    }

    test('THE GUARD: a fixture-creating spec has an afterEach that reclaims', () => {
        const offenders = [];
        for (const spec of SPECS) {
            let creates = false;
            for (const creator of FIXTURE_CREATORS) {
                if (spec.code.includes(`${creator}(`)) {
                    creates = true;
                }
            }
            // A spec that registers into the ledger itself (a raw `api.post`
            // whose id it pushes) is equally a creator — and the registration is
            // what makes it findable without guessing at the create shape.
            if (spec.code.includes('rememberOwnedRow(') || spec.code.includes('rememberOwnedByUser(')) {
                creates = true;
            }
            // A spec that POSTs to a collection route of the teardown plan is a
            // creator TOO — and this is the signal that needs no maintenance at
            // all, so it is the one that cannot rot. It matters because the two
            // signals above are both downstream of the ledger: a spec that
            // creates through a form and registers NOTHING matched neither, and
            // the guard then had nothing to complain about (the measured
            // `admin-mandant.spec.ts` hole — see `UI_CREATE_SITES`).
            for (const route of PLAN_COLLECTIONS) {
                if (postsToCollectionRoute(spec.code, route)) {
                    creates = true;
                }
            }
            // …and a spec listed as creating through the UI is a creator even
            // when this run's tree has not yet registered it, so the check below
            // has something to check.
            if (UI_CREATE_SITES.some((site) => site.spec === spec.file)) {
                creates = true;
            }
            if (!creates) {
                continue;
            }
            // The teardown must run for EVERY test of the file, and in a PER-TEST
            // hook. `afterAll` runs once per worker that touched the file, not
            // once per test — the measured badge-editor bug (6–10 firings per
            // run) — so it never counts.
            //
            // "Every test" has two acceptable shapes, and both are checked here
            // rather than assumed:
            //   a) the hook is at FILE scope (indentation-free), which is the
            //      shape every fixture-creating spec now uses; or
            //   b) the file has exactly ONE `test.describe`, in which case a
            //      hook inside it covers the whole file.
            // (b) exists because `badge.spec.ts` predates the ledger and cleans
            // its UI-created template by exact name from a describe-scoped
            // `afterEach`; demanding a rewrite of a correct cleanup would make
            // this gate a nuisance rather than a guard. What it must never accept
            // is a hook inside ONE of SEVERAL describes — that is the measured
            // `admin-mobile-layout.spec.ts` shape, where a second describe's
            // fixtures had no teardown at all.
            const hasPerTestHook = /test\.afterEach\(/.test(spec.code) && spec.code.includes('reclaimOwnedRows(');
            const atFileScope = !/^\s+test\.afterEach\(/m.test(spec.code);
            const describeCount = (spec.code.match(/^test\.describe\(/gm) ?? []).length;
            const coversWholeFile = atFileScope || describeCount <= 1;
            const hasTeardown = hasPerTestHook && coversWholeFile;
            if (!hasTeardown) {
                offenders.push(
                    `  ${spec.file}  creates fixtures but has no per-test teardown covering the whole file ` +
                        `(${describeCount} describe(s), hook ${atFileScope ? 'nested inside one' : 'at file scope'})`,
                );
            }
        }
        expect(
            offenders,
            'These specs create rows and never give them back. Every row a test creates must be ' +
                'registered with the ownership ledger and reclaimed in a PER-TEST hook that covers the whole ' +
                "file — at file scope, or inside the file's only describe:\n" +
                '    test.beforeEach(async () => { resetOwnedRows(); });\n' +
                '    test.afterEach(async () => { await reclaimOwnedRows(); });\n' +
                'Add them to the spec — do not rely on the serial globalTeardown, which only runs when ' +
                'a run ends cleanly and then reclaims by name prefix, not by id.\n' +
                offenders.join('\n'),
        ).toEqual([]);
    });

    test('the scan is not vacuous: it flags the specs that really do create fixtures', () => {
        // If the creator list ever stopped matching, or a spec's call were
        // renamed, the guard above would pass for the wrong reason — the F2 shape
        // ("a comment asserting a guarantee no code provides"). Pin the count from
        // the other side: the distinct spec files the scan must recognise as
        // creators, named explicitly so a rename is a visible edit here.
        const creatorSpecs = new Set();
        for (const spec of SPECS) {
            for (const creator of FIXTURE_CREATORS) {
                if (spec.code.includes(`${creator}(`)) {
                    creatorSpecs.add(spec.file);
                }
            }
        }
        expect(
            [...creatorSpecs].sort(),
            'these specs call a fixture creator and must be listed explicitly, so that renaming a ' +
                'helper or a spec cannot silently empty the guard',
        ).toEqual([
            'tests/e2e/a11y.spec.ts',
            'tests/e2e/account-deletion.spec.ts',
            'tests/e2e/accreditation.spec.ts',
            'tests/e2e/admin-category.spec.ts',
            'tests/e2e/admin-event.spec.ts',
            // The sub-application resend spec (Position 47). TWO creators, read out
            // of its code rather than its imports: `ensurePrimaryMandantSubAccreditation()`
            // in its shared fixture helper and `registerAndActivateUser()` — the first
            // registers its `categories`/`events`/`accreditations`/`subAccreditations`
            // rows itself (`helpers/admin-data.ts:610-657`), the second its account
            // (`rememberOwnedUserAccount`, :701), and the spec registers the two rows
            // it POSTs itself (`applications`, `subApplications`) at the file-scope
            // hooks the guard above demands. Nothing here is UI-created — approving or
            // denying an existing sub-application writes a status, not a row — so
            // `UI_CREATE_SITES` has no row for it, which is the claim to revisit if
            // that spec ever grows a create form.
            'tests/e2e/admin-sub-resend.spec.ts',
            'tests/e2e/admin-users.spec.ts',
            'tests/e2e/approvals.spec.ts',
            'tests/e2e/badge.spec.ts',
            // The ownership spec itself, since the tests that pin the teardown's
            // handling of a refused login and of a decided application have to
            // build those fixtures for real — there is no way to hand a
            // non-existent applicant a 401. It pays the same ownership hooks as
            // every other creator, which the guard above already checks.
            'tests/e2e/ownership.spec.ts',
            'tests/e2e/portal.spec.ts',
            'tests/e2e/sub-accreditation.spec.ts',
            'tests/e2e/wallet.spec.ts',
        ]);

        // And no table entry that no spec calls — a dead entry is the "looks like
        // coverage" edit the marker table was guilty of.
        const unused = [];
        for (const creator of FIXTURE_CREATORS) {
            let called = false;
            for (const spec of SPECS) {
                if (spec.code.includes(`${creator}(`)) {
                    called = true;
                }
            }
            if (!called) {
                unused.push(creator);
            }
        }
        expect(unused, 'FIXTURE_CREATORS entries that no spec calls — dead coverage').toEqual([]);
    });

    /**
     * The `test(` / `test.describe(` block that contains a given offset, as
     * source text: everything from the nearest preceding block opener up to the
     * next one.
     *
     * ## Why per BLOCK and not per file
     *
     * The first version of the UI-create check asked per FILE, and that is
     * demonstrably too coarse — measured, not assumed: with the three
     * registrations of `admin-mandant.spec.ts` removed, the check went red for
     * `mandants` and `teams` but stayed GREEN for `mandantDomains`, because a
     * DIFFERENT test in the same file (the logo-column one) still calls
     * `rememberOwnedRow('mandantDomains', …)`. A file-level answer to "does this
     * spec give its UI-created rows back" is an answer about the wrong unit: the
     * ledger is per TEST, so the question is too.
     *
     * The scan is deliberately simple — a linear walk over block openers — and
     * deliberately *over*-narrow rather than over-wide: a block boundary that is
     * missed would make the check stricter, never laxer.
     */
    const TEST_BLOCK_OPENER = /\n[ \t]*test(?:\.[A-Za-z]+)?\(/g;

    /**
     * Does this source register a row of `kind` with the ledger, and HOW MANY?
     *
     * A REGEX rather than a substring, and the reason is measured: the natural
     * way to write a three-argument registration is multi-line (the line gets
     * long), and a substring check for `rememberOwnedRow('mandantDomains'` then
     * fails on CORRECT code — a gate that fails on correct code is a gate people
     * learn to bypass.
     *
     * Two deliberate shapes, both measured:
     *
     * - `\s*` after the paren, plus a quote CLASS and a BACKREFERENCE for the
     *   kind, so all three string forms count. The single-quote-only version
     *   missed a double-quoted or backticked kind, and that direction is
     *   fail-CLOSED here (the kind reads as unregistered, the gate goes RED) —
     *   so it was a false alarm rather than a silent pass, but a gate that cries
     *   wolf on correct code is a gate people learn to switch off. The
     *   backreference also keeps the kind EXACT: `rememberOwnedRow('teams-legacy'`
     *   cannot answer for `teams`.
     * - A VARIABLE kind is not matched, and the resulting direction is
     *   fail-closed on purpose — the kind reads as unregistered, so the check
     *   goes red. That is the strict end of the trade, and it is also the
     *   project's stated convention: `admin-mandant.spec.ts` writes the kind as a
     *   literal at each create site precisely so this gate can read it, and calls
     *   a generic `rememberOwnedRow(kind, …)` "a registration of nothing".
     *
     * ## Why the COUNT and not a boolean
     *
     * MEASURED, and this is why the count was added rather than the boolean being
     * left alone: `admin-category.spec.ts` creates TWO categories in ONE test and
     * registers both. Deleting the FIRST registration — the empty-hook position's
     * smaller cousin, "the test registers its rows, but not all of them" — left
     * the block-level check GREEN (28 passed), because the second registration is
     * in the same block and `registerCallIn` only asks whether ONE exists. The
     * run was green over a spec that had stopped giving back a row.
     *
     * So `UI_CREATE_SITES` now carries a `creates` count next to its `controls`,
     * and the guard asks for that many registrations. The count is DATA because
     * the only other candidate — how often the control string occurs in the block
     * — is wrong: `'neu anlegen'` occurs TWICE in `admin-venue.spec.ts`'s second
     * test, and the second occurrence is the assertion that the option is NOT
     * offered. Deriving the number from the text would have demanded two venue
     * registrations there.
     */
    function registrationsIn(code = '', kind = '') {
        // A FRESH regex per call: a module-scope `/g` pattern carries `lastIndex`
        // between calls, which is exactly how a second call would read zero.
        const pattern = new RegExp(`rememberOwnedRow\\(\\s*(['"\`])${kind}\\1`, 'g');
        let count = 0;
        while (pattern.exec(code) !== null) {
            count += 1;
        }
        return count;
    }

    function registerCallIn(code = '', kind = '') {
        return registrationsIn(code, kind) > 0;
    }

    function testBlockContaining(code = '', index = 0) {
        // A FRESH regex per call: a module-scope `/g` pattern carries `lastIndex`
        // between calls, and two calls in one test would then read a block
        // boundary that belongs to the previous one.
        const pattern = new RegExp(TEST_BLOCK_OPENER.source, 'g');
        const starts = [];
        let end = code.length;
        let match = pattern.exec(code);
        while (match !== null) {
            if (match.index >= index) {
                end = match.index;
                break;
            }
            starts.push(match.index);
            match = pattern.exec(code);
        }
        if (starts.length === 0) {
            return code;
        }
        return code.slice(starts[starts.length - 1], end);
    }

    test('THE GUARD: a spec that creates through the UI registers what it created', () => {
        const offenders = [];
        for (const site of UI_CREATE_SITES) {
            const spec = SPECS.find((entry) => entry.file === site.spec);
            if (spec === undefined) {
                offenders.push(
                    `  ${site.spec}  is listed as creating "${site.kind}" through the UI but the file does not ` +
                        'exist any more — the table is asserting about a spec that is gone',
                );
                continue;
            }
            const missingControl = site.controls.filter((control) => !spec.code.includes(control));
            if (missingControl.length > 0) {
                offenders.push(
                    `  ${site.spec}  is listed as creating a ${site.kind} but none of ` +
                        `${JSON.stringify(missingControl)} appears in it any more — the table claims coverage ` +
                        'that the spec no longer has',
                );
                continue;
            }
            // The registration has to be in the SAME test that creates the row,
            // because the ledger is emptied per test (`resetOwnedRows` in
            // `beforeEach`) — a registration in a sibling test registers nothing
            // for this one. See `testBlockContaining` for why that granularity is
            // measured rather than assumed.
            const unregistered = site.controls.filter((control) => {
                const block = testBlockContaining(spec.code, spec.code.indexOf(control));
                return !registerCallIn(block, site.kind);
            });
            if (unregistered.length > 0) {
                offenders.push(
                    `  ${site.spec}  creates a ${site.kind} through the UI (${JSON.stringify(unregistered)}) ` +
                        `but that test never calls rememberOwnedRow('${site.kind}'). A create form answers no ` +
                        "id, so the test's only handle is the name it typed — without a registration the row " +
                        'survives this test, the next run inherits it, and every assertion in the spec stays ' +
                        'green. (A registration in a SIBLING test does not count: the ledger is emptied per ' +
                        'test.)',
                );
                continue;
            }
            // …and ENOUGH of them: one per create, not one per test. MEASURED — with
            // the boolean alone, deleting ONE of `admin-category.spec.ts`'s two
            // registrations left this gate GREEN (28 passed), because the other
            // one is in the same block. A spec that registers some of its rows is
            // a spec that leaks the rest, and that is the failure the board
            // recorded, one step smaller.
            //
            // The count is read on the FIRST control's block: a site whose controls
            // sit in different tests (`admin-venue.spec.ts`, two venues) has one
            // entry per test, so its block is the one its own `creates` describes.
            const block = testBlockContaining(spec.code, spec.code.indexOf(site.controls[0]));
            const found = registrationsIn(block, site.kind);
            if (found < site.creates) {
                offenders.push(
                    `  ${site.spec}  creates ${site.creates} ${site.kind} row(s) through the UI in ONE test but ` +
                        `that test registers ${found}. Every create needs its own registration: they are not ` +
                        'interchangeable, and the ones nobody registered survive this test and the next run ' +
                        'inherits them. (Raise `creates` if the test really creates more.)',
                );
            }
        }
        expect(
            offenders,
            'These specs create rows through the UI and never hand them back. "Every row a test creates must ' +
                'be registered" cannot be enforced for a form submit by looking at helper calls — the create ' +
                'happens in the browser, not in the spec — so it is listed as data and checked here. Register ' +
                'the row by looking it up under its exact, worker-stamped name right after the submit:\n' +
                "    rememberOwnedRow('mandants', await findCreatedRowId(…));\n" +
                offenders.join('\n'),
        ).toEqual([]);
    });

    test('the UI-create table has no dead entry', () => {
        // The twin of the check above, for the same reason `FIXTURE_CREATORS` has
        // one: a table entry that no longer describes anything is the "looks like
        // coverage" edit this whole file exists to be against.
        const stale = [];
        for (const site of UI_CREATE_SITES) {
            const spec = SPECS.find((entry) => entry.file === site.spec);
            if (spec === undefined || !site.controls.some((control) => spec.code.includes(control))) {
                stale.push(`${site.spec} (${site.kind}, ${JSON.stringify(site.controls)})`);
            }
        }
        expect(
            stale,
            'UI_CREATE_SITES entries whose control is not in the spec any more — dead coverage that hides a ' +
                'spec that stopped registering',
        ).toEqual([]);

        // …and `creates` has to be a real count on every entry. This is NOT a
        // style assertion: the guard compares `registrations < site.creates`, and
        // against `undefined` every comparison is FALSE — so an entry that forgot
        // the field would silently satisfy the guard it exists to enforce. That
        // is the fail-OPEN direction, and it is the one direction this check may
        // never take.
        const uncounted = UI_CREATE_SITES.filter(
            (site) => !Number.isInteger(site.creates) || site.creates < 1,
        ).map((site) => `${site.spec} (${site.kind})`);
        expect(
            uncounted,
            'UI_CREATE_SITES entries with no positive integer `creates` — the per-create count in the guard above ' +
                'compares against it, so a missing value makes that comparison vacuously pass',
        ).toEqual([]);

        // Non-vacuity from the other side: the table must actually contain the
        // creates this position added, or the guard would be checking three
        // entries and looking thorough. Named explicitly, so adding a spec is a
        // visible edit here rather than a silent one.
        expect(UI_CREATE_SITES.map((site) => `${site.spec} → ${site.kind}`).sort()).toEqual([
            'tests/e2e/admin-category.spec.ts → categories',
            'tests/e2e/admin-event.spec.ts → events',
            'tests/e2e/admin-mandant.spec.ts → mandantDomains',
            'tests/e2e/admin-mandant.spec.ts → mandants',
            'tests/e2e/admin-mandant.spec.ts → teams',
            'tests/e2e/admin-venue.spec.ts → teams',
            'tests/e2e/admin-venue.spec.ts → venues',
            'tests/e2e/admin-venue.spec.ts → venues',
            'tests/e2e/approvals.spec.ts → blacklists',
            'tests/e2e/approvals.spec.ts → subAccreditations',
        ]);
    });

    test('a raw create POST makes a spec a creator even with no registration at all', () => {
        // The automatic, table-free half. A spec that POSTs to a collection route
        // of the teardown plan creates a row the ledger is able to address, so it
        // has to be one whether it says so or not — which is the difference
        // between a gate and a note.
        const creators = [];
        for (const spec of SPECS) {
            for (const route of PLAN_COLLECTIONS) {
                if (postsToCollectionRoute(spec.code, route)) {
                    creators.push(spec.file);
                    break;
                }
            }
        }
        // Non-vacuous in both directions: the scan must find a real number of
        // specs, and the spec that creates a row of every creatable kind — the
        // ledger walk — must show up in it.
        expect(new Set(creators).size, 'the collection-route scan found nothing at all').toBeGreaterThan(3);
        expect(
            creators,
            'every spec that posts to a teardown collection route is classified as a creator',
        ).toContain('tests/e2e/ownership.spec.ts');
    });
});
