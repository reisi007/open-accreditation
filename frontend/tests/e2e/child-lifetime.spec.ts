import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { runChild } from './ownership-probe/run-child';

/**
 * ## The child must be KILLED, not merely waited for
 *
 * A driver with no timeout on its child process is not merely slow. When the
 * child hangs, the parent hits Playwright's own `setTimeout` and the test is
 * reported as failed — while the child KEEPS RUNNING and keeps writing rows into
 * the shared dev database. The failure is reported once; the writes continue, and
 * land in the NEXT run's measurements as rows nobody can attribute.
 *
 * That is a much worse failure than one red test, and it is invisible: the run
 * that caused it is over, and the run that inherits the damage is a different
 * test in a different file.
 *
 * So this is a test about the absence of a process. That is an unusual thing to
 * assert, and it has two failure modes worth stating:
 *
 * - A false PASS: checking too soon and seeing no process, while the child has
 *   not started yet. Avoided by waiting for the child to prove it is ALIVE first
 *   (the hang fixture writes its pid before it blocks, so a visible pid is a
 *   positive signal rather than an absence of one).
 * - A false FAIL: `process.kill(pid, 0)` throwing for a pid this process does not
 *   own. The check therefore treats ESRCH as "gone" and anything else as an
 *   error — the narrow reading, because a permission error would otherwise be
 *   reported as a surviving process and send the reader after the wrong cause.
 */

/** How long to wait for the SIGKILL to be observed. Generous on purpose. */
const DEATH_GRACE_MS = 5000;

/** Is this pid still running? `false` once it is gone; throws only on surprises. */
function isAlive(pid = 0) {
    if (pid <= 0) {
        return false;
    }
    try {
        process.kill(pid, 0);
        return true;
    } catch (error) {
        // Narrowed structurally (this directory forbids TS annotations): ESRCH is
        // "no such process", which is the answer we came for.
        if (error && typeof error === 'object' && 'code' in error && error.code === 'ESRCH') {
            return false;
        }
        throw error;
    }
}

/** Poll until the pid is gone, or the grace period runs out. */
async function waitUntilGone(pid = 0) {
    const deadline = Date.now() + DEATH_GRACE_MS;
    while (Date.now() < deadline) {
        if (!isAlive(pid)) {
            return true;
        }
        await new Promise((resolve) => {
            setTimeout(resolve, 100);
        });
    }
    return !isAlive(pid);
}

const HANG_RECORD = path.resolve(process.cwd(), 'test-results/ownership-hang-probe.json');

test.describe('the child probe process is killed, not left running', { tag: ['@regression', '@feature:e2e-hygiene'] }, () => {
    // The kill has to be proven well inside the driver's own 300 s ceiling, and
    // this test spawns a whole Playwright runner to do it, so the budget is
    // generous — but it is a CEILING, not the thing being measured.
    test.setTimeout(180000);

    // One writer of the hand-off file: the same single-writer reason the probe
    // driver has (two projects means two writers and a race).
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('a child that hangs is killed, and stops writing', async () => {
        const started = Date.now();
        fs.mkdirSync(path.dirname(HANG_RECORD), { recursive: true });
        // Existence-checked unlink rather than `{ force: true }`, which the repo's
        // ESLint config forbids in `tests/e2e`.
        if (fs.existsSync(HANG_RECORD)) {
            fs.unlinkSync(HANG_RECORD);
        }

        // The SAME helper the real driver uses, with the production constant
        // scaled down so a test can afford it. The production value is 240 s in
        // `ownership.spec.ts` and stays a constant there; what is under test is
        // that the child is bounded AT ALL and that the bound reaches the process
        // doing the writing — which is the whole point, see `run-child.ts`.
        //
        // 20 s, not 6 s, and that number was raised by a MEASURED full-suite
        // flake: under 8-worker load the child's `npx playwright test` boot
        // exceeded a 6 s budget, the group was killed before the fixture wrote
        // its pid, and the test reported "the hang probe never ran" — a false
        // accusation about the probe rather than about the kill. The budget has
        // to clear the child's COLD START, and the cold start under load is what
        // sets the floor.
        const KILL_AFTER_MS = 20000;
        const result = await runChild(
            'npx',
            ['playwright', 'test', '-c', 'tests/e2e/playwright.hang-probe.config.ts'],
            KILL_AFTER_MS,
            { ...process.env, CI: '', PROBE_HANG_RECORD_PATH: HANG_RECORD },
        );
        const status = result.status;
        const output = result.output;
        const elapsedMs = Date.now() - started;

        // 1. The child really got as far as RUNNING and blocking. Without this the
        //    rest could pass for the wrong reason — a child that never started is
        //    also a child that is not running afterwards.
        expect(
            fs.existsSync(HANG_RECORD),
            'the hang probe never wrote its pid, so it never ran and this test proves nothing. Child output:\n' +
                output,
        ).toBe(true);
        const record = JSON.parse(fs.readFileSync(HANG_RECORD, 'utf8'));
        expect(record.pid, 'the hang probe must have reported a real pid').toBeGreaterThan(0);

        // 2. The kill was reported as a kill. Checked before the process check
        //    because a helper that resolved "successfully" without signalling
        //    would make the process check below pass for the wrong reason.
        expect(
            result.killedForTimeout,
            'the helper reported that the child exited on its own, so nothing was killed. A child that ' +
                'finishes before its own deadline is not the failure this test exists to catch. Child output:\n' +
                output,
        ).toBe(true);

        // 3. And the PROCESS IS GONE — the whole group, not just the launcher.
        //    This is the assertion that distinguishes the real fix from the
        //    obvious one: MEASURED, `execFileSync`'s `timeout` + `killSignal`
        //    killed `npx` and left BOTH the Playwright runner and its worker
        //    running. So "the launcher is gone" is not a sufficient check, and
        //    this one reads the pid of the WORKER the fixture reported.
        expect(
            await waitUntilGone(record.pid),
            `the hung child process ${record.pid} is STILL RUNNING ${DEATH_GRACE_MS}ms after the driver's ` +
                `${KILL_AFTER_MS}ms timeout fired. This is the exact failure the child timeout exists to ` +
                'prevent: the parent test reports a failure and the child keeps writing rows into the shared ' +
                'dev database. If this fires while the launcher is gone, the kill reached only the process ' +
                `it spawned and not the process group.\nChild output:\n${output}`,
        ).toBe(true);

        expect(
            elapsedMs,
            'the child finished suspiciously fast — if it exited before the timeout, this test proved ' +
                'nothing about the KILL, only that the hang probe does not hang.',
        ).toBeGreaterThanOrEqual(KILL_AFTER_MS);

        expect(
            status,
            'a killed child has no exit status of its own; a zero here would mean it completed, which is the ' +
                `other thing entirely.\nChild output:\n${output}`,
        ).not.toBe(0);

        // The hand-off file is removed with an EXISTENCE CHECK rather than
        // `rmSync(…, { force: true })`: the repo's ESLint config forbids `force`
        // in `tests/e2e` outright, and an unlink that throws ENOENT here would be
        // indistinguishable from a real cleanup failure. A leftover record from a
        // killed run is harmless by construction — the fixture overwrites it
        // before anyone reads it, and this test asserts on the pid inside it.
        if (fs.existsSync(HANG_RECORD)) {
            fs.unlinkSync(HANG_RECORD);
        }
    });
});
