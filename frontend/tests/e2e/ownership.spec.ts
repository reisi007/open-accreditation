import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { loginAdminApi } from './helpers/api-session';
import { E2E_OWNED_TEARDOWN, reclaimOwnedRows, rememberOwnedRow, resetOwnedRows } from './helpers/ownership';
import {
    PROBE_CONTROL_RECORD_PATH,
    PROBE_RECORD_PATH,
    clearProbeRecord,
    readProbeRecord,
} from './ownership-probe/probe-record';

/**
 * The same file-scope ownership hooks every other spec carries — this one eats
 * its own dog food, and the gate in `namespace-isolation.spec.ts` would flag it
 * otherwise (it creates fixtures). At file scope because this file has THREE
 * describes; a describe-scoped hook would leave the other two uncovered, which is
 * the measured `admin-mobile-layout.spec.ts` shape.
 */
test.beforeEach(async () => {
    resetOwnedRows();
});
test.afterEach(async () => {
    await reclaimOwnedRows();
});

/**
 * ## The half-failure proof
 *
 * "Every E2E test registers what it created and deletes it itself — even when it
 * fails half-way: three fixtures created, the fourth throws, the three go back."
 *
 * A passing test cannot demonstrate that, because the failure IS the input. So
 * this file runs a SEPARATE Playwright process
 * (`playwright.ownership-probe.config.ts`) whose first test throws on purpose,
 * and then asks the database what survived. Three things have to be true
 * simultaneously for the claim to hold, and each is a separate failure mode:
 *
 * 1. the probe really ran and really created a row;
 * 2. that row is gone afterwards;
 * 3. a row the probe created but did NOT register is still there.
 *
 * (3) is the negative control, and without it (2) proves nothing: the row could
 * have been taken by a cascade, a sibling spec, or the serial name sweep, and the
 * teardown could have done nothing at all. (1) is what keeps the whole thing
 * from passing vacuously — a probe whose create failed would leave no row, and
 * "no row is left" would be true for the wrong reason.
 *
 * ## Why the child run has no global teardown
 *
 * `playwright.ownership-probe.config.ts` deliberately declares no
 * `globalSetup`/`globalTeardown`. The serial teardown reclaims every `E2E %` row
 * by name prefix; if it ran in the child, it would delete the probe's leftovers
 * and BOTH (2) and (3) would pass with the per-test teardown removed entirely.
 * That is the F2 shape — a test that reports a guarantee no code provides — and
 * the config's docblock says so at the place where someone would be tempted to
 * "fix" it by adding the teardown back.
 */
test.describe('the ownership ledger gives a half-failed test its fixtures back', { tag: ['@regression', '@feature:e2e-hygiene'] }, () => {
    // A child Playwright run is a heavy thing to start, and it needs the dev
    // stack up. 5 minutes is generous for one API-only test; the default 30 s is
    // not, on a loaded machine.
    test.setTimeout(300000);

    // DESKTOP ONLY, and that is a correctness requirement rather than a
    // budget-saving one. The probe is a MACHINE-level experiment with a single
    // hand-off file between it and this driver, so running it once per browser
    // project means two driver tests, two child Playwright processes and one
    // shared record path, concurrently. MEASURED in a full run: the residue check
    // at the bottom of this file failed with "ownership-probe-record.json
    // survived", because the Mobile driver's probe wrote the file after the
    // Desktop driver had deleted it — a race only the shared path made possible,
    // and one that would have had the proof measuring whichever driver won.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    /**
     * Run the probe suite and hand back its combined output plus exit status.
     *
     * `stdio: 'pipe'` so the child's own failure text reaches the assertion
     * below instead of scrolling past: the driver must show that the child
     * failed for the DECLARED reason, not for a typo in the probe.
     */
    function runProbe() {
        try {
            const stdout = execFileSync(
                'npx',
                ['playwright', 'test', '-c', 'tests/e2e/playwright.ownership-probe.config.ts'],
                { encoding: 'utf8', stdio: 'pipe', env: { ...process.env, CI: '' } },
            );
            return { status: 0, output: stdout };
        } catch (error) {
            // `unknown` narrowed structurally: a thrown `execFileSync` error is
            // not typed, and this directory may not annotate a narrowing variable
            // (no TS syntax). `'stdout' in error` is the check that actually
            // holds, and it is what the two reads below depend on.
            let status = 1;
            let output = '';
            if (error && typeof error === 'object') {
                if ('status' in error && typeof error.status === 'number') {
                    status = error.status;
                }
                if ('stdout' in error && typeof error.stdout === 'string') {
                    output = error.stdout;
                }
                if ('stderr' in error && typeof error.stderr === 'string') {
                    output += error.stderr;
                }
            }
            return { status, output };
        }
    }

    /**
     * Does a category with this id still exist? Read through the ADMIN list,
     * which is mandant-scoped — the same surface the teardown deletes through,
     * so a row the teardown removed is genuinely absent rather than merely
     * invisible to the owner that could see it.
     */
    async function categoryExists(id = 0) {
        const api = await loginAdminApi();
        try {
            const rows = (await (await api.get('/api/admin/categories')).json()).data ?? [];
            for (const row of rows) {
                if (row.id === id) {
                    return true;
                }
            }
            return false;
        } finally {
            await api.dispose();
        }
    }

    test('a test that dies after its first fixture still gets that fixture back — and one it forgot does not', async () => {
        // A record left by an interrupted earlier run would be read as this run's
        // result, so both are cleared first. The child's `outputDir` cleanup does
        // NOT reach them (see `probe-record.ts`), which is why this is needed.
        clearProbeRecord(PROBE_RECORD_PATH);
        clearProbeRecord(PROBE_CONTROL_RECORD_PATH);

        const run = runProbe();

        const registered = readProbeRecord(PROBE_RECORD_PATH);
        const control = readProbeRecord(PROBE_CONTROL_RECORD_PATH);

        // 1. The child really ran, and really FAILED — the deliberate throw. A
        //    green child would mean the probe stopped failing, i.e. the input
        //    this measurement depends on is gone.
        expect(
            run.status,
            `the probe run must FAIL (it throws on purpose) and must fail for that reason.\n` +
                `Child output:\n${run.output}`,
        ).not.toBe(0);
        expect(
            run.output,
            'the child must fail with the deliberate error, not with a typo in the probe itself',
        ).toContain('deliberate half-failure');

        // 2. And it really created rows. Without this the rest is vacuous.
        expect(registered, 'the probe wrote no record — it never created its fixture, so nothing is proven').not.toBeNull();
        expect(control, 'the control probe wrote no record — the comparison arm is missing').not.toBeNull();
        expect(registered.id, 'the probe must have received a real id from the create response').toBeGreaterThan(0);
        expect(control.id).toBeGreaterThan(0);

        // 3. THE CLAIM. The registered row is gone even though the test threw.
        expect(
            await categoryExists(registered.id),
            `category ${registered.id} was registered with the ownership ledger and the test then threw on ` +
                'purpose — the per-test afterEach must still have given it back. If this row survives, the ' +
                'teardown is not running on a failed test, and every other claim about ownership is void.',
        ).toBe(false);

        // 4. THE CONTROL. The unregistered row is still there, which is what
        //    makes (3) mean "the teardown ran" rather than "something else
        //    deleted it".
        expect(
            await categoryExists(control.id),
            `category ${control.id} was created WITHOUT being registered, so it must still exist. If it is ` +
                'gone too, then something other than the ownership ledger is deleting rows (a cascade, the ' +
                'serial name sweep, a sibling spec) and this whole file is measuring the wrong thing.',
        ).toBe(true);

        // The control's row is the one deliberate leftover this harness creates.
        // Nobody else can address it by id, so the driver is the only place that
        // can — and it must, or every run of this test would add a row.
        await deleteCategory(control.id);
        clearProbeRecord(PROBE_RECORD_PATH);
        clearProbeRecord(PROBE_CONTROL_RECORD_PATH);
    });

    /** Remove one category by id, for the control row the driver is responsible for. */
    async function deleteCategory(id = 0) {
        const api = await loginAdminApi();
        try {
            const response = await api.delete(`/api/admin/categories/${id}`);
            expect(
                [204, 404],
                `the driver could not clean up its own control row ${id} (status ${response.status()})`,
            ).toContain(response.status());
        } finally {
            await api.dispose();
        }
    }
});

/**
 * ## The teardown itself, exercised directly
 *
 * The probe above proves the teardown runs on a failed test. These prove what
 * it DOES when it runs — that a row of each kind is really deleted, and that a
 * row it CANNOT delete takes the run down with it.
 *
 * The two are deliberately paired. A teardown that deletes nothing and a
 * teardown that swallows every failure produce the same green run, and the first
 * is only distinguishable from "it did its job" because the second is visible.
 */
test.describe('the ownership teardown gives back what the test registered', { tag: ['@regression', '@feature:e2e-hygiene'] }, () => {
    test.setTimeout(120000);

    /**
     * The rows the creating test built, so the serial pair can ask whether they
     * survived. Module scope because Playwright offers no way to pass state
     * between tests, and `describe.serial` below is what makes reading it in the
     * FOLLOWING test legitimate rather than a race.
     *
     * Seeded rather than annotated, because this directory forbids annotations
     * (see `helpers/ownership.ts`, where the same rule is measured in a table).
     */
    const CREATED = [{ kind: '', id: 0, listUrl: '' }];
    CREATED.length = 0;

    /** Does the admin list of one collection still contain this id? */
    async function rowStillExists(listUrl = '', id = 0) {
        const api = await loginAdminApi();
        try {
            const rows = (await (await api.get(listUrl)).json()).data ?? [];
            for (const row of rows) {
                if (row.id === id) {
                    return true;
                }
            }
            return false;
        } finally {
            await api.dispose();
        }
    }

    test.describe.serial('one row of every creatable kind', () => {
        test('CREATING: each is registered with the ledger the moment it exists', async () => {
            // Walks the REAL plan rather than a restatement of it, so a kind added
            // to `E2E_OWNED_TEARDOWN` is covered the day it is added.
            //
            // Three kinds are skipped, each for a stated reason rather than by
            // omission: the mandant-scoped routes need a parent this test does not
            // create (their own specs register mandants and domains), and the
            // owner-scoped ones answer only for the owning account (the probe's
            // control arm and `profile.spec.ts` cover those).
            const api = await loginAdminApi();
            try {
                for (const step of E2E_OWNED_TEARDOWN) {
                    if (!step.reclaimable || step.route === null || step.route.includes('{parentId}') || step.actor === 'owner') {
                        continue;
                    }

                    let path = '';
                    let data = {};
                    const suffix = `e2e-ledger-${process.pid}-${CREATED.length}`;

                    if (step.kind === 'categories') {
                        path = '/api/admin/categories';
                        data = { name: `E2E Ledger ${suffix}`, slug: suffix };
                    } else if (step.kind === 'events') {
                        path = '/api/admin/events';
                        data = { title: `E2E Ledger ${suffix}`, active: true };
                    } else if (step.kind === 'blacklists') {
                        path = '/api/admin/blacklists';
                        data = { email: `${suffix}@example.test`, note: 'ownership ledger' };
                    } else if (step.kind === 'badgeTemplates') {
                        path = '/api/admin/badge-templates';
                        data = {
                            name: `E2E Ledger ${suffix}`,
                            is_default: false,
                            layout: [{ field: 'name', x: 10, y: 10, w: 80, h: 10, size: 14, align: 'left' }],
                        };
                    } else if (step.kind === 'accreditations') {
                        // An accreditation needs a category and an event, so this
                        // branch creates its own and registers each immediately —
                        // the same push-after-create shape the real fixtures use.
                        // That also means the category cascade cannot mask a
                        // missing accreditation delete: the teardown reaches the
                        // accreditation first.
                        const cat = (
                            await (await api.post('/api/admin/categories', { data: { name: `E2E Ledger ${suffix}`, slug: suffix } })).json()
                        ).data;
                        rememberOwnedRow('categories', cat.id);
                        const ev = (
                            await (await api.post('/api/admin/events', { data: { title: `E2E Ledger ${suffix} Event`, active: true } })).json()
                        ).data;
                        rememberOwnedRow('events', ev.id);
                        path = '/api/admin/accreditations';
                        data = {
                            category_id: cat.id,
                            scope: 'event',
                            event_id: ev.id,
                            quota: 5,
                            deadline_start: '2020-01-01',
                            deadline_end: '2035-01-01',
                            auto_approve: false,
                            active: true,
                        };
                    } else {
                        continue;
                    }

                    const response = await api.post(path, { data });
                    expect(response.status(), `could not create the ${step.kind} fixture at ${path}`).toBe(201);
                    const row = (await response.json()).data;
                    rememberOwnedRow(step.kind, row.id);
                    CREATED.push({ kind: step.kind, id: row.id, listUrl: path });
                }
            } finally {
                await api.dispose();
            }

            expect(
                CREATED.length,
                'the plan yielded no creatable fixture — either the walk is vacuous or every kind became ' +
                    'unreachable here and this test has stopped testing anything',
            ).toBeGreaterThanOrEqual(5);
        });

        test('the teardown deleted every one of them', async () => {
            // Runs AFTER the creating test, whose `afterEach` has already
            // reclaimed. If the creating test never ran, "nothing is left" would
            // be trivially true — so the count is asserted first.
            expect(
                CREATED.length,
                'the creating test did not run or registered nothing — this assertion would be vacuous',
            ).toBeGreaterThanOrEqual(5);

            const stillThere = [];
            for (const row of CREATED) {
                if (await rowStillExists(row.listUrl, row.id)) {
                    stillThere.push(`${row.kind} ${row.id}`);
                }
            }
            expect(
                stillThere,
                'these rows were registered with the ownership ledger and their test has already ended — they ' +
                    'must be gone. Each one is a fixture this suite created and did not return:\n' +
                    stillThere.join('\n'),
            ).toEqual([]);
        });
    });

    test('a row the teardown CANNOT delete takes the run down with it', async () => {
        // THE F1 REPRODUCTION, in miniature and on purpose.
        //
        // F1: the venue sweep issued `await api.delete(...)`, threw the answer
        // away, 53 deletions were refused with 409, and the run reported success.
        // Here a venue is created and referenced by a team, so the backend
        // refuses to delete it — and the teardown must FAIL rather than shrug.
        //
        // Only the VENUE is registered. Registering the team too would delete it
        // first and make the venue delete succeed — which is what the plan's
        // order is supposed to guarantee anyway, so registering it here would
        // test the ordering and not the loudness. The loudness is the point.
        // The unblock lives in a `finally`, not in the happy path, and that is
        // not style. This test's whole subject is "a test that dies still gives
        // its rows back", and the first version of it did the opposite: three
        // `E2E Ledger e2e-409-*` venue/team pairs were left in the dev database
        // (MEASURED, by name), because every assertion between the create and the
        // unblock can throw and skip it. A test about not leaking that leaks when
        // it fails is worse than one that never claimed to be about it.
        let venueId = 0;
        let teamId = 0;
        let mandantId = 0;
        const api = await loginAdminApi();
        const suffix = `e2e-409-${process.pid}`;
        try {
            const venue = (await (await api.post('/api/admin/venues', { data: { name: `E2E Ledger ${suffix}` } })).json()).data;
            venueId = venue.id;
            const mandants = (await (await api.get('/api/admin/mandants')).json()).data ?? [];
            let primary = null;
            for (const mandant of mandants) {
                if (mandant.is_primary) {
                    primary = mandant;
                    break;
                }
            }
            if (primary === null) {
                primary = mandants[0] ?? null;
            }
            expect(primary, 'this test needs a mandant to hang the referencing team on').not.toBeNull();
            mandantId = primary.id;

            const team = (
                await (
                    await api.post(`/api/admin/mandants/${mandantId}/teams`, {
                        data: { name: `E2E Ledger ${suffix}`, slug: suffix, venue_id: venue.id },
                    })
                ).json()
            ).data;
            teamId = team.id;

            rememberOwnedRow('venues', venue.id);
            // The team is deliberately NOT registered — that is what makes the
            // venue's delete answer 409.

            let refused = null;
            try {
                await reclaimOwnedRows();
            } catch (error) {
                // Narrowed structurally rather than annotated — this directory
                // forbids annotations, and `instanceof Error` is the honest check:
                // the teardown is contractually required to raise, so anything else
                // is a different bug worth seeing.
                if (error instanceof Error) {
                    refused = error;
                }
            }
            expect(
                refused,
                'deleting a referenced venue answers 409 and the teardown swallowed it — the portal’s ' +
                    '`deleteResources` did exactly this, which is how 53 refused deletions became a green run',
            ).not.toBeNull();
            expect(
                refused?.message ?? '',
                'the failure must NAME the row and the status, so a reader can see which fixture survived',
            ).toContain('409');
            expect(refused?.message ?? '').toContain(String(venue.id));

            // The venue is genuinely still there — the 409 was not a phantom.
            const venues = (await (await api.get('/api/admin/venues')).json()).data ?? [];
            let stillReferenced = false;
            for (const row of venues) {
                if (row.id === venueId) {
                    stillReferenced = true;
                }
            }
            expect(stillReferenced, 'the venue should have survived its own refused delete').toBe(true);
        } finally {
            // Unblock in this order: the team references the venue, and the venue
            // route answers 409 while it does. The status is NOT asserted here on
            // purpose — this block runs precisely when an assertion above has
            // already failed, and a second failure would mask the first. The
            // serial teardown is the net for whatever this leaves.
            if (mandantId !== 0 && teamId !== 0) {
                await api.delete(`/api/admin/mandants/${mandantId}/teams/${teamId}`);
            }
            if (venueId !== 0) {
                await api.delete(`/api/admin/venues/${venueId}`);
            }
            await api.dispose();
        }
    });
});

/**
 * A leftover from an aborted probe run would be invisible residue. The driver
 * clears both records; this is the belt to that braces, and it also fails loudly
 * if a probe run is killed so hard the driver's cleanup never happens.
 */
test('no probe record survives this run', { tag: ['@regression', '@feature:e2e-hygiene'] }, async ({}, testInfo) => {
    // DESKTOP ONLY, for the same reason as the driver above: a second browser
    // project is a second writer of the same file, and this check would be
    // reporting on a probe that is legitimately still in flight.
    test.skip(testInfo.project.name !== 'Desktop Chrome');
    for (const recordPath of [PROBE_RECORD_PATH, PROBE_CONTROL_RECORD_PATH]) {
        expect(
            fs.existsSync(recordPath),
            `${recordPath} survived — the driver did not clean up after itself, and its probe left a ` +
                'deliberate control row that nothing can now address by id or by name',
        ).toBe(false);
    }
});
