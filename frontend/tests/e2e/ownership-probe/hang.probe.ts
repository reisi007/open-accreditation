import fs from 'node:fs';
import { test } from '@playwright/test';

/**
 * A probe that HANGS, so the driver's child timeout can be exercised for real.
 *
 * ## Why this is not in the normal probe run
 *
 * `half-failure.fixture.ts` proves that the teardown runs on a FAILED test. This
 * one exists to prove the complementary thing: that a child which never returns
 * is KILLED rather than left running. That claim is otherwise untestable, because
 * a driver with a correct timeout and a driver with no timeout produce the same
 * green run whenever the child behaves — and "the child always behaves" is
 * precisely the assumption the timeout exists to stop relying on.
 *
 * So the hang is INJECTED, and the fixture is a separate file for a second
 * reason: `playwright.ownership-probe.config.ts` selects its `testMatch` by name,
 * and adding a third file to the default match would make every real driver run
 * wait out a deliberate hang.
 *
 * It creates NOTHING. A hang probe that also created rows would need a teardown,
 * and the point of the exercise is what happens when there is no time left to run
 * one.
 */

const HANG_FILE = process.env.PROBE_HANG_RECORD_PATH ?? '';

/**
 * Blocks forever.
 *
 * `Atomics.wait` on a `SharedArrayBuffer` is the one sleep that cannot be
 * cancelled by a JS-level signal handler and does not spin the CPU — so a
 * SIGTERM-based kill (which is what a well-behaved child gets by default) has
 * nothing to interrupt, and only a SIGKILL ends it. That is the realistic shape
 * of the bug: not a process that agrees to die, but one that cannot.
 */
function blockForever() {
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0);
    // Unreachable while nobody posts to the buffer, which is the point.
    throw new Error('the hang probe was released, which should not happen');
}

test('hangs forever, writing its pid so the driver can look for it', async () => {
    if (HANG_FILE !== '') {
        fs.writeFileSync(HANG_FILE, JSON.stringify({ pid: process.pid, startedAt: Date.now() }), 'utf8');
    }
    blockForever();
});
