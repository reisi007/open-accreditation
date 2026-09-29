import { execFileSync, spawn } from 'node:child_process';
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
 * **In principle is doing a lot of work in that sentence, and the measurement
 * says the principle did not hold on CI.** Playwright's runner does spawn its
 * workers non-detached so they stay in the group (see the comment at
 * `playwright/lib/runner/index.js:1916`), and the group signal itself was
 * delivered on Linux CI — the leader died of SIGKILL and no `killNote` was
 * produced. And yet the probe's own worker pid was still alive five seconds
 * later, on three consecutive attempts, while the identical test is green on
 * darwin. So the process group is the PRIMARY mechanism — one syscall, it gets
 * the launcher and every descendant that inherited the group — but it is not
 * the only one, and on its own it left a live process behind on the platform
 * that matters most.
 *
 * Therefore: after the group signal, a **descendant sweep** follows. It is a
 * plain recursive walk of `--ppid` from the leader, SIGKILLing every pid it
 * finds, then repeating the walk once more in case a process was reparented or
 * spawned into the gap. PPID descent is the only handle available that does not
 * depend on group membership — which is exactly the property that was
 * measurably not reliable here.
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
 * `pid -> ppid` for every process on the machine, or an empty map if `ps` fails.
 *
 * ## Why not `ps --ppid`
 *
 * `--ppid` is a **procps** (GNU/Linux) flag. BSD `ps` — which is what macOS
 * ships — does not have it: measured here, `ps -o pid= --ppid 1234` answers
 * `ps: illegal option -- -` and exits 1. A sweep written against that flag
 * would therefore pass on CI and fail on every developer machine, which is the
 * worst possible split: the platform where the failure would be visible is the
 * one platform that never runs it.
 *
 * `-A -o pid=,ppid=` is accepted by BOTH implementations (verified on darwin
 * here; it is also valid procps syntax), so the whole table is read once and
 * filtered in JS. That is not slower in any way that matters — the table is a
 * few hundred lines and this runs at most a handful of times per child.
 */
function readParentTable() {
    try {
        const raw = execFileSync('ps', ['-A', '-o', 'pid=,ppid='], {
            encoding: 'utf8',
            maxBuffer: 8 * 1024 * 1024,
        });
        const parents = new Map();
        for (const line of raw.split('\n')) {
            const trimmed = line.trim();
            if (trimmed === '') {
                continue;
            }
            const parts = trimmed.split(/\s+/);
            const pid = Number(parts[0]);
            const ppid = Number(parts[1]);
            if (Number.isInteger(pid) && Number.isInteger(ppid)) {
                parents.set(pid, ppid);
            }
        }
        return parents;
    } catch {
        // An empty table means the sweep finds nothing to do and the group
        // signal carries the whole job — the previous behaviour, not a new
        // failure mode. `killNote` records that the sweep was unavailable.
        return new Map();
    }
}

/**
 * Every pid below `rootPid`, breadth-first, from a parent table.
 *
 * A pid is a stable identity; a PPID is not. Once the leader is reaped its
 * children are reparented away and this walk — repeated later — returns
 * nothing, which is why the caller snapshots the result while the leader is
 * still alive and re-signals the snapshot afterwards.
 */
function descendantPids(rootPid = 0, parents = new Map()) {
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
 * SIGKILL each pid, counting the ones that were actually reachable.
 *
 * An `ESRCH` is not a failure: it means the pid is gone, which is the desired
 * outcome — usually the work of the group signal that went out a moment
 * earlier. Any other errno is equally uninteresting here, because the point of
 * the sweep is best-effort coverage of a handle that is not group membership,
 * and the caller reports coverage through `sweptDescendants` rather than
 * through a throw.
 */
function sweepDescendants(pids = PID_SEED) {
    let signalled = 0;
    for (const pid of pids) {
        // The `0` guard is a safety interlock, not defensive style: pid 0 means
        // "every process in MY group" to `kill(2)`, and this driver is itself a
        // process with a group. See `PID_SEED`.
        if (pid <= 0) {
            continue;
        }
        try {
            process.kill(pid, 'SIGKILL');
            signalled += 1;
        } catch {
            // Already gone, or gone between the snapshot and now.
        }
    }
    return signalled;
}

/**
 * A `ps` dump of `pid` and everything below it, in the exact format a diagnosis
 * needs: `pid, ppid, pgid, stat, comm`.
 *
 * Returned as raw lines rather than parsed objects because the consumer is a
 * human reading a test failure, and because `comm` may legitimately contain
 * spaces (an absolute path), which would make a positional parse a source of
 * bugs in the one place that exists to explain something.
 */
export function describeProcessTree(pid = 0) {
    if (pid <= 0) {
        return 'no pid to describe.';
    }
    let raw = '';
    try {
        raw = execFileSync('ps', ['-A', '-o', 'pid,ppid,pgid,stat,comm'], {
            encoding: 'utf8',
            maxBuffer: 8 * 1024 * 1024,
        });
    } catch (error) {
        return `ps failed while describing pid ${pid}: ${error instanceof Error ? error.message : String(error)}`;
    }

    const rows = raw
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');

    // Whether the first row is a header is DETECTED, not assumed: both `ps`
    // flavours print one for `-o pid,ppid,…` (the `=`-less form always does),
    // but a leading data row mistaken for a header would silently drop PID 1
    // from the dump — and PID 1 is exactly the row a reparenting question turns
    // on. Cheap to check, and a wrong guess here would be invisible.
    const hasHeader = rows.length > 0 && Number.isNaN(Number(rows[0].split(/\s+/)[0]));
    const header = hasHeader ? rows[0] : 'PID  PPID  PGID STAT COMMAND';
    const body = hasHeader ? rows.slice(1) : rows;

    const parents = new Map();
    for (const row of body) {
        const parts = row.split(/\s+/);
        const rowPid = Number(parts[0]);
        const rowPpid = Number(parts[1]);
        if (Number.isInteger(rowPid) && Number.isInteger(rowPpid)) {
            parents.set(rowPid, rowPpid);
        }
    }

    const wanted = new Set([pid, ...descendantPids(pid, parents)]);
    const shown = body.filter((row) => wanted.has(Number(row.split(/\s+/)[0])));
    if (shown.length === 0) {
        return `pid ${pid}: no matching process — it is gone.\n${header}`;
    }
    return `${header}\n${shown.join('\n')}`;
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
            // leader is reaped and its children reparented, so a `--ppid` walk
            // issued after the signal finds an empty list and the sweep passes
            // vacuously — which is the exact shape of a green test that proves
            // nothing. The captured text is also the diagnosis a caller needs:
            // it shows the pgid every pid belonged to BEFORE the kill, which is
            // how "was it in the group?" becomes a fact rather than a belief.
            treeAtKill = describeProcessTree(child.pid);
            const snapshot = descendantPids(child.pid, readParentTable());
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
            //    one handle that does not depend on group membership, which is
            //    the property that was measured to be unreliable on CI.
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
    // by now been reparented, so the walk is repeated against whatever the
    // kernel still reports AND the pre-kill snapshot is re-signalled. Both, not
    // either: the snapshot covers the reparented, the fresh walk covers a pid
    // that appeared after the first one was taken.
    if (timeoutMs > 0 && child.pid !== undefined) {
        sweptPids += sweepDescendants(descendantPids(child.pid, readParentTable()));
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
