import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import { loginAdminApi } from './helpers/api-session';
import {
    E2E_OWNED_TEARDOWN,
    TEARDOWN_NOT_FOUND,
    TEARDOWN_ROUTE_PROBE_ID,
    classifyNotFoundBody,
    preflightTeardownRoutes,
    reclaimOwnedRows,
    rememberOwnedRow,
    resetOwnedRows,
} from './helpers/ownership';
import {
    PROBE_CONTROL_RECORD_PATH,
    PROBE_RECORD_PATH,
    clearProbeRecord,
    readProbeRecord,
} from './ownership-probe/probe-record';
import { runChild } from './ownership-probe/run-child';

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
    // SERIAL, and the mode is a correctness requirement rather than a budget
    // choice: the residue check at the end of this describe asserts that the
    // hand-off records are GONE, which is only meaningful AFTER the driver has
    // run. MEASURED in a full run with `fullyParallel`: the check ran in another
    // worker while the driver's child was mid-probe, saw the records, and failed
    // — a false accusation against a cleanup that had already run. Serial mode
    // makes the two run one after the other in one worker, which is the order the
    // claim needs.
    test.describe.configure({ mode: 'serial' });
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
     * How long the child may run before it is KILLED, and why the number is
     * below the driver's own timeout rather than at it.
     *
     * The driver sets `test.setTimeout(300000)`. With no bound of its own, a hung
     * child made the parent hit 300 s, Playwright failed the test, and the CHILD
     * KEPT RUNNING — still writing rows into the shared dev database with nobody
     * left to attribute them. That is the worse half of the failure: not one red
     * test but an unbounded set of writes that outlive the run that started them
     * and land in the next run's measurements.
     *
     * 240 s is four minutes of generous headroom over the child's actual cost
     * (one API-only test, ~2 s) and leaves a full minute in which the parent
     * reports the timeout as a readable failure.
     */
    const PROBE_TIMEOUT_MS = 240000;

    /**
     * Run the probe suite and hand back its combined output plus exit status.
     *
     * `runChild`, not `execFileSync` — and the reason is measured, not stylistic.
     * `execFileSync`'s `timeout`/`killSignal` signal ONLY the process it spawned,
     * which here is `npx`; the Playwright runner and its worker are grandchildren
     * and both survived a SIGKILL of the launcher (see `run-child.ts`). A bound
     * that does not reach the process doing the writing is the original bug with a
     * number attached. `runChild` spawns the child into its own process group and
     * signals the GROUP, so the launcher, the runner and the worker all get it.
     *
     * The environment is passed EXPLICITLY rather than inherited for the one
     * variable that decides whether the child's own teardown runs:
     * `E2E_OWNERSHIP=off` reaching the child would neuter the child's per-test
     * teardown, assertion (3) below would fail — and this file used to be
     * shielded from that switch by accident alone: `scripts/e2e-per-spec-leaks.mjs`
     * carried a `--grep-invert` naming this describe's title. A test whose
     * validity depends on a filter in a different tool is not a test of the
     * claim; it is a test of that tool's arguments. MEASURED, the filter was
     * dead anyway: the tool drops this FILE by name, so the grep could never
     * match anything, and the flag has been removed. Pinning the value here is
     * what makes the driver honest — on its own, under any environment, and with
     * no flag elsewhere that has to keep existing.
     *
     * `CI: ''` is still passed deliberately: it is what stops the child's reporter
     * from writing to a CI annotations file this repo does not have.
     */
    async function runProbe() {
        const result = await runChild(
            'npx',
            ['playwright', 'test', '-c', 'tests/e2e/playwright.ownership-probe.config.ts'],
            PROBE_TIMEOUT_MS,
            { ...process.env, CI: '', E2E_OWNERSHIP: 'on' },
        );
        return {
            status: result.status,
            output: result.output,
            killedForTimeout: result.killedForTimeout,
            elapsedMs: result.elapsedMs,
        };
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

        const run = await runProbe();

        const registered = readProbeRecord(PROBE_RECORD_PATH);
        const control = readProbeRecord(PROBE_CONTROL_RECORD_PATH);

        // 0. The child produced a RESULT rather than being killed. Ahead of
        //    "it failed", because a killed child also "failed" — and everything
        //    below reasons about rows the child may never have created. Without
        //    this, a hung probe would surface as a confident, wrong conclusion
        //    about the teardown instead of as a hang.
        expect(
            run.killedForTimeout,
            `the probe was killed after ${PROBE_TIMEOUT_MS / 1000}s instead of finishing, so there is no ` +
                'measurement here at all. Everything below would be reasoning about rows the probe may never ' +
                `have created. It has been SIGKILLed and is no longer writing.\n${run.output}`,
        ).toBe(false);

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

    /**
     * A leftover from an aborted probe run would be invisible residue. The driver
     * clears both records; this is the belt to that braces, and it also fails loudly
     * if a probe run is killed so hard the driver's cleanup never happens.
     *
     * INSIDE the serial describe, after the driver, so it cannot run while the
     * driver's child is still writing (see `test.describe.configure` above).
     */
    test('no probe record survives this run', { tag: ['@regression', '@feature:e2e-hygiene'] }, async ({}, testInfo) => {
        // DESKTOP ONLY, for the same reason as the driver above: a second browser
        // project is a second writer of the same file.
        test.skip(testInfo.project.name !== 'Desktop Chrome');
        for (const recordPath of [PROBE_RECORD_PATH, PROBE_CONTROL_RECORD_PATH]) {
            expect(
                fs.existsSync(recordPath),
                `${recordPath} survived — the driver did not clean up after itself, and its probe left a ` +
                    'deliberate control row that nothing can now address by id or by name',
            ).toBe(false);
        }
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
            // Kinds are skipped for STATED reasons, and the important one is the
            // `creatableHere: false` flag rather than the absence of a branch in
            // the if/else below. MEASURED: the walk used to reach its final `else
            // { continue }` for `badgeImages` and `venues` and this test asserted
            // `toBeGreaterThanOrEqual(5)` — so "the plan yielded five kinds, not
            // seven" passed silently, and the promise "a kind in the plan is
            // covered from the day it is entered" was false for both. The flag
            // makes the skip a data statement the test below can count, and the
            // other two skip reasons stay where they were: the mandant-scoped
            // routes need a parent this file does not create (their own specs
            // register mandants and domains), and the owner-scoped ones answer only
            // for the owning account (the probe's control arm and `profile.spec.ts`
            // cover those).
            const api = await loginAdminApi();
            // Two tallies, both reported rather than asserted inside this test: the
            // assertion lives below, where the created list is in hand and the two
            // numbers can be compared against each other.
            const uncovered = [];
            const covered = [];
            try {
                for (const step of E2E_OWNED_TEARDOWN) {
                    if (
                        !step.reclaimable ||
                        step.route === null ||
                        step.route.includes('{parentId}') ||
                        step.actor === 'owner' ||
                        step.creatableHere === false
                    ) {
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
                        // Unreachable for every entry that is NOT flagged
                        // `creatableHere: false`, and that is the point: the flag is
                        // the skip, so a kind that arrives in the plan without a
                        // branch AND without the flag lands here and is COUNTED as
                        // covered. The count below is what makes that visible —
                        // with a floor (`toBeGreaterThanOrEqual`) it would not be.
                        uncovered.push(step.kind);
                        continue;
                    }

                    const response = await api.post(path, { data });
                    expect(response.status(), `could not create the ${step.kind} fixture at ${path}`).toBe(201);
                    const row = (await response.json()).data;
                    rememberOwnedRow(step.kind, row.id);
                    CREATED.push({ kind: step.kind, id: row.id, listUrl: path });
                    covered.push(step.kind);
                }
            } finally {
                await api.dispose();
            }

            // EXACT count, not a floor. The floor (`toBeGreaterThanOrEqual(5)`) is
            // what let a plan entry with no fixture here pass unnoticed: the number
            // went from seven to five, the floor stayed satisfied, and the promise
            // "a kind in the plan is covered from the day it is entered" quietly
            // stopped being true. An exact list is a claim about WHICH kinds, and
            // it has to be edited when the plan changes — which is the cost that
            // buys the honesty.
            expect(
                covered,
                'the kinds this walk creates, by name. A kind added to E2E_OWNED_TEARDOWN appears here unless ' +
                    'it is flagged creatableHere: false; a kind that is neither created nor flagged is an ' +
                    'UNCOVERED plan entry, and the assertion below lists those separately.',
            ).toEqual(['accreditations', 'categories', 'events', 'blacklists', 'badgeTemplates']);
            expect(
                uncovered,
                'these plan entries are reclaimable but this walk cannot create them, and they are not flagged ' +
                    'creatableHere: false. That means nothing here proves their route or their success status — ' +
                    "flag them, or add a branch. (preflightTeardownRoutes still checks the ROUTE of every entry, " +
                    'so what is missing is the create-and-verify round trip.)',
            ).toEqual([]);
            expect(
                CREATED.length,
                'the plan yielded no creatable fixture — either the walk is vacuous or every kind became ' +
                    'unreachable here and this test has stopped testing anything',
            ).toBeGreaterThanOrEqual(5);
        });

        test('the teardown deleted every one of them', async () => {
            // Runs AFTER the creating test, whose `afterEach` has already
            // reclaimed. If the creating test never ran, "nothing is left" would
            // be trivially true — so the count is asserted first, and against the
            // SAME exact list the creating test used, so a walk that quietly
            // covered less cannot make this one pass for the right reason.
            expect(
                CREATED.map((row) => row.kind),
                'the creating test did not run, or registered a different set of kinds than it asserts — this ' +
                    'assertion would be vacuous',
            ).toEqual(['accreditations', 'categories', 'events', 'blacklists', 'badgeTemplates']);

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
 * ## Two 404s that look identical from the outside
 *
 * The teardown tolerates 404 because "the row is already gone" is the goal
 * state. Tolerating it TOTAL meant a plan entry with a typo passed for the same
 * thing: one character in `/api/admin/mandants` — `/api/admin/mandat` — and the
 * suite stayed green while `mandants` grew by one row per run, without bound.
 *
 * These tests pin the distinction from both ends: the classifier on the exact
 * bodies the backend produces, and the PLAN against the backend so an entry that
 * no test happens to exercise is asked about anyway.
 */
test.describe('the teardown can tell a gone row from a wrong address', { tag: ['@regression', '@feature:e2e-hygiene'] }, () => {
    test('a 404 that means "the row is gone" is tolerated, a 404 that means "no such route" is not', async () => {
        // MEASURED against the running backend: both answer 404, and only the
        // message differs. The two requests are made for real — a classifier
        // tested against bodies typed by hand is a classifier tested against the
        // author's belief about the bodies.
        const api = await loginAdminApi();
        try {
            const rowGone = await api.delete(`/api/admin/categories/${TEARDOWN_ROUTE_PROBE_ID}`);
            const noRoute = await api.delete('/api/admin/categoriez/1');

            // The precondition, stated: the two really are the same status.
            expect(
                rowGone.status(),
                'a real route with an id that cannot exist must answer 404 "row gone"',
            ).toBe(404);
            expect(
                noRoute.status(),
                'a route that does not exist must ALSO answer 404 — that is the whole difficulty',
            ).toBe(404);

            expect(
                classifyNotFoundBody(await rowGone.text()),
                'the row-gone 404 must classify as the goal state, or the teardown would fail on every ' +
                    'cascaded child',
            ).toBe(TEARDOWN_NOT_FOUND.ROW_ABSENT);
            expect(
                classifyNotFoundBody(await noRoute.text()),
                'the route-missing 404 must NOT classify as the goal state — tolerating it is what let a ' +
                    'one-character typo in the teardown plan leak a row per run, silently',
            ).toBe(TEARDOWN_NOT_FOUND.ROUTE_ABSENT);
        } finally {
            await api.dispose();
        }
    });

    test('a 404 nobody can read is a FAILURE, not a tolerated one', () => {
        // Fail-closed, and the reason is worth stating: a 404 the harness cannot
        // classify is indistinguishable from a wrong address, so tolerating it is
        // precisely the shape this section was written to remove. An HTML body
        // from a proxy in front of the API is the realistic version.
        expect(classifyNotFoundBody('<html><body>404 Not Found</body></html>')).toBe(TEARDOWN_NOT_FOUND.UNKNOWN);
        expect(classifyNotFoundBody('')).toBe(TEARDOWN_NOT_FOUND.UNKNOWN);
        // And the message is read, not the debug payload: a body that carries the
        // verdict in a field other than `message` is still not classifiable.
        expect(classifyNotFoundBody(JSON.stringify({ error: 'No query results for model' }))).toBe(
            TEARDOWN_NOT_FOUND.UNKNOWN,
        );
    });

    test('every route in the teardown plan reaches a real delete route', async () => {
        // The plan-level half. A kind no test owns is a route nobody has ever
        // asked about: `badgeImages` has ZERO registrations in the whole suite
        // today, so a typo in its route would be invisible until a test starts
        // owning badge images. This asks the backend about the PLAN instead.
        const reclaimable = E2E_OWNED_TEARDOWN.filter((step) => step.reclaimable && step.route !== null);
        expect(
            reclaimable.length,
            'the plan yielded no reclaimable route — the preflight would then be checking nothing',
        ).toBeGreaterThanOrEqual(10);

        const preflight = await preflightTeardownRoutes();

        expect(
            preflight.rejected,
            'these teardown routes do not reach a per-row delete route. A plan entry that reaches nothing ' +
                'answers 404, the teardown counted that as "already gone", and every row of that kind stayed ' +
                'behind while the suite reported success:\n' +
                preflight.rejected.join('\n'),
        ).toEqual([]);
        // Non-vacuity: the probe really did ask about every reclaimable step, not
        // about a subset that happened to work.
        expect(
            preflight.checked.length,
            `the preflight checked ${preflight.checked.length} of ${reclaimable.length} reclaimable routes — a ` +
                'partial check is the "looks like coverage" edit',
        ).toBe(reclaimable.length);
    });
});

