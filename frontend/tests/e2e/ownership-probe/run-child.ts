import { spawn } from 'node:child_process';
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
 * ## What actually ends it
 *
 * A PROCESS GROUP. The child is spawned `detached: true`, which makes it a group
 * leader (its pgid equals its pid), and the group is then signalled as a whole
 * with `process.kill(-pgid, 'SIGKILL')`. Every descendant inherits the group, so
 * the launcher, the runner and the worker all get the signal in one call and
 * none of them can decline it.
 *
 * `SIGKILL` rather than `SIGTERM` is deliberate: `SIGTERM` is a REQUEST, and the
 * failure this module exists to prevent is a process too wedged to answer one —
 * the `hang.probe.ts` case blocks in `Atomics.wait`, which no handler
 * interrupts.
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
            try {
                process.kill(-child.pid, 'SIGKILL');
            } catch (error) {
                // ESRCH means the group is already gone — the child exited in the
                // same tick the timer fired, a legitimate race and not an error.
                // Anything else means something may have survived, and that
                // belongs in the output rather than in a swallowed catch.
                const code = error && typeof error === 'object' && 'code' in error ? String(error.code) : 'unknown';
                killNote = `\nthe process group could not be killed (${code}); a descendant may still be running.\n`;
            }
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
