import { expect, test } from '@playwright/test';
import { loginAdminApi } from '../helpers/api-session';
import { rememberOwnedRow, resetOwnedRows } from '../helpers/ownership';
import { writeProbeRecord } from './probe-record';
import { PROBE_CONTROL_RECORD_PATH, PROBE_RECORD_PATH } from './probe-record';

/**
 * THE DELIBERATELY-FAILING PROBE. Not a suite — two tests, one of which dies on
 * purpose, so that `ownership.spec.ts` can measure what survives it.
 *
 * ## The claim under test
 *
 * "Three fixtures created, the fourth throws — the three still go back." That is
 * the central promise of the ownership ledger, and a PASSING test cannot show
 * it: the failure is the input. So this file fails on purpose, in its own
 * Playwright run, and the driver reads the database afterwards.
 *
 * ## The negative control, and why it is the more important half
 *
 * The second test creates a row and deliberately does NOT register it. Without
 * it, the driver's "the registered row is gone" assertion could be satisfied by
 * something other than the teardown — a cascade, another spec, a sweep — and the
 * measurement would have no control arm. With it, the SAME run leaves a row
 * behind on purpose, and the driver asserts that this one SURVIVES while the
 * registered one does not. The difference between the two outcomes isolates the
 * cause to exactly one thing: the `rememberOwnedRow` call.
 *
 * That is also why the control is a separate file-scope concern rather than a
 * branch inside the failing test: the two outcomes have to be comparable, and a
 * shared teardown would apply to both.
 *
 * ## Neither row is E2E-Test-asserted here
 *
 * The probe's job is to create rows and (not) hand them back. It asserts only
 * that its own create really happened, because a probe that silently failed to
 * create anything would make the driver's "the row is gone" pass for the wrong
 * reason.
 */
test('creates one category, registers it, then dies before the second fixture exists', async () => {
    resetOwnedRows();

    const api = await loginAdminApi();
    let createdId = 0;
    try {
        const suffix = `${process.pid}`;
        const create = await api.post('/api/admin/categories', {
            data: { name: `E2E Halbfehlschlag ${suffix}`, slug: `e2e-halbfehlschlag-${suffix}` },
        });
        if (create.status() !== 201) {
            throw new Error(`the probe could not create its fixture: status ${create.status()}`);
        }
        createdId = (await create.json()).data.id;

        // Registered BEFORE the throw below. That single line is the whole
        // mechanism: the row exists, it is owned, and the hook that runs after
        // this throw is the one that gives it back. A ledger that only
        // remembered rows at the END of a successful fixture run would strand
        // `createdId` here — which is the bug this file exists to expose.
        rememberOwnedRow('categories', createdId);

        // Proof the row really is there, from inside the test. A failure HERE
        // would tell the driver the fixture never existed, which is a different
        // diagnosis than "the teardown did not run".
        const rows = (await (await api.get('/api/admin/categories')).json()).data ?? [];
        let seen = false;
        for (const row of rows) {
            if (row.id === createdId) {
                seen = true;
            }
        }
        expect(seen, 'the probe must have created its row before it dies, or the proof is vacuous').toBe(true);

        writeProbeRecord(PROBE_RECORD_PATH, { id: createdId, pid: process.pid });
    } finally {
        await api.dispose();
    }

    // ── the deliberate failure ──────────────────────────────────────────────
    // The second fixture is NEVER created, so the teardown has exactly one row
    // to give back. The `afterEach` that does it is the file's own; see
    // `../playwright.ownership-probe.config.ts` for why this run has no global
    // teardown that could have done it instead.
    throw new Error('deliberate half-failure: the second fixture was never created');
});

test.afterEach(async () => {
    // The real teardown under test. It is a FILE-SCOPE hook in the real specs
    // too, and the point of this probe is that it runs even though the body
    // above threw — so this file must NOT have a `globalTeardown` doing the same
    // job. See the config's docblock.
    const { reclaimOwnedRows } = await import('../helpers/ownership');
    await reclaimOwnedRows();
});

test('negative control: creates one category and deliberately forgets to register it', async () => {
    resetOwnedRows();

    const api = await loginAdminApi();
    let createdId = 0;
    try {
        const suffix = `${process.pid}-control`;
        const create = await api.post('/api/admin/categories', {
            data: { name: `E2E Halbfehlschlag ${suffix}`, slug: `e2e-halbfehlschlag-${suffix}-control` },
        });
        if (create.status() !== 201) {
            throw new Error(`the control could not create its fixture: status ${create.status()}`);
        }
        createdId = (await create.json()).data.id;
        // NO `rememberOwnedRow` here, on purpose: that omission is the experiment.
    } finally {
        await api.dispose();
    }

    writeProbeRecord(PROBE_CONTROL_RECORD_PATH, { id: createdId, pid: process.pid });
    // No throw. This probe is expected to PASS: it is the baseline the driver
    // compares the registered fixture's outcome against.
});
