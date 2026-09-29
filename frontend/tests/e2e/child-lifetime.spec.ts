import fs, { readFileSync } from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { describeProcessTree, isZombieState, parseProcStat, runChild, statCodeFor } from './ownership-probe/run-child';

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
 * The reason is the CI *environment*, not the driver, and only the second half
 * of that sentence is a measurement. What is measured: the worker survived in
 * state `Z` inside the E2E container, three times. What is NOT measured there:
 * *which* process was PID 1 — an earlier version of this comment asserted
 * `tail -f /dev/null` as though it had been read off the runner, and it had not;
 * `deployment/Dockerfile.e2e` sets no `ENTRYPOINT`/`CMD` at all, so the identity
 * of PID 1 in that job was never established.
 *
 * The mechanism needs no particular reaper to be *true*, only a reaper to be
 * *absent*, and the absence is what the `Z` reading shows directly: a SIGKILLed
 * process is not removed from the process table by the kill — only its PARENT
 * removes it, by reaping. With no reaper, the killed worker stays as
 * `<defunct>` forever, and `kill(pid, 0)` keeps SUCCEEDING, because the pid
 * genuinely still exists. On darwin PID 1 is `launchd`, which reaps, so the
 * entry disappears at once — hence green locally, red on the container.
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
 *
 * ## The same environment also has no `ps`
 *
 * The `Z` row above could only be read because the dump names it. In the very
 * environment that produces the zombie, the dump tool is not there: CI run
 * 36532030136 failed with `ps failed while describing pid 1567: spawnSync ps
 * ENOENT`. So the classification reads `/proc/<pid>/stat` directly now, and this
 * file holds no process-inspection logic of its own beyond the `kill(pid, 0)`
 * existence probe — which is a syscall and cannot be missing.
 */

/** How long to wait for the SIGKILL to be observed. Generous on purpose. */
const DEATH_GRACE_MS = 5000;

/**
 * Can this process still EXECUTE? `false` once it is gone OR once it is a
 * zombie — the two states in which it provably cannot write anything.
 *
 * Three states have to be told apart, and they are not the same two the
 * original comment listed:
 *
 * 1. **`ESRCH` from `kill(pid, 0)`** — the pid does not exist. Done.
 * 2. **A zombie state** — it exists and cannot run. Also done, and done for the
 *    strongest possible reason: a zombie has no address space to write from.
 * 3. **`''` from `statCodeFor`** — the pid passed check 1 but the state could
 *    not be read. This is the one that used to be missing, and it is the
 *    dangerous one: it means the mechanism is blind, not that the process is
 *    gone. It is treated as STILL RUNNING, because the failure mode being
 *    guarded against here is a false "gone" that would hide a real leak.
 *
 * That third case is not hypothetical, and it is what CI ran 36532030136 hit
 * three times: the E2E image has no `ps` binary, so every state read returned
 * `''` and the check correctly refused to call a process dead. Fail-closed
 * turned a broken tool into a red run instead of a green lie — the right
 * direction, but a red run all the same. The fix is a mechanism that answers
 * without a binary (`/proc`, see `run-child.ts`), not a softer predicate.
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
    const state = statCodeFor(pid);
    if (state === '') {
        return true;
    }
    return !isZombieState(state);
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
        //    out, and — from `treeAtKill` and a live state read — what the
        //    worker's STAT and pgid actually were. A message that states those
        //    cannot mislead, because it reports rather than concludes.
        //
        //    L2: this message is built EAGERLY, and it used to be built on the
        //    green path too, while the docblock above the old `statCodeFor`
        //    claimed the work happened "on the failure path". Playwright takes
        //    the message as a plain string, so there is no lazy form to switch
        //    to — the honest fix is to stop claiming a path it does not take.
        //    It is now assembled once, into a named constant, from measurements
        //    taken once: `workerStat` below is read a single time instead of
        //    twice, which also removes the window in which the two reads could
        //    disagree and print a state the test never saw.
        const workerStat = statCodeFor(record.pid);
        const killedTree = result.treeAtKill;
        const diagnosis =
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
            // An empty state is NOT rendered as "no such process". That is the
            // conflation that made CI run 36532030136 hard to read: the pid was
            // there, and the READER was missing. The two are now distinct
            // strings, and the second one says which mechanism failed.
            `  worker's current STAT:         ${workerStat === '' ? '<unreadable — the mechanism failed, this is NOT evidence the process is gone>' : workerStat}\n\n` +
            '--- PROCESS TREE AT KILL TIME (pgid is the column that settles group membership) ---\n' +
            `${killedTree}\n\n` +
            '--- PROCESS TREE NOW (after the sweep and after the grace period) ---\n' +
            `${describeProcessTree(record.pid)}\n` +
            `Child output:\n${output}`;
        expect(await waitUntilGone(record.pid), diagnosis).toBe(true);

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

/**
 * ## The load-bearing line, tested on its own
 *
 * Everything above rests on one rule — a `Z` process is not executing — and that
 * rule used to be an inline regex inside a module-private function, in a
 * `tests/e2e` directory that no unit test runner collects. Two consequences, both
 * measured rather than assumed:
 *
 * - On darwin the state alphabet this test actually observes is `? R S U`: no
 *   `Z`, no `X`, because `launchd` reaps. So the branch the whole fix rests on
 *   was **never taken locally** — the local green run did not exercise it.
 * - Deleting the pattern would therefore have left every suite green. There was
 *   no test that could see the change.
 *
 * The rule and the parser behind it are now exported from `run-child.ts` and
 * asserted here, from real kernel output rather than from a hand-written
 * fixture: a real `/proc/<pid>/stat` line is read and its fields checked against
 * the rule, so a broken `parseProcStat` — a shifted field, a missing paren anchor
 * — turns this red rather than quietly reclassifying every corpse as alive.
 *
 * ## Why this is TWO tests and not one with a skip in the middle
 *
 * The kernel-alignment assertion needs `/proc`, which does not exist on darwin.
 * It could have been one test with a `test.skip()` before it — and that is the
 * version that was written first and then measured: a mid-test `test.skip()`
 * aborts the test, so the assertions AFTER it never run. The result on a
 * developer machine was "skipped", and the skip was silent about which of the
 * four remaining checks had been skipped with it. So the split is structural:
 * the platform-independent half is its own test and runs everywhere, and the
 * linux-only half is a second test whose skip is visible as its own line in the
 * report.
 *
 * What that leaves uncovered, stated rather than papered over: the parser's field
 * ALIGNMENT is verified against the kernel only on the platform that produces the
 * zombies — which is also the only platform where a wrong alignment would matter.
 * A hand-written fixture would have covered darwin too and would also have proved
 * nothing about the kernel's actual format, which is the thing that has to be
 * right.
 */
test.describe('the process-state classification is a pure, tested function', { tag: ['@regression', '@feature:e2e-hygiene'] }, () => {
    // One writer of the hand-off file is the reason for the project skip in the
    // other describe; the same reasoning applies here — the state read is of
    // `process.pid`, and running the same assertions in two browser projects
    // proves nothing extra about a pure function.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('a live process is alive, a zombie is not, and an unreadable state is NOT "gone"', async () => {
        // ── 1. This process, right now, is by definition executing ──
        // Not a fixture and not a mock: the reader is a live process on a live
        // kernel, which is the only way the assertion can be wrong in the
        // direction that matters (reading a running process as dead).
        const ownState = statCodeFor(process.pid);
        expect(ownState, 'the test runner must be able to read its own state').not.toBe('');
        expect(isZombieState(ownState), `this very process reports "${ownState}", which must not be a corpse`).toBe(false);

        // ── 2. The rule, over the kernel's own alphabet ──
        // `proc(5)` lists these; `Z` is the one this suite exists for. `x` is
        // included because it is listed too, and a rule that ignored it would
        // read a dead process as alive.
        for (const alive of ['R', 'S', 'D', 'I', 'T', 't', 'W', 'K', 'P']) {
            expect(isZombieState(alive), `${alive} is a live state and must not be read as a corpse`).toBe(false);
        }
        for (const dead of ['Z', 'X', 'x']) {
            expect(isZombieState(dead), `${dead} is a corpse and must be read as unable to execute`).toBe(true);
        }
        // The empty string is the fail-CLOSED case, and it belongs to the CALLER
        // (`isExecuting`), not here — `isZombieState('')` is `false` by
        // construction, and `isExecuting` turns that into "assume alive". Asserted
        // as its own line so the two halves cannot be confused later.
        expect(isZombieState(''), 'an unreadable state is not a corpse state').toBe(false);

        // ── 3. The parser, against the two shapes that break a naive one ──
        // The executable name is wrapped in parentheses and may contain both
        // spaces and parentheses of its own. Every later field is positional, so
        // a parser that split on whitespace would report a name-derived number as
        // the state — and `isZombieState` would happily classify the wreckage.
        // MEASURED that this is a real shape: the reproduction container's victim
        // reports `comm` = `MainThread`, and a Chrome helper on a dev machine is
        // `(chrome_crashpad_handler)`.
        const tricky = parseProcStat('4242 (Web Content (gpu)) Z 7 4242 4242 0 -1 4194304');
        // Structurally narrowed, not asserted-and-hoped: this directory forbids TS
        // annotations, so the guard is a runtime `throw` on the condition the
        // `expect` would report. `throw` narrows the type for every line after it
        // and names the failure in node's own format; an `expect` alone would
        // neither narrow nor survive the access.
        if (tricky === null) {
            throw new Error('a well-formed /proc line with a parenthesised name must parse');
        }
        expect(tricky.comm).toBe('Web Content (gpu)');
        expect(tricky.state).toBe('Z');
        expect(tricky.ppid).toBe(7);
        expect(tricky.pgrp).toBe(4242);
        expect(isZombieState(tricky.state), 'a name full of spaces must not shift the state field').toBe(true);

        // ── 4. Garbage in, null out — never a half-parsed row ──
        // A partial parse is the dangerous shape: it yields a row with pid 0 or
        // state `''` that looks like data. `null` is the only safe answer, and it
        // is what makes "no such process" distinguishable from "unreadable".
        // The last two cases are the near-misses that matter: a line cut off after
        // the name has no state to report, and a line whose name has no closing
        // paren has no field positions at all.
        for (const junk of ['', '   ', 'nonsense', '1234 (sh', '1234 (sh) Z']) {
            expect(parseProcStat(junk), `"${junk}" must not parse into a row`).toBeNull();
        }
    });

    test('the parser agrees with the kernel on a REAL /proc/<pid>/stat line', async () => {
        // linux-only, and only because the FILESYSTEM is: every assertion here is
        // about the kernel's own field layout, which is the thing `parseProcStat`
        // exists to get right. A hand-written string elsewhere in this file would
        // only prove the parser agrees with a string this repo also wrote.
        test.skip(process.platform !== 'linux', '/proc exists on linux only; the platform-independent checks are in the previous test.');
        const raw = readFileSync(`/proc/${process.pid}/stat`, 'utf8');
        const row = parseProcStat(raw);
        if (row === null) {
            throw new Error(`/proc/${process.pid}/stat did not parse: ${JSON.stringify(raw)}`);
        }
        expect(row.pid).toBe(process.pid);
        // Cross-checked against the OTHER reader: `statCodeFor` parses this file
        // through the same function but reads it by pid, so agreement here means
        // the address-by-pid and the parse-by-content paths see the same state.
        expect(row.state).toBe(statCodeFor(process.pid));
        // ppid and pgrp are the fields the descendant walk and the pass-2
        // condition depend on; a one-field shift would send the sweep into the
        // wrong subtree while every state assertion above still passed.
        expect(row.ppid).toBe(process.ppid);
        expect(row.pgrp).toBeGreaterThan(0);
    });
});
