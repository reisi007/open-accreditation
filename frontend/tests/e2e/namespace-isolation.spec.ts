import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { E2E_PURGE_MARKERS } from './helpers/admin-data';
import {
    ALL_REVIEW_NAMES,
    ALL_REVIEW_SLUGS,
    ALL_REVIEW_USER_PREFIXES,
} from '../screenshots/helpers/fixture-names';

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
