import { execFileSync, spawn } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { once } from 'node:events';

/**
 * Running a child Playwright with a lifetime you can actually bound.
 *
 * ## The measurement that produced this module
 *
 * `execFileSync('npx', [...], { timeout, killSignal: 'SIGKILL' })` does NOT end
 * the thing you asked for. `npx` is a launcher: it spawns
 * `@playwright/test/cli.js`, which spawns a worker process, which runs the test.
 * MEASURED with a child that blocks forever, on this machine, with
 * `timeout: 6000` and `killSignal: 'SIGKILL'`: BOTH grandchildren survived. Node's
 * `execFileSync` timeout signals the process it spawned and nothing else — it has
 * no process group and no child sweep. So a timeout added to `execFileSync`
 * would, on its own, still be the original bug: the parent reported a failure
 * while the child kept running and kept writing rows into the shared dev
 * database.
 *
 * ## What actually ends it: the group FIRST, a descendant SWEEP regardless
 *
 * A PROCESS GROUP is signalled first. The child is spawned `detached: true`,
 * which makes it a group leader (its pgid equals its pid), and the group is
 * signalled as a whole with `process.kill(-pgid, 'SIGKILL')`. Every descendant
 * inherits the group, so in principle the launcher, the runner and the worker
 * all get the signal in one call and none of them can decline it.
 *
 * **"In principle" was doing a lot of work in that sentence.** The group signal
 * itself is MEASURED working: the leader died of SIGKILL and no `killNote` was
 * produced, on Linux CI, on every attempt. Playwright's runner spawns its
 * workers non-detached so they stay in the group (see the comment at
 * `playwright/lib/runner/index.js:1916`), and the measurement in
 * `child-lifetime.spec.ts` found the worker's pgid equal to the group that was
 * killed — so group membership was **never** the defect, and this file used to
 * argue the opposite. It has been corrected: the group is the primary mechanism
 * and it works.
 *
 * ## So what the sweep is for — stated from the facts, not from the old story
 *
 * What a group signal cannot do is make a killed process LEAVE THE PROCESS
 * TABLE. The kill delivers; removal is the parent's job (`wait()`), and a
 * process whose parent never reaps stays as `Z`. The sweep therefore does not
 * rescue a process the group missed — measured, none was missed — it does the
 * one thing the group cannot: it addresses pids **by identity**, in dependency
 * order, so a descendant that somehow did not inherit the group still dies. It
 * is coverage with a measured cost of zero in the normal case, not a rescue
 * operation.
 *
 * Removal has a second owner, and this is where the file's story has to stay
 * honest. A SIGKILLed process's children are REPARENTED, and a reparented
 * corpse can only be removed by whatever holds PID 1. In the E2E job that is a
 * real reaper — Nutzerentscheid D26, `--init` on the job container in
 * `ci.yml`, proven behaviourally in `child-lifetime.spec.ts` — and on a
 * developer machine it is `launchd`. Where no reaper exists, the pid lingers as
 * an unreapable `Z`, and re-signalling it is the only assertion left that it
 * stays unexecutable. That is why the sweep stays, and it is a different reason
 * than "the group missed somebody".
 *
 * Therefore: after the group signal, a **descendant sweep** follows. It is a
 * plain recursive walk of the PPID map from the leader, SIGKILLing every pid it
 * finds, then repeating the walk once more in case a process was reparented or
 * spawned into the gap. PPID descent is the only handle available that does not
 * depend on group membership — and that is its whole justification, on its own
 * terms. It is NOT the claim this file used to make. The claim was that PPID
 * descent is "the property that the group cannot be relied on to have finished",
 * which is backwards twice over: group membership was measured intact (above),
 * and what the group signal cannot finish is not membership but REAPING. Reaping
 * is addressed at PID 1, not by a walk.
 *
 * `SIGKILL` rather than `SIGTERM` is deliberate: `SIGTERM` is a REQUEST, and
 * the failure this module exists to prevent is a process too wedged to answer
 * one — the `hang.probe.ts` case blocks in `Atomics.wait`, which no handler
 * interrupts.
 *
 * ## Why the sweep runs BEFORE the group signal is reported, and re-runs
 *
 * Two properties make the sweep reliable rather than decorative, and both
 * orderings matter:
 *
 * - A `SIGKILL`ed parent is reaped by init and its children are REPARENTED to
 *   init, so `--ppid <leader>` finds nothing once the leader is dead. The
 *   sweep therefore takes its own snapshot of the tree while the leader is
 *   still alive, and signals the pids it recorded — a pid is stable, a PPID is
 *   not.
 * - A signal is asynchronous. A pid that is alive when the snapshot is taken
 *   may not be reaped when the wait completes, so the sweep runs again after
 *   the close/delay race, by which point `once(child, 'close')` has resolved
 *   and the leader is definitely gone.
 *
 * ## Why the kill is a fallback and the child's own exit is the primary
 *
 * The child's own exit is waited for first, because it is the honest signal: if
 * the child exits on its own there is nothing to kill, and reporting a kill then
 * would be a lie. Only if the deadline passes does the group signal go out — and
 * the result says WHICH of the two happened, because "the child was killed" and
 * "the child failed" are different diagnoses and the driver's assertions depend
 * on telling them apart (`killedForTimeout`).
 *
 * ## How this file inspects processes, in one line
 *
 * No external binary on linux: `/proc/<pid>/stat` is read directly, field 3 is
 * the state and field 4 the parent, so the classification, the tree dump and the
 * sweep all come from the same filesystem and spawn nothing. darwin keeps `ps`.
 * The reason is measured and unpleasant — the CI image has no `ps` — and it is
 * written out at `USE_PROC` below, where the branch is taken.
 *
 * ## Which of these helpers are also the TEST's, and why that is not circular
 *
 * `isExecuting` used to live in `child-lifetime.spec.ts`, module-private. A
 * predicate that decides "this process cannot execute any more" was therefore
 * reachable only by running a whole Playwright runner that spawns a whole
 * Playwright runner — the test file that was supposed to be unit-testable logic
 * had no seam, and on darwin the `Z` branch it exists for is never taken
 * (measured alphabet there: `? R S U`). It lives HERE now, exported, and
 * `run-child.test.ts` (Vitest) drives it from both sides. The spec imports the
 * predicate instead of owning it, which is what removes the duplicate: there is
 * exactly one implementation of the rule, and the E2E spec is one consumer of it
 * among two. The unit test is the PRIMARY here and the E2E spec the second
 * consumer, not the other way round — a rule whose only test needs a container
 * is a rule with no test on a developer machine.
 *
 * ## Why this file has no type annotations
 *
 * `tests/e2e/**` is linted with the PLAIN-JS parser (`eslint.config.js`:
 * `ecmaVersion: 2020`, no TS) while `tsc -b` type-checks it. Every shape is
 * chosen to satisfy both: parameters carry DEFAULT VALUES (which is ordinary
 * ES2020 and gives `tsc` the parameter's type by inference), and results are
 * built from a SEEDED literal rather than annotated — see `helpers/ownership.ts`
 * for the measured table of which shapes each parser accepts.
 */

/**
 * The argument list, seeded with one string. `args = []` would infer `never[]`,
 * and every caller's `['playwright', 'test', …]` is then a TS2322. A one-string
 * seed infers `string[]`, which is what the values always are. The seed is never
 * executed; it exists only to type the parameter.
 */
const ARGS = ['node'];

/** A no-op default for a parameter that is sometimes a real function. */
function noop() {
    return undefined;
}

/**
 * A one-element seed, for the same reason `ARGS` exists one screen up: a bare
 * `[]` infers `never[]` under `strict`, and `pids.push(someNumber)` is then a
 * TS2344 rather than the obvious thing it is.
 *
 * The value `0` is never signalled — `sweepDescendants` skips anything `<= 0`,
 * which matters beyond style: `process.kill(0, sig)` signals **every process in
 * the caller's own group**, so an unfiltered seed would be the single most
 * destructive line in this file.
 */
const PID_SEED = [0];

/**
 * A one-element string seed, same reason as `ARGS`/`PID_SEED`: a bare `[]` is
 * `never[]` under `strict`, so `for (const name of names)` would be a TS error
 * the moment anything is pushed onto it.
 */
const NAME_SEED = [''];

/**
 * The empty parent table, used where "could not look" has already been reported
 * and only the walk is left to do. A named constant rather than a fresh
 * `new Map()` at each call site because it is shared: a default parameter that
 * builds a new map per call is fine, but a module-level one makes it obvious at
 * the call site that this value is never written to.
 */
const NO_TABLE = new Map();

/**
 * The empty process row, used where "there is no row" is a real answer — the
 * pass-2 identity check gets one whenever the leader is already gone, which is
 * the MEASURED normal case, not an edge case.
 *
 * A named, SEEDED constant rather than a `null` default, and the reason is
 * mechanical: this directory has no TS annotations (file header), so a
 * parameter typed by a `null` default infers as `null` and rejects every real
 * row the caller passes. `pid: 0` is what makes it safe as a value — it can
 * never match a pid, so "no row" is always a rejection.
 */
const NO_ROW = { pid: 0, ppid: 0, pgrp: 0, state: '', comm: '' };

/**
 * ## The one place that decides HOW a process is looked at
 *
 * MEASURED, CI run 36532030136 (2026-09-29): the E2E container has **no `ps`
 * binary at all**. `execFileSync('ps', …)` failed with `spawnSync ps ENOENT`,
 * on three consecutive attempts, and the fail-closed handling turned that into
 * three red runs. `ENOENT` from `spawnSync` is the loader failing to find the
 * EXECUTABLE — a binary that exists and rejects its arguments produces a
 * non-zero exit, not `ENOENT` — so `ps` is not on the runner's `PATH`. That is
 * consistent with the image rather than mysterious: `deployment/Dockerfile.e2e`
 * installs an explicit apt list (`git curl xz-utils unzip zip`) that does not
 * contain `procps`, and defines no `ENTRYPOINT`/`CMD` of its own — it inherits
 * `CMD ["/usr/local/bin/entrypoint.sh", "serve"]` from the base image
 * (`deployment/Dockerfile:254`), so `ps` would exist only if the base snapshot
 * happens to carry it. It does not, as the `ENOENT` above measures.
 *
 * The same inheritance is why "the image" is not the whole answer to "what is
 * PID 1": the E2E job is a GitHub Actions **job container**, and what the runner
 * puts at PID 1 is a property of the RUNNER's invocation, not of the image.
 * Measured for the reaping half of the same problem in
 * `child-lifetime.spec.ts`; the flag that decides it is `ci.yml`'s
 * `container.options: --init`, and that test fails if it is ever absent.
 *
 * The consequence is larger than "one call site". Both former `ps` paths
 * degraded SILENTLY into the same empty answer, and that answer meant opposite
 * things depending on who asked: the classification read it as "I know nothing,
 * so assume still running" (correct, fail-closed), and the sweep read it as
 * "there is nothing to sweep" (silently vacuous). A mechanism with no output
 * path is not a fallback, it is a coin toss.
 *
 * So: **linux reads `/proc`**, which is a filesystem every container mounts and
 * which needs no binary, no PATH and no fork. **darwin keeps `ps`**, which is
 * measured present on every developer machine. The switch is `process.platform`
 * — a single, declared branch — and there is deliberately NO `ps` fallback
 * behind the `/proc` path: a chain of fallbacks is precisely what made the
 * failure invisible, and a mechanism that answers nothing must be able to say
 * so instead of borrowing a weaker answer from the next implementation.
 */
const USE_PROC = process.platform === 'linux';

/** Where the process table lives on linux. Always mounted in a container. */
const PROC_ROOT = '/proc';

/** `ps` output is small, but a runaway host can have a lot of processes. */
const PS_MAX_BUFFER = 8 * 1024 * 1024;

/**
 * Parse one line of `/proc/<pid>/stat`.
 *
 * ## Why the parentheses are the hard part
 *
 * Field 2 is the executable name in parentheses, and the kernel escapes nothing
 * inside them. A process is regularly called `(Web Content)`, `(a b)`, or even
 * `(sh (weird))`. Everything after that name is positional, so the parse anchors
 * on the LAST `)` in the line and takes the span between the first `(` and that
 * anchor as the name. The obvious implementation — `split(/\s+/)` over the whole
 * line — silently shifts `state`, `ppid` and `pgrp` by one for every process
 * whose name contains a space, which is a class of bug that would surface only as
 * a wrong parent in a walk, never as an error.
 *
 * Field numbering follows `proc(5)`: 1 pid, 2 comm, 3 state, 4 ppid, 5 pgrp.
 *
 * `null` for anything unparseable, so the caller can distinguish "no row" from
 * "a row whose numbers happen to be zero".
 */
export function parseProcStat(raw = '') {
    const text = raw.trim();
    const open = text.indexOf('(');
    const close = text.lastIndexOf(')');
    if (open < 0 || close < open) {
        return null;
    }
    const fields = text.slice(close + 1).trim().split(/\s+/);
    if (fields.length < 3) {
        return null;
    }
    const pid = Number(text.slice(0, open).trim());
    const ppid = Number(fields[1]);
    const pgrp = Number(fields[2]);
    return {
        pid: Number.isInteger(pid) ? pid : 0,
        comm: text.slice(open + 1, close),
        state: fields[0],
        ppid: Number.isInteger(ppid) ? ppid : 0,
        pgrp: Number.isInteger(pgrp) ? pgrp : 0,
    };
}

/**
 * Does this state mean "this process provably cannot execute any more"?
 *
 * `Z` is the corpse: the process is gone, only its exit status is left, and its
 * parent has not reaped it. `X` and `x` are the "dead" states `proc(5)` also
 * lists; `x` has been reported as `Z` since Linux 2.6.33, so in practice `Z` is
 * what a modern kernel shows and the other two are accepted so a kernel that
 * does report them is not read as a live process. Every other state — `R`, `S`,
 * `D`, `I`, `T`, `t`, `W`, `K`, `P` — keeps executing or is at least able to,
 * and is therefore treated as alive.
 *
 * The direction is the whole point: a wrongly-alive reading costs one red run,
 * a wrongly-dead one would hide a real leak. Nothing here decides that; the
 * caller decides what an unreadable state means.
 *
 * ## Why this is exported
 *
 * It is a pure function of one short string and it is one half of the single line
 * the whole "the child is dead" claim rests on (the other half is
 * `isExecuting`, below). Before this it was an inline regex in a `tests/e2e`
 * spec, module-private and therefore untestable: on darwin the measured state
 * alphabet is `? R S U` — no `Z`, no `X` — so the branch was never even taken
 * locally, and a later edit that deleted the pattern would have left every suite
 * green. It is exported, its table is asserted in `child-lifetime.spec.ts`, and
 * both halves are unit-tested in `run-child.test.ts`.
 */
export function isZombieState(state = '') {
    return /^[ZXx]/.test(state);
}

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
 * That third case is not hypothetical, and it is what CI run 36532030136 hit
 * three times: the E2E image has no `ps` binary, so every state read returned
 * `''` and the check correctly refused to call a process dead. Fail-closed
 * turned a broken tool into a red run instead of a green lie — the right
 * direction, but a red run all the same. The fix is a mechanism that answers
 * without a binary (`/proc`, see `USE_PROC` above), not a softer predicate.
 *
 * ## Why the STATE READ is a parameter
 *
 * `readState` defaults to `statCodeFor`, so every production caller writes
 * `isExecuting(pid)` and nothing changes. What the parameter buys is branch 3:
 * with a real `statCodeFor` that branch is nearly unreachable — a pid that
 * `kill(pid, 0)` accepts almost always has a readable `/proc/<pid>/stat` — so a
 * unit test could not drive the one branch that decides a fail-CLOSED reading.
 * Injecting the reader is how `run-child.test.ts` asserts that `''` means
 * "assume still running", and `sweepDescendants` below takes its `kill` the
 * same way. Both seams exist to make the *dangerous* branch reachable without
 * breaking a process; neither is a hook the production path uses.
 */
export function isExecuting(pid = 0, readState = statCodeFor) {
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
    const state = readState(pid);
    if (state === '') {
        return true;
    }
    return !isZombieState(state);
}

/** Every entry in `/proc`, or nothing at all if it cannot be listed. */
function procEntries() {
    try {
        return readdirSync(PROC_ROOT);
    } catch {
        return NAME_SEED.slice();
    }
}

/**
 * The raw `/proc/<pid>/stat` text, or `''` when there is no such entry.
 *
 * Takes a NUMBER because every pid-shaped caller has one; the one caller that
 * does not (`procfsMounted`) needs a name rather than a number and is therefore
 * a separate two-line read rather than a union type or a `'self'` sentinel
 * threaded through here. Sentinel-and-union would be the shorter version and
 * the one where a future caller can pass a name by accident.
 */
function procStatText(pid = 0) {
    try {
        return readFileSync(`${PROC_ROOT}/${pid}/stat`, 'utf8');
    } catch {
        return '';
    }
}

/**
 * Is `procfs` actually mounted?
 *
 * This is the question that keeps a broken `/proc` from being read as "the
 * process is gone". Every per-pid read fails identically when the pid does not
 * exist and when the filesystem is absent, and the two answers must not collapse
 * into one: the first is the answer we came for, the second is a blindfold, and
 * a blindfold that reads as "gone" is the fail-OPEN direction.
 *
 * `/proc/self` is the probe because it is the one entry that is guaranteed to
 * exist while procfs is mounted — it is the reader's own process, so no race can
 * retire it between the check and the use.
 */
function procfsMounted() {
    try {
        // `/proc/self` is the one entry that cannot be retired between the check
        // and the use: it is the reader's own process.
        readFileSync(`${PROC_ROOT}/self/stat`, 'utf8');
        return true;
    } catch {
        return false;
    }
}

/**
 * Every process on the machine, or `null` when this platform cannot be inspected.
 *
 * The `null` is load-bearing and is the reason this function is separate from
 * `readParentTable`. "I could not look" and "I looked and there was nothing"
 * are different facts, they call for different actions, and collapsing them is
 * how a broken mechanism reports success. Callers that can act sensibly on
 * "nothing found" (the sweep: an empty map means the group signal carries the
 * job) take the map. Callers whose output is read as a DIAGNOSIS
 * (`describeProcessTree`) take this and say so in words.
 */
function readProcessTable() {
    if (!USE_PROC) {
        return readProcessTableViaPs();
    }
    if (!procfsMounted()) {
        return null;
    }
    const rows = new Map();
    for (const entry of procEntries()) {
        // `/proc` holds far more than pids (`self`, `net`, `irq`, …); only a
        // purely numeric directory is a process, and `parseProcStat` would
        // return null for the rest anyway — but not before trying to read them.
        // The directory name is a STRING and the pid is not, so this is the one
        // conversion in the file; the regex is what makes it a safe one, since a
        // directory called `1x` would otherwise reach `Number` as `NaN` and
        // produce a `/proc/NaN/stat` read that fails for a reason unrelated to
        // what it looks like.
        if (!/^\d+$/.test(entry)) {
            continue;
        }
        const row = parseProcStat(procStatText(Number(entry)));
        if (row !== null && row.pid > 0) {
            rows.set(row.pid, row);
        }
    }
    return rows;
}

/**
 * The `ps`-based table, for darwin — where `/proc` does not exist.
 *
 * `launchd` on macOS is the reason this platform is a fallback rather than a
 * second-class citizen: it reaps, so a killed process leaves the process table
 * immediately, which is exactly the property the CI environment lacks (see
 * `child-lifetime.spec.ts`). And macOS ships `ps` in `/bin`, measured on every
 * developer machine — the property the CI image measurably does not have.
 *
 * The column list is the one `describeProcessTree` already used, and it is
 * accepted by BSD `ps` and by procps alike; only the darwin path reaches it.
 */
function readProcessTableViaPs() {
    const rows = new Map();
    let raw = '';
    try {
        raw = execFileSync('ps', ['-A', '-o', 'pid,ppid,pgid,stat,comm'], {
            encoding: 'utf8',
            maxBuffer: PS_MAX_BUFFER,
        });
    } catch {
        return null;
    }
    for (const line of psBody(raw)) {
        // `comm` is last and may contain spaces (an absolute path), so it takes
        // the remainder of the line rather than the next field.
        const parts = line.split(/\s+/);
        const pid = Number(parts[0]);
        const ppid = Number(parts[1]);
        const pgrp = Number(parts[2]);
        if (!Number.isInteger(pid)) {
            continue;
        }
        rows.set(pid, {
            pid,
            ppid: Number.isInteger(ppid) ? ppid : 0,
            pgrp: Number.isInteger(pgrp) ? pgrp : 0,
            state: parts[3] ?? '',
            comm: parts.slice(4).join(' '),
        });
    }
    return rows;
}

/**
 * The data rows of a `ps -A -o …` dump, with the header dropped.
 *
 * Whether the first row is a header is DETECTED, not assumed: both `ps`
 * flavours print one for `-o pid,ppid,…` (the `=`-less form always does), but
 * a leading data row mistaken for a header would silently drop PID 1 from the
 * dump — and PID 1 is exactly the row a reparenting question turns on. Cheap to
 * check, and a wrong guess here would be invisible.
 */
function psBody(raw = '') {
    const rows = raw
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
    if (rows.length > 0 && Number.isNaN(Number(rows[0].split(/\s+/)[0]))) {
        return rows.slice(1);
    }
    return rows;
}

/** One row, or `null` for "no such process" and for "cannot be inspected". */
function readProcessRow(pid = 0) {
    if (pid <= 0) {
        return null;
    }
    // The single-pid form on linux reads ONE file, not the whole table: this is
    // called on the failure path and from a 100 ms poll, and a full `/proc` walk
    // per poll would be a cost this module introduced to replace a cost it had.
    if (USE_PROC) {
        if (!procfsMounted()) {
            return null;
        }
        return parseProcStat(procStatText(pid));
    }
    const table = readProcessTable();
    return table === null ? null : (table.get(pid) ?? null);
}

/**
 * The single-letter state of `pid`, or `''` for "no answer of any kind".
 *
 * `''` is deliberately ambiguous and deliberately useless on its own: the
 * caller decides what it means. It is `isExecuting` — above, in this file —
 * that turns it into "assume it is still running", and its docblock is where
 * that reasoning lives. Duplicating the three-case story here is what made two
 * copies of one rule in two files.
 *
 * The deliberate non-goal is distinguishing "no such process" from "could not
 * look": doing it here would move the fail-closed decision out of the place
 * where its reason can be read next to it.
 */
export function statCodeFor(pid = 0) {
    const row = readProcessRow(pid);
    return row === null ? '' : row.state;
}

/**
 * `pid -> ppid` for every process on the machine, or an empty map if this
 * platform cannot be inspected.
 *
 * ## Why the whole table rather than a filter flag
 *
 * `--ppid` is a **procps** flag. BSD `ps` — macOS — does not have it: measured
 * here, `ps -o pid= --ppid 1234` answers `ps: illegal option -- -` and exits 1.
 * A sweep written against that flag would pass on CI and fail on every developer
 * machine, which is the worst possible split: the platform where the failure
 * would be visible is the one platform that never runs it. On linux the question
 * does not arise at all, because there the table is a directory listing.
 *
 * Takes the table as an argument rather than reading one itself, so the kill
 * path can derive the snapshot and the `treeAtKill` text from a SINGLE
 * observation of the machine. Two independent reads can disagree, and a dump
 * that contradicts the pids about to be signalled is worse than no dump.
 *
 * The result is never `null` — an empty map means "walk found nothing", which
 * for this caller is the same action either way (signal nothing). The callers
 * that DO have to tell "nothing" from "could not look" check
 * `readProcessTable()` themselves, which does distinguish them.
 *
 * ## The `= NO_TABLE` default is a LANDMINE, and the call sites are the fix
 *
 * Omitting the argument resolves to an empty map, which is indistinguishable
 * from "the walk found nothing" — so a call written as `readParentTable()` is a
 * **silently vacuous** walk, not a compile error and not a runtime error. That
 * is not hypothetical: it is exactly what pass 2 of the sweep did, and it
 * degraded to zero SIGKILLs while every suite stayed green. So:
 *
 * - every call site passes a table, and `run-child.test.ts` PINS that: the test
 *   reads this file's own source and fails on a single occurrence of
 *   `readParentTable()` with an empty argument list. A source pin is normally
 *   the weak kind of test; here it is the strong kind, because the alternative
 *   is a type error this directory cannot express (no annotations — see the
 *   file header — so the parameter must carry a default to be typed at all).
 * - `freshParentTable` below is the ONLY reader-plus-map, so the kill path has
 *   one shape to get wrong instead of three.
 */
export function readParentTable(table = NO_TABLE) {
    const parents = new Map();
    for (const [pid, row] of table) {
        parents.set(pid, row.ppid);
    }
    return parents;
}

/**
 * The parent table from a FRESH read of the machine, or an empty map if this
 * platform cannot be inspected.
 *
 * This is the one place that reads and maps in a single step, and it exists so
 * that no kill-path caller has to write the `null`-to-empty dance itself. The
 * `null` is `readProcessTable()`'s "could not look" answer, and it maps to the
 * same action as "found nothing" — signal nothing — which is safe in the
 * direction that matters: a blindfold here costs coverage, never a wrong kill.
 */
export function freshParentTable() {
    const table = readProcessTable();
    return readParentTable(table === null ? NO_TABLE : table);
}

/**
 * Every pid below `rootPid`, breadth-first, from a parent table.
 *
 * A pid is a stable identity; a PPID is not. Once the leader is reaped its
 * children are reparented away and this walk — repeated later — returns
 * nothing, which is why the caller snapshots the result while the leader is
 * still alive and re-signals the snapshot afterwards.
 *
 * The return value ALWAYS begins with `PID_SEED`'s `0`, which is never
 * signalled (`sweepDescendants` skips it). The seed is a typing device, and this
 * is where its cost is visible: a caller that walks an EMPTY table gets `[0]`
 * back rather than `[]`, and the difference between "found nothing" and "found
 * nothing because the table was empty" is invisible to a `length`-only check.
 * Exported so `run-child.test.ts` can assert the real shape against a synthetic
 * table — an empty table, a flat set, a three-level tree and a cycle.
 */
export function descendantPids(rootPid = 0, parents = new Map()) {
    const childrenByParent = new Map();
    for (const [pid, ppid] of parents) {
        const existing = childrenByParent.get(ppid);
        if (existing === undefined) {
            childrenByParent.set(ppid, [pid]);
        } else {
            existing.push(pid);
        }
    }

    const found = PID_SEED.slice();
    const queue = [rootPid];
    let cursor = 0;
    while (cursor < queue.length) {
        const current = queue[cursor];
        cursor += 1;
        const children = childrenByParent.get(current);
        if (children === undefined) {
            continue;
        }
        for (const child of children) {
            if (found.includes(child)) {
                continue;
            }
            found.push(child);
            queue.push(child);
        }
    }
    return found;
}

/**
 * Is the process now occupying `pid` the one this driver started?
 *
 * ## The check that was there, and why it is not enough
 *
 * The premise check used to be `leader !== null && leader.pgrp === child.pid` —
 * that is, "the pid is occupied AND it leads a process group of its own". The
 * first half is necessary and the second is cheap, but together they establish
 * only that SOME group leader holds this number. They do NOT establish that it
 * is OURS: a pid that the kernel recycled to a fresh, unrelated group leader
 * passes both conditions exactly as our own would. A recycled pid becoming a new
 * group leader is unlikely — `setsid`/`spawn detached` have to happen to be the
 * next user of that number — but "unlikely" is a probability, and the cost of
 * the mistake is SIGKILLing a stranger's process subtree, which is the one
 * outcome in this file that is worse than a red test.
 *
 * ## What this adds: a recorded IDENTITY, not an inferred one
 *
 * `before` is the leader's row captured at kill time, from the SAME table read
 * that produced the snapshot — no extra syscall, no extra race. The kernel
 * offers no true generation counter for a pid, so identity has to be
 * reconstructed from what does change when a number is recycled: the process's
 * `comm` and its `pgrp`. A reused pid whose new occupant differs in EITHER is
 * rejected.
 *
 * The honest limit, stated rather than smoothed over: `comm` is a name, not a
 * uid, and a stranger running the same program in the same group would pass. So
 * this narrows the window substantially and does not close it. What it does
 * guarantee is the direction of the remaining error: the walk is skipped, never
 * entered on a false positive, and the snapshot re-signal below still runs
 * regardless — so a wrong answer here costs coverage, not a foreign kill.
 *
 * Exported and unit-tested because it is a three-line predicate on the
 * file's most dangerous branch, which is exactly the shape of rule that gets
 * edited by someone in a hurry. `run-child.test.ts` drives it with a matching
 * row, a differing `comm`, a differing `pgrp`, a `null` before/after, and — the
 * case the old check got wrong — a recycled pid that IS a group leader.
 */
export function isSameProcess(pid = 0, now = NO_ROW, before = NO_ROW) {
    // `NO_ROW` rather than `null` as the default, and that is the file header's
    // rule paying off: a parameter with a `null` default is typed `null` by
    // inference, which rejects every real row. A seeded row types the parameter,
    // and it is a safe default by construction — `pid: 0` fails the first check,
    // so an omitted argument is always a rejection, never an acceptance.
    if (pid <= 0 || now.pid !== pid || before.pid !== pid) {
        return false;
    }
    if (now.pgrp !== before.pgrp || now.comm !== before.comm) {
        return false;
    }
    // A group leader is its own pgid. This is the cheap part of the old check and
    // it is kept, because a row whose pgrp is not its own pid means the kernel
    // has already reparented or regrouped it — never a state in which walking
    // from it means walking from OUR leader.
    return now.pgrp === pid;
}

/**
 * SIGKILL each pid, counting the ones that were actually reachable.
 *
 * An `ESRCH` is not a failure: it means the pid is gone, which is the desired
 * outcome — usually the work of the group signal that went out a moment
 * earlier. Any other errno is equally uninteresting here, because the point of
 * the sweep is best-effort coverage of a handle that is not group membership,
 * and the caller reports coverage through `sweptDescendants` rather than
 * through a throw.
 *
 * ## Why `kill` is a parameter
 *
 * The `pid <= 0` interlock below is the most destructive line in the file if it
 * ever breaks: `process.kill(0, 'SIGKILL')` signals **every process in the
 * caller's own group**, and the caller here is a test runner with a group. A
 * unit test that exercised the interlock against the real `process.kill` would
 * therefore SIGKILL its own test run on failure — a regression test that
 * destroys the evidence. Injecting the killer lets the test assert the exact
 * same thing ("0 and negatives never reach the syscall") with a recorder, and
 * the real path keeps the real `process.kill`.
 */
export function sweepDescendants(pids = PID_SEED, kill = killWithSigkill) {
    let signalled = 0;
    for (const pid of pids) {
        // The `0` guard is a safety interlock, not defensive style: pid 0 means
        // "every process in MY group" to `kill(2)`, and this driver is itself a
        // process with a group. See `PID_SEED`.
        if (pid <= 0) {
            continue;
        }
        try {
            kill(pid);
            signalled += 1;
        } catch {
            // Already gone, or gone between the snapshot and now.
        }
    }
    return signalled;
}

/**
 * The real killer, named so it can be a DEFAULT VALUE (the file header's
 * "parameters carry default values" rule — the alternative would be an
 * annotation, which is an ESLint parse error in this directory). Throws for an
 * unknown pid, which is what `sweepDescendants` counts as "nothing to do".
 */
function killWithSigkill(pid = 0) {
    process.kill(pid, 'SIGKILL');
}

/** The column header a reader needs; also the shape `ps -A -o …` prints. */
const TREE_HEADER = 'PID  PPID  PGID STAT COMMAND';

/**
 * One line per process in `pid`'s tree, in the exact format a diagnosis needs:
 * `pid, ppid, pgid, stat, comm`.
 *
 * Returned as text rather than as objects because the consumer is a human
 * reading a test failure, and because `comm` may legitimately contain spaces (a
 * Chrome helper is `(chrome_crashpad_handler)`; a full path contains spaces
 * too), which would make a positional re-parse a source of bugs in the one
 * place that exists to explain something.
 *
 * ## The two failure texts, and why they are not the same
 *
 * A dump that cannot be produced is NOT a dump that came back empty. The empty
 * answer is `pid N: no matching process — it is gone.`, and it is only true
 * when the table was actually read. When it could not be read, this says so in
 * words and names the reason, because the reader of a red CI run is about to
 * ask "is the process alive or is my tooling broken?" and the old code answered
 * that question with a shrug that looked like the first.
 */
export function describeProcessTree(pid = 0) {
    const table = readProcessTable();
    return table === null ? UNREADABLE_TREE(pid) : describeProcessTreeFrom(pid, table);
}

/**
 * The text for "the table could not be read" — a blindfold, stated as one.
 *
 * A separate function rather than a branch inside the renderer because the
 * renderer's parameter cannot be `null` in this directory: it takes no TS
 * annotation, so a `= null` default would type it as `null` and reject every
 * real table. Deciding it at the call site is also the more honest place for the
 * decision — the caller is the one that knows whether it already reported the
 * failure elsewhere, which is why `killNote` carries it too.
 *
 * The wording is deliberately uncomfortable. "It is gone" would be a lie, and a
 * comfortable phrase here is how a broken mechanism ends up reported as a
 * healthy system.
 */
function UNREADABLE_TREE(pid = 0) {
    return USE_PROC
        ? `pid ${pid}: /proc is not readable on this platform, so its state could NOT be determined — ` +
          'this line is a blindfold, not a measurement. (The failure this dump explains is still open.)'
        : `pid ${pid}: \`ps\` failed on this platform, so its state could NOT be determined — ` +
          'this line is a blindfold, not a measurement. (The failure this dump explains is still open.)';
}

/**
 * `describeProcessTree` against a table the caller has already read.
 *
 * Split out so the kill path can render the tree and take its snapshot from ONE
 * observation. Reading twice is not merely wasteful: between the two reads a
 * process can be reaped, and a dump that lists a pid the snapshot then omits —
 * or the reverse, which is worse — sends the reader of a failure report looking
 * for a mechanism bug where there was a race.
 *
 * An EMPTY table here renders as "it is gone", which is correct: this function
 * is only reached once the caller established the table was readable, and a
 * readable table with no matching pid means the pid is not in it.
 */
function describeProcessTreeFrom(pid = 0, table = NO_TABLE) {
    if (pid <= 0) {
        return 'no pid to describe.';
    }

    const body = [];
    for (const [rowPid, row] of table) {
        // Sorted so two runs of the same situation produce the same text, and
        // padded to fixed widths so the STATE column is a column — this output
        // is read by a person scanning for a `Z`, and a ragged one makes that
        // scan a comparison instead of a lookup.
        body.push(
            `${String(rowPid).padStart(6)} ${String(row.ppid).padStart(6)} ${String(row.pgrp).padStart(6)} ` +
                `${row.state.padEnd(4)} ${row.comm}`,
        );
    }
    body.sort();

    const parents = readParentTable(table);
    const wanted = new Set([pid, ...descendantPids(pid, parents)]);
    const shown = body.filter((line) => wanted.has(Number(line.trim().split(/\s+/)[0])));
    if (shown.length === 0) {
        return `pid ${pid}: no matching process — it is gone.\n${TREE_HEADER}`;
    }
    return `${TREE_HEADER}\n${shown.join('\n')}`;
}

/**
 * Spawn a command, wait for it, and guarantee it — and everything it started — is
 * gone.
 *
 * @param command executable to run
 * @param args its arguments
 * @param timeoutMs wall-clock ceiling before the GROUP is killed; 0 = no bound
 * @param env the child's environment, passed through verbatim
 * @param cwd working directory for the child
 */
export async function runChild(command = '', args = ARGS, timeoutMs = 0, env = process.env, cwd = '') {
    const started = Date.now();

    // `detached: true` is the load-bearing option: it puts the child in its OWN
    // process group, which is what makes a single `kill(-pid)` reach the
    // grandchildren. Without it they stay in the parent's group and survive.
    const child = spawn(command, args, {
        cwd: cwd === '' ? undefined : cwd,
        env,
        detached: true,
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    let output = '';
    // A canceller closure rather than a stored timer handle. `let x = null` is an
    // implicit `any` under this config, and a `null` seed types the variable as
    // `null` so a later timer assignment is a TS2322. A closure starts as `noop`
    // and is replaced wholesale.
    let cancelKill = noop;
    let killNote = '';
    // Filled by the timer and reported back, so a caller whose assertion fails
    // can say WHICH mechanism ended the child instead of asserting a theory.
    let treeAtKill = '';
    let groupKillDelivered = false;
    let sweptPids = 0;
    // Hoisted out of the timer callback so the SECOND sweep pass can re-signal
    // exactly the pids the first one saw. A pid does not change when its parent
    // is reaped; a PPID does, which is the entire reason for keeping this.
    const capturedDescendants = PID_SEED.slice();
    // The leader's own row, read at kill time, so pass 2 can tell "still the
    // process we started" from "a new occupant of a recycled pid".
    //
    // DECLARED HERE, before the timer that writes it — and that placement is
    // load-bearing, not tidiness. A `let` further down, between the `await` and
    // the second sweep, is in its TEMPORAL DEAD ZONE for the whole run: the
    // `setTimeout` callback fires at `timeoutMs` and assigns it before the
    // function has resumed past the `await`, so the write throws
    // `ReferenceError: Cannot access 'leaderBefore' before initialization` and
    // the group signal never goes out at all. MEASURED, not reasoned: the E2E
    // child-lifetime test failed with exactly that error. Every other mutable in
    // this function is declared in this block for the same reason.
    let leaderBefore = null;

    if (child.stdout !== null) {
        child.stdout.on('data', (chunk) => {
            output += chunk.toString('utf8');
        });
    }
    if (child.stderr !== null) {
        child.stderr.on('data', (chunk) => {
            output += chunk.toString('utf8');
        });
    }

    // `close` rather than `exit`: it fires after stdio has been fully drained, so
    // the captured output is complete instead of truncated at the last line, and
    // it always fires — including after a successful `exit`.
    const exited = once(child, 'close');

    if (timeoutMs > 0) {
        const killTimer = setTimeout(() => {
            // The GROUP, not the process. `-child.pid` is the process-group id
            // because `detached: true` made the child its own leader.
            if (child.pid === undefined) {
                killNote = '\nthe child had no pid when the timeout fired; nothing could be signalled.\n';
                return;
            }

            // ── The tree is snapshotted BEFORE anything is signalled ──
            // This is the whole reason the sweep is trustworthy. A SIGKILLed
            // leader is reaped and its children reparented, so a PPID walk
            // issued after the signal finds an empty list and the sweep passes
            // vacuously — which is the exact shape of a green test that proves
            // nothing. The captured text is also the diagnosis a caller needs:
            // it shows the pgid every pid belonged to BEFORE the kill, which is
            // how "was it in the group?" becomes a fact rather than a belief.
            //
            // The SAME table read produces the snapshot, so the pgid column in
            // `treeAtKill` and the pids about to be signalled come from one
            // observation of the machine rather than two that can disagree.
            const before = readProcessTable();
            // The leader's row from the SAME read, kept for the pass-2 identity
            // check below. Free here — the table is already in hand — and it is
            // the only answer to "is this pid still OUR process" that does not
            // require guessing.
            leaderBefore = before === null ? null : (before.get(child.pid) ?? null);
            if (before === null) {
                // Stated rather than swallowed: the sweep below will find
                // nothing, and "nothing" is indistinguishable from "already
                // dead" unless the reason is on the record. The comment on
                // `readParentTable` promises this note exists — it did not
                // before, because nothing ever set it.
                killNote +=
                    '\nthe process table could not be read on this platform, so the descendant sweep had ' +
                    'nothing to work from; the group signal carried the whole job.\n';
            }
            treeAtKill = before === null ? UNREADABLE_TREE(child.pid) : describeProcessTreeFrom(child.pid, before);
            const snapshot = descendantPids(child.pid, readParentTable(before === null ? NO_TABLE : before));
            capturedDescendants.push(...snapshot);

            // 1. The group — one syscall, and it reaches every descendant that
            //    inherited the group.
            try {
                process.kill(-child.pid, 'SIGKILL');
                groupKillDelivered = true;
            } catch (error) {
                // ESRCH means the group is already gone — the child exited in the
                // same tick the timer fired, a legitimate race and not an error.
                // Anything else means something may have survived, and that
                // belongs in the output rather than in a swallowed catch.
                const code = error && typeof error === 'object' && 'code' in error ? String(error.code) : 'unknown';
                killNote += `\nthe process group could not be killed (${code}); the descendant sweep is the only thing that ran.\n`;
            }

            // 2. The sweep — children first, so a killed parent cannot reparent
            //    a child out of reach before its turn comes. PPID descent is the
            //    one handle that does not depend on group membership, and what
            //    it buys is that a descendant which did not inherit the group
            //    still dies.
            sweptPids += sweepDescendants(snapshot);
        }, timeoutMs);
        cancelKill = () => {
            clearTimeout(killTimer);
        };
    }

    // `Promise.race` with a bounded wait, because the fallback is not decoration:
    // if the kill did not land, `once(child, 'close')` never resolves and this
    // function would hang forever — the exact failure it exists to prevent, one
    // level up. The extra grace is the window in which a SIGKILLed group is
    // reaped; after it the child is reported as killed whether or not it agreed.
    const outcome = await Promise.race([exited, delay(timeoutMs > 0 ? timeoutMs + 2000 : 0)]);
    cancelKill();

    // ── Sweep pass 2 ──
    // A signal is asynchronous: a pid that was alive when the timer fired may
    // not have been reaped when the race resolved, and the group's members have
    // by now been reparented. So the pre-kill snapshot is re-signalled, and a
    // fresh walk is taken against whatever the kernel still reports.
    //
    // ## The fresh walk is CONDITIONAL, and it is the only conditional one
    //
    // The snapshot needs no condition: its pids were this child's descendants
    // moments ago, and re-signalling a pid that has since died is the work
    // succeeding. The FRESH walk is different in kind — it starts from
    // `child.pid` and follows whatever is below it *now*, and a pid is not
    // reserved. If the leader was reaped and its number handed to something
    // else, that fresh walk walks into a stranger's process tree and SIGKILLs
    // processes that had nothing to do with this test.
    //
    // So the walk only runs while the premise it rests on still holds, decided
    // by `isSameProcess` above: the row now at `child.pid` must match the row
    // captured at kill time in pid, pgrp AND comm. The old check — "occupied and
    // a group leader" — proved only that SOME group leader holds the number, and
    // a recycled pid that happens to become a new group leader passed it. When
    // the premise fails, the pass degrades to the snapshot alone — which is still
    // every pid the sweep ever intended to reach, and the pass reports why it
    // did less rather than doing it silently.
    //
    // The window is the whole `runChild` call: a recycled leader pid requires
    // the kernel to have wrapped the pid space and handed this number to a new
    // process within one timeout, which is why this is rated low — but "very
    // unlikely" is a probability, and a conditional costs two lines.
    //
    // ## This is NOT the only place in the file that can signal a stranger
    //
    // An earlier version of this comment called the fresh walk "the one place
    // in this file that can do damage rather than merely report a failure". That
    // was wrong, and wrong in the direction that makes a reader stop looking.
    // The unconditional re-signal of `capturedDescendants` at the end of this
    // pass has the SAME exposure, because a pid is not reserved: had the kernel
    // handed one of the snapshot's numbers to a new process in the meantime,
    // that SIGKILL lands on it. So does the pre-kill snapshot, and so does the
    // group signal — that one needs a recycled pgid that the new leader kept.
    //
    // What the conditional buys is therefore not SAFETY, it is a BOUND: the
    // fresh walk can collect an entire stranger SUBTREE from one recycled
    // number, while a snapshot pid is a single already-recorded identity,
    // signalled at most twice. The honest summary is that this file narrows the
    // blast radius and does not eliminate it — which is why the premise check,
    // the snapshot and the group signal are reported to the caller as three
    // separate facts rather than summed into one reassuring number.
    //
    // MEASURED (temporary instrumentation, removed again), one `runChild` call
    // with a 20 s bound on darwin:
    //
    //     leaderPid=73159  leaderRow=null  premiseHeld=false
    //     pass1Swept=2      captured=4     tableRows=803
    //
    // The premise does NOT hold in the normal case — by the time the race
    // resolves, the leader is already reaped and its row is gone, so the fresh
    // walk would have found nothing anyway. It is skipped rather than run, which
    // is the point: the pass whose output is empty on every ordinary run is the
    // one that could collect a stranger's subtree if a pid ever did get reused.
    // What is left — the four captured pids, re-signalled unconditionally — is
    // the half that does real work, and it does not depend on the premise.
    //
    // ## The fresh walk READS THE MACHINE (position 34 — this was vacuous)
    //
    // It used to be written `descendantPids(child.pid, readParentTable())` —
    // with NO argument, which resolves to `NO_TABLE`, i.e. an EMPTY map. An
    // empty map has no children, so the walk returned the `[0]` seed and
    // nothing else, and `sweepDescendants` skipped the `0`. Measured: the pass
    // issued **zero** SIGKILLs, on every platform, while the whole suite stayed
    // green. It read as a mechanism and was a no-op — the exact shape of a
    // green test that proves nothing. `freshParentTable()` makes the read
    // explicit and unmissable; `run-child.test.ts` pins both halves (the read
    // finds this process, and no call site may drop the argument).
    if (timeoutMs > 0 && child.pid !== undefined) {
        const leader = readProcessRow(child.pid);
        // `NO_ROW` for "no row", because `readProcessRow`'s `null` and the
        // guard's seeded default have to agree on what absence looks like.
        const leaderIsStillOurs = isSameProcess(
            child.pid,
            leader === null ? NO_ROW : leader,
            leaderBefore === null ? NO_ROW : leaderBefore,
        );
        if (leaderIsStillOurs) {
            const pass2Table = freshParentTable();
            if (pass2Table.size === 0) {
                // Stated, not swallowed — same reasoning as the pre-kill read
                // above. A walk over zero rows cannot kill anybody, and a
                // diagnosis that cannot tell that from "swept, found nothing" is
                // the diagnosis that hides a broken mechanism.
                killNote +=
                    '\nthe second sweep pass could not read the process table, so its fresh walk had ' +
                    'nothing to work from; only the pre-kill snapshot was re-signalled.\n';
            }
            sweptPids += sweepDescendants(descendantPids(child.pid, pass2Table));
        }
        sweptPids += sweepDescendants(capturedDescendants);
    }

    // `once` yields the event's arguments; index 0 is the code, index 1 the
    // signal (both null when the child was signalled). The delay branch yields
    // `undefined`, which is the discriminator between "the child finished" and
    // "we gave up".
    const finishedOnItsOwn = Array.isArray(outcome);
    const code = finishedOnItsOwn ? (outcome[0] ?? 0) : 137;
    const signal = finishedOnItsOwn ? (outcome[1] ?? null) : 'SIGKILL';
    const killedForTimeout = !finishedOnItsOwn || signal === 'SIGKILL';

    return {
        // A signalled exit has no code of its own; the 137 stand-in keeps "the
        // child failed" and "the child was killed" distinguishable in one object
        // for a caller that reads only `status`.
        status: code === 0 && !killedForTimeout ? 0 : code === 0 ? 137 : code,
        output: output + killNote + (finishedOnItsOwn ? '' : `\nthe child was killed after ${timeoutMs}ms.\n`),
        killedForTimeout,
        killedWith: killedForTimeout ? signal : '',
        elapsedMs: Date.now() - started,
        // ── The diagnosis a caller needs in order to report a fact ──
        //
        // `finishedOnItsOwn` distinguishes the two races inside this function:
        // the `close` event won, or the delay gave up first. On CI the `close`
        // branch is the one that won — the leader was reaped after the SIGKILL,
        // which is why no "the child was killed after …" note appears in the log
        // even though the deadline plainly did fire.
        finishedOnItsOwn,
        // Whether the group signal was actually delivered. `false` means the
        // sweep was the only mechanism that ran, which is a fact worth having
        // rather than something to be inferred from an absent error message.
        groupKillDelivered,
        // How many SIGKILLs the sweep issued across both passes.
        sweptPids,
        // `ps` output for the leader and its descendants AS THEY WERE at kill
        // time — the pgid column is what settles group membership.
        treeAtKill,
        // The raw note, kept separately from `output` so a caller can report
        // "there was a killNote, and its errno was X" instead of "no note
        // appeared, therefore the kill was fine".
        killNote,
    };
}

/**
 * A timer that resolves. `0` means "the smallest real bound" rather than an
 * eternal wait — a caller that passes no timeout still gets a bounded function.
 */
function delay(ms = 0) {
    return new Promise((resolve) => {
        setTimeout(resolve, ms > 0 ? ms : 1);
    });
}
