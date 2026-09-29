import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { expect, test } from '@playwright/test';
import { describeProcessTree, runChild } from './ownership-probe/run-child';

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
 * assert, and it has three failure modes worth stating:
 *
 * - A false PASS: checking too soon and seeing no process, while the child has
 *   not started yet. Avoided by waiting for the child to prove it is ALIVE first
 *   (the hang fixture writes its pid before it blocks, so a visible pid is a
 *   positive signal rather than an absence of one).
 * - A false FAIL: `process.kill(pid, 0)` throwing for a pid this process does not
 *   own. The check therefore treats ESRCH as "gone" and anything else as an
 *   error — the narrow reading, because a permission error would otherwise be
 *   reported as a surviving process and send the reader after the wrong cause.
 * - **A false FAIL from a ZOMBIE — the one that actually happened.** See below.
 *
 * ## A zombie is dead, and `kill(pid, 0)` cannot tell you that
 *
 * MEASURED, on Linux CI, red three times in a row: the worker's pid still
 * answered `process.kill(pid, 0)` five seconds after the group SIGKILL, with the
 * group's signal provably delivered (`killedWith: SIGKILL`, no `killNote`, leader
 * dead) — and the identical test green on darwin. Reproduced end to end here
 * with the real driver in a container whose PID 1 does not reap orphans:
 *
 * ```
 * PID    PPID    PGID STAT COMMAND
 *    51       1      26 Z    node      <- the worker: "Z" = zombie, pgid 26 = the group that was killed
 * ```
 *
 * The reason is the CI *environment*, not the driver. A GitHub Actions container
 * job runs its steps with PID 1 = `tail -f /dev/null`, which never calls
 * `wait()`. A SIGKILLed process is not removed from the process table by the
 * kill — only its PARENT removes it, by reaping. With no reaper, the killed
 * worker stays as `<defunct>` forever, and `kill(pid, 0)` keeps SUCCEEDING,
 * because the pid genuinely still exists. On darwin PID 1 is `launchd`, which
 * reaps, so the entry disappears at once — hence green locally, red on Linux.
 *
 * **A zombie cannot write to the database.** It has no threads left, its address
 * space is gone, and it executes no instructions. The claim this test exists to
 * protect — "the child stops writing" — is satisfied by `Z` in the strongest
 * possible way. Asserting on pid *presence* instead of on *executability* was
 * measuring a property of the init process, not of the driver, which is why it
 * failed on one platform only and looked like a driver bug for a whole session.
 *
 * So the predicate below asks the question the test actually cares about:
 * "can this process still execute?" — `false` for a vanished pid AND for a
 * zombie. The `Z` reading is not a loophole; it is the correct answer, and the
 * failure message prints the `STAT` column so a reader can check that claim for
 * themselves instead of taking it on faith.
 */

/** How long to wait for the SIGKILL to be observed. Generous on purpose. */
const DEATH_GRACE_MS = 5000;

/**
 * Can this process still EXECUTE? `false` once it is gone OR once it is a
 * zombie — the two states in which it provably cannot write anything.
 *
 * A zombie is `Z` (or the rarer `X`/`x` variants some kernels use while a
 * corpse is being torn down). A running process is anything else: `R`, `S`,
 * `D`, `I`, `T` — all of which keep executing and all of which are treated as
 * alive, so the check can only ever fail toward "still running", never toward
 * "gone". That direction is deliberate: a wrongly-alive reading costs one red
 * run, a wrongly-dead one would hide a real leak.
 *
 * ## Why `ps -A -o pid=,stat=` and not `ps -p <pid> -o stat=`
 *
 * `-p` is absent from BusyBox `ps` (measured: `ps: unrecognized option: p`), so
 * the single-pid form would work on exactly the two platforms where this test
 * is NOT failing and break on the third. `-A` with an explicit column list is
 * accepted by procps, BSD `ps` and BusyBox alike, and pairing `pid=` with
 * `stat=` means each code travels with its owner — a bare `-o stat=` table has
 * no pid column, so looking a pid up in it would mean counting lines and being
 * off by one the moment the table's length disagrees with the process list.
 *
 * The cost is one fork of `ps` per poll. This runs on the failure path and once
 * per 100 ms of the grace window, which is not a hot loop by any measure.
 */
function statCodeFor(pid = 0) {
    if (pid <= 0) {
        return '';
    }
    try {
        const raw = execFileSync('ps', ['-A', '-o', 'pid=,stat='], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 });
        for (const line of raw.split('\n')) {
            const trimmed = line.trim();
            if (trimmed === '') {
                continue;
            }
            const parts = trimmed.split(/\s+/);
            if (Number(parts[0]) === pid) {
                return parts[1] ?? '';
            }
        }
        return '';
    } catch {
        return '';
    }
}

/**
 * Can this process still EXECUTE? `false` once it is gone OR once it is a
 * zombie — the two states in which it provably cannot write anything.
 *
 * A zombie is `Z` (or the rarer `X`/`x` variants some kernels use while a
 * corpse is being torn down). A running process is anything else: `R`, `S`,
 * `D`, `I`, `T` — all of which keep executing and all of which are treated as
 * alive, so the check can only ever fail toward "still running", never toward
 * "gone". That direction is deliberate: a wrongly-alive reading costs one red
 * run, a wrongly-dead one would hide a real leak.
 */
function isExecuting(pid = 0) {
    if (pid <= 0) {
        return false;
    }
    try {
        process.kill(pid, 0);
    } catch (error) {
        // Narrowed structurally (this directory forbids TS annotations): ESRCH is
        // "no such process", which is the answer we came for.
        if (error && typeof error === 'object' && 'code' in error && error.code === 'ESRCH') {
            return false;
        }
        throw error;
    }
    const stat = statCodeFor(pid);
    if (stat === '') {
        // The pid exists but `ps` gave no answer (it failed, or the process died
        // between the two calls). Treated as STILL RUNNING, because the failure
        // mode being guarded against here is a false "gone" that would hide a
        // real leak; the next poll, or the grace window's expiry, resolves it.
        return true;
    }
    return !/^[ZX]/.test(stat);
}

/** Poll until the pid can no longer execute, or the grace period runs out. */
async function waitUntilGone(pid = 0) {
    const deadline = Date.now() + DEATH_GRACE_MS;
    while (Date.now() < deadline) {
        if (!isExecuting(pid)) {
            return true;
        }
        await new Promise((resolve) => {
            setTimeout(resolve, 100);
        });
    }
    return !isExecuting(pid);
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

        // 3. And the PROCESS CAN NO LONGER EXECUTE — the whole group, not just
        //    the launcher. This is the assertion that distinguishes the real fix
        //    from the obvious one: MEASURED, `execFileSync`'s `timeout` +
        //    `killSignal` killed `npx` and left BOTH the Playwright runner and
        //    its worker running. So "the launcher is gone" is not a sufficient
        //    check, and this one reads the pid of the WORKER the fixture
        //    reported.
        //
        //    ## The failure message reports MEASUREMENTS, not a theory
        //
        //    It previously ended with "the kill reached only the process it
        //    spawned and not the process group", which is a CAUSAL CLAIM about
        //    a run that had not yet happened — and it was wrong. That claim
        //    described the CI failure that turned out NOT to have happened: the
        //    group kill was delivered every time (`killedWith: SIGKILL`, no
        //    `killNote`, leader dead). Had the message been trusted, the search
        //    would have gone to group membership — which was never broken — and
        //    away from the actual cause, a zombie in a container with no reaper.
        //
        //    What replaces it is the set of facts that actually discriminate:
        //    which branch of the driver's internal race won, whether the group
        //    signal was delivered at all, how many descendant SIGKILLs went
        //    out, and — from `treeAtKill` and a live `ps` — what the worker's
        //    STAT and pgid actually were. A message that states those cannot
        //    mislead, because it reports rather than concludes.
        const killedTree = result.treeAtKill;
        expect(
            await waitUntilGone(record.pid),
            `the hung child process ${record.pid} is STILL EXECUTING ${DEATH_GRACE_MS}ms after the driver's ` +
                `${KILL_AFTER_MS}ms timeout fired. This is the exact failure the child timeout exists to ` +
                'prevent: the parent test reports a failure and the child keeps writing rows into the shared ' +
                'dev database. A `Z` in the STAT column below would mean zombie — already dead, waiting for ' +
                'a parent that will never reap it — and would NOT be a failure, which is why this check ' +
                'accepts it; a state other than Z means the process is genuinely still running.\n\n' +
                '--- WHAT WAS MEASURED (not inferred) ---\n' +
                `  which branch won inside runChild: ${result.finishedOnItsOwn ? "the child's 'close' event fired (the leader was reaped)" : 'the bounded wait expired first'}\n` +
                `  group SIGKILL delivered:        ${result.groupKillDelivered ? 'yes' : 'NO — the descendant sweep was the only mechanism that ran'}\n` +
                `  descendant SIGKILLs sent:       ${result.sweptPids} (across both sweep passes)\n` +
                `  killNote:                       ${result.killNote === '' ? 'none — no signal reported an error' : result.killNote.trim()}\n` +
                `  signal that ended the leader:   ${result.killedWith === '' ? 'none (it exited on its own)' : result.killedWith}\n` +
                `  worker's current STAT:         ${statCodeFor(record.pid) === '' ? '<no such process>' : statCodeFor(record.pid)}\n\n` +
                '--- PROCESS TREE AT KILL TIME (pgid is the column that settles group membership) ---\n' +
                `${killedTree}\n\n` +
                '--- PROCESS TREE NOW (after the sweep and after the grace period) ---\n' +
                `${describeProcessTree(record.pid)}\n` +
                `Child output:\n${output}`,
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
