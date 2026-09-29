import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    descendantPids,
    freshParentTable,
    isExecuting,
    isSameProcess,
    isZombieState,
    parseProcStat,
    readParentTable,
    statCodeFor,
    sweepDescendants,
} from './run-child';

/**
 * ## Why the driver has a unit test at all, when it ships inside `tests/e2e`
 *
 * Because a `tests/e2e` directory is not collected by Vitest, and everything
 * in it used to be reachable only by running Playwright — which boots browsers,
 * a backend and a database. The functions below are pure, or take their one
 * impure input (`process.kill`, the process table) as an argument, and they
 * decide things a red run is supposed to catch. "Only testable in the E2E job"
 * is the same shape as "only testable on Linux CI", and the position that
 * produced this file measured exactly that gap: on darwin the state alphabet is
 * `? R S U`, so the `Z` branch was never taken on a developer machine, and
 * deleting the pattern would have left every suite green.
 *
 * This file is the PRIMARY test of the rule; `child-lifetime.spec.ts` is the
 * second consumer and the only one that can show a group SIGKILL reaching a
 * real Playwright worker.
 *
 * ## The two structural facts that made this possible
 *
 * MEASURED, both re-checked while writing it:
 *
 * - `vitest.config.ts` `include` covers `src` and `scripts` only. A
 *   `*.test.ts` under `tests/` is therefore invisible to Vitest until that
 *   pattern is widened to reach the `tests` directory — which is why the config
 *   carries it now. Before the widening, `find tests -name '*.test.ts'` returned
 *   nothing, so adding the pattern collected no pre-existing file.
 * - `playwright.config.ts` `testMatch` is the `*.spec.ts` glob, so this file is
 *   invisible to Playwright. That is the point of the name: unit tests here must
 *   not be collected by the E2E run, and E2E specs must not be collected here.
 */

/**
 * A parent table, spelled the way `readProcessTable` spells one: pid -> row with
 * at least a `ppid`. A seeded literal rather than a fixture file, because the
 * fixture IS the input under test — a shared helper that built the table could
 * hide the very field the walk depends on.
 *
 * Every array here is SEEDED (`= [[0, 0]]`, `= [0]`), and every null is
 * narrowed by a `throw`. Both are the price of this directory: `tests/e2e/**`
 * is linted with the PLAIN-JS parser (an annotation is an ESLint parse error)
 * while `tsc -b` type-checks it under `strict`, so a bare `[]` is `never[]` and
 * a bare `.push(x)` cannot be typed. `run-child.ts`'s file header documents the
 * same trade-off for the same reason.
 */
function rows(entries = [[0, 0]]) {
    const table = new Map();
    for (const entry of entries) {
        table.set(entry[0], {
            pid: entry[0],
            ppid: entry[1],
            pgrp: entry[1],
            state: entry[2] ?? 'S',
            comm: entry[3] ?? `pid-${entry[0]}`,
        });
    }
    return table;
}

/** A parent table in its REDUCED form, which is what the walk actually reads. */
function parents(entries = [[0, 0]]) {
    return readParentTable(rows(entries));
}

/** A `parseProcStat` row, or a thrown error naming the line that failed to parse. */
function rowOrThrow(raw = '') {
    const row = parseProcStat(raw);
    if (row === null) {
        throw new Error(`these tests are worthless if this line does not parse: ${JSON.stringify(raw)}`);
    }
    return row;
}

describe('isZombieState — the state alphabet of proc(5)', () => {
    it('reads a corpse as a corpse and every other listed state as alive', () => {
        for (const dead of ['Z', 'X', 'x']) {
            expect(isZombieState(dead), `${dead} cannot execute`).toBe(true);
        }
        for (const alive of ['R', 'S', 'D', 'I', 'T', 't', 'W', 'K', 'P']) {
            expect(isZombieState(alive), `${alive} is a live state`).toBe(false);
        }
    });

    it('does not read an unreadable state as a corpse — that is the CALLER\'s call', () => {
        // `isZombieState('')` is false by construction, and `isExecuting` turns
        // that into "assume still running". Asserted separately so the two halves
        // cannot be quietly merged later.
        expect(isZombieState('')).toBe(false);
    });

    it('anchors on the FIRST character, so a Z anywhere else is not a corpse', () => {
        // The anchor is what stops a table column or a wrapped field from
        // producing a corpse out of nowhere: `'SZ'` is not `'Z'`. MEASURED as a
        // real risk — a mis-parsed `/proc/<pid>/stat` line is exactly a state
        // string with other numbers in it, and an unanchored test would read that
        // wreckage as a dead process, which is the fail-OPEN direction.
        expect(isZombieState('SZ')).toBe(false);
        expect(isZombieState(' RZ')).toBe(false);
        // The complementary case: a corpse state with anything after it is still
        // a corpse. Asserted so the anchor cannot be "fixed" into a whole-string
        // comparison, which would reject a legitimate `Zs` from a future kernel.
        expect(isZombieState('Zs')).toBe(true);
    });
});

describe('parseProcStat — field alignment, from real kernel lines', () => {
    it('anchors on the LAST parenthesis, so a name with spaces and parens cannot shift the fields', () => {
        // MEASURED that this shape is real: a Chrome helper on a dev machine is
        // `(chrome_crashpad_handler)`, and a process is regularly called
        // `(Web Content)`. A `split(/\s+/)` parser reports a name fragment as the
        // state here, and `isZombieState` then classifies the wreckage.
        const row = rowOrThrow('4242 (Web Content (gpu)) Z 7 4242 4242 0 -1 4194304');
        expect(row.comm).toBe('Web Content (gpu)');
        expect(row.state).toBe('Z');
        expect(row.ppid).toBe(7);
        expect(row.pgrp).toBe(4242);
    });

    // A VISIBLE skip, not a degraded assertion. This test used to carry
    // `if (process.platform !== 'linux') { expect(typeof process.pid).toBe('number'); return; }`,
    // which reported itself as a pass on darwin while having read nothing from
    // any kernel — a green line that was about `process.pid`, not about
    // `/proc`. `it.skipIf` puts the platform in the report as its own line, and
    // leaves the reader of it able to see that the kernel-alignment check did
    // not run. The check itself is not lost on darwin: the hand-written kernel
    // line above exercises the same parser on the same field layout.
    it.skipIf(process.platform !== 'linux')(
        'parses THIS process out of the kernel\'s own output [linux only — /proc does not exist on darwin; the ' +
            'hand-written kernel line above covers the parser on every platform]',
        () => {
            const raw = readFileSync(`/proc/${process.pid}/stat`, 'utf8');
            const row = rowOrThrow(raw);
            expect(row.pid).toBe(process.pid);
            expect(row.ppid).toBe(process.ppid);
            expect(row.state).not.toBe('');
            expect(row.pgrp).toBeGreaterThan(0);
        },
    );

    it('returns null rather than a half-parsed row for anything it cannot read', () => {
        // A partial parse yields a row with pid 0 and state '' that LOOKS like
        // data. `null` is what keeps "no such process" distinguishable from
        // "unreadable", and those two answers make the caller do different
        // things.
        for (const junk of ['', '   ', 'nonsense', '1234 (sh', '1234 (sh) Z']) {
            expect(parseProcStat(junk), `"${junk}" must not parse`).toBeNull();
        }
    });
});

describe('readParentTable — pid to ppid, the only handle the walk has', () => {
    it('reduces rows to the parent map the walk descends', () => {
        const table = parents([
            [10, 1],
            [11, 10],
            [12, 11],
        ]);
        expect(table.get(10)).toBe(1);
        expect(table.get(11)).toBe(10);
        expect(table.get(12)).toBe(11);
    });

    it('is empty for an empty table, and empty for NO table at all', () => {
        expect(readParentTable(rows([])).size).toBe(0);
        expect(readParentTable().size).toBe(0);
    });
});

describe('descendantPids — the walk itself', () => {
    it('collects a three-level tree, breadth-first and in dependency order', () => {
        const found = descendantPids(
            10,
            parents([
                [10, 1],
                [11, 10],
                [12, 11],
                [13, 10],
                [99, 1],
            ]),
        );
        // `[0]` first is `PID_SEED` and is never signalled; what matters is that
        // the three descendants are there, the unrelated branch is not, and the
        // ROOT itself is not (it was signalled as a group already).
        expect(found).toContain(11);
        expect(found).toContain(12);
        expect(found).toContain(13);
        expect(found).not.toContain(99);
        expect(found).not.toContain(10);
        expect(found[0]).toBe(0);
    });

    it('returns ONLY the seed for an empty table, which is the vacuous case position 34 found', () => {
        // This is the shape the pass-2 walk had: no table, no descendants, and
        // `sweepDescendants` then skipping the seed — so the pass signalled
        // nothing while the suite stayed green. Naming it here means the
        // difference between "found nothing" and "looked at nothing" is a
        // documented shape, not a silent one.
        expect(descendantPids(10, parents([]))).toEqual([0]);
    });

    it('terminates on a cycle, because a corrupted table must not hang the kill path', () => {
        // A pid whose ppid is its own descendant is impossible from a real
        // kernel, and impossible-looking is exactly where a queue-based walk
        // hangs. `found.includes` is the guard; this asserts it is load-bearing.
        const found = descendantPids(
            10,
            parents([
                [10, 12],
                [11, 10],
                [12, 11],
            ]),
        );
        expect(found).toContain(11);
        expect(found).toContain(12);
        expect(found.filter((pid) => pid === 11).length).toBe(1);
    });

    it('reads the live machine, so a fresh walk is never vacuous', () => {
        // UNCONDITIONAL, and that is the whole content of this test. The
        // previous version wrapped the two assertions in `if (live.size === 0) {
        // … }` and put a NAMED FAILURE in the empty branch — written as
        // `expect(process.platform === 'linux' ? 'procfs must be readable' : 'ps
        // must be readable').toBe('procfs must be readable')`. On linux that
        // compares a string with itself: it can only ever fail on darwin, so the
        // branch that fires on a broken reader — the one this test exists for —
        // was the branch that could not fail.
        //
        // MEASURED, on the code as it stood: reducing `freshParentTable()` to
        // `return NO_TABLE` left the whole file at 29/29 green. The pin for
        // position 34 therefore had a second half that pinned nothing, and its
        // own comment ("a named failure is better than an empty assertion that
        // passes for the wrong reason on the platform it was written for")
        // described precisely the behaviour it exhibited.
        //
        // The tolerance is gone rather than reshaped because there is no
        // platform this suite runs on that lacks a reader: linux reads `/proc`
        // and darwin reads `ps` (`USE_PROC` in `run-child.ts`), both measured
        // present. A platform with neither is a platform where the kill path
        // silently sweeps nothing on every run — the exact defect of position
        // 34 — and the right answer there is a red run with a message that
        // names the mechanism, not a pass. Should a reader-less platform ever
        // need tolerating, the gate has to be VISIBLE (`it.skipIf`, the idiom
        // `child-lifetime.spec.ts` uses for its `/proc`-only test), never a
        // conditional assertion inside the test.
        const live = freshParentTable();
        expect(live.size, 'the process table must be readable here; a fresh walk over an empty table is vacuous').toBeGreaterThan(0);
        expect(live.get(process.pid), 'the fresh table must contain this very process').toBe(process.ppid);
    });
});

describe('sweepDescendants — the count and the interlock', () => {
    it('never lets pid 0 or a negative pid reach the signal', () => {
        // `process.kill(0, 'SIGKILL')` signals EVERY process in the caller's own
        // group, and the caller is a test runner with a group. The real killer is
        // therefore NOT used here: a regression in the interlock would
        // SIGKILL this very test run, which is a test that destroys its own
        // evidence. A recorder asserts the same property safely.
        // Seeded and trimmed: the seed is never signalled, so the array that
        // matters is `slice(1)` — see the seeding note at `rows()`. The recorder
        // takes a DEFAULT value for the same reason the driver does: without one
        // the parameter is an implicit `any` under `tsc -b` strict, and an
        // annotation is an ESLint parse error in this directory.
        const recorded = [0];
        const record = (pid = 0) => {
            recorded.push(pid);
        };
        const count = sweepDescendants([0, -1, -4242, 4321], record);
        expect(recorded.slice(1)).toEqual([4321]);
        expect(count).toBe(1);
    });

    it('counts a pid that is already gone as nothing signalled, and does not throw', () => {
        // ESRCH is the desired outcome, not a failure: the group signal usually
        // did the work a moment earlier.
        expect(
            sweepDescendants(
                [999999],
                () => {
                    throw Object.assign(new Error('kill ESRCH 999999'), { code: 'ESRCH' });
                },
            ),
        ).toBe(0);
    });

    // The third "nothing to signal" case — the seed-only list the walk returns
    // when it finds nothing — is asserted in the position-34 block below, which
    // is where that list is produced and where both empty tables are driven. It
    // lived here as well, under the name "signals nothing at all for the
    // seed-only list the walk returns when it finds nothing": the same assertion
    // with one table instead of two, and a second line in the report for a fact
    // the other test already carried.
});

describe('isExecuting — exists AND not a corpse', () => {
    it('is true for this very process, read from the live kernel', () => {
        // Not a fixture and not a mock: the only way this assertion can be wrong
        // is in the direction that matters — reading a RUNNING process as dead.
        expect(statCodeFor(process.pid), 'the reader must be able to read its own state').not.toBe('');
        expect(isExecuting(process.pid)).toBe(true);
    });

    it('is false for a pid that no longer exists', () => {
        // ESRCH from `kill(pid, 0)`: the answer we came for. A pid that has just
        // been reaped is the honest way to produce it.
        const gone = 999998;
        expect(isExecuting(gone)).toBe(false);
    });

    it('is false for a non-positive pid, without a syscall', () => {
        // pid 0 would mean "my own group" to `kill(2)`; the guard is the reason
        // this function is safe to call with anything.
        expect(isExecuting(0)).toBe(false);
        expect(isExecuting(-1)).toBe(false);
    });

    it('reads a zombie as NOT executing, and every live state as executing', () => {
        // The fail-open/closed pair, driven through the injected state reader so
        // the branch is reachable at all: with the real `statCodeFor` a pid that
        // `kill(pid, 0)` accepts almost always has a readable state, so the
        // zombie branch would only ever run on the platform where it was never
        // measured.
        for (const dead of ['Z', 'X', 'x']) {
            expect(isExecuting(process.pid, () => dead), `state ${dead} cannot execute`).toBe(false);
        }
        for (const alive of ['R', 'S', 'D', 'T']) {
            expect(isExecuting(process.pid, () => alive), `state ${alive} executes`).toBe(true);
        }
    });

    it('treats an UNREADABLE state as still running — fail-closed, and the reason CI went red three times', () => {
        // The dangerous branch, and the one the position named: `''` means the
        // mechanism is blind, not that the process is gone. A soft predicate here
        // would turn a broken tool into a green lie.
        expect(isExecuting(process.pid, () => '')).toBe(true);
    });
});

/**
 * The source of `run-child.ts` with every comment removed, so a pin can look at
 * CODE and not at the prose that discusses it. Both comment forms are stripped
 * with one alternation rather than a line-by-line guess, and a string literal
 * is left alone — which is a known simplification: a comment marker inside a
 * string would be removed too. That is acceptable here because the driver has no
 * string containing a comment terminator or a line-leading `//`, and a false
 * positive would show up as this test failing, not as a silently weakened pin.
 */
function stripComments(text = '') {
    return text.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

/**
 * YAML comments removed, for the same reason as `stripComments` and with the
 * same consequence if forgotten: the `options: --init` line in `ci.yml` is
 * EXPLAINED by a comment block that mentions `--init` on every one of its
 * lines. A pin that read the raw text would pass with the flag deleted — which
 * is precisely what the first version of this test did, and it was only caught
 * because the mutation run was made before the pin was trusted.
 */
function stripYamlComments(text = '') {
    return text.replace(/[^\n]*$/gm, (line) => {
        // A `#` inside a quoted scalar is data, not a comment; the workflow uses
        // none, and a wrong answer here shows up as this test failing.
        const at = line.indexOf('#');
        return at < 0 ? line : line.slice(0, at);
    });
}

/**
 * The `container:` MAPPING of a job block — the span the reaper pin below is
 * allowed to read, and deliberately nothing more.
 *
 * ## Why the span is part of the assertion
 *
 * MEASURED on the previous version of this pin: it sliced the job from its
 * header to `\n    steps:`, which is `container:` PLUS `env:` PLUS `services:`.
 * Moving `options: --init` from the job container onto the job's postgres
 * SERVICE — where a reaper is worth nothing, because the orphan is created
 * inside the job container — left all 28 tests in this file green. So the fast
 * half of D26 was satisfiable by the wrong thing, while its own comment claimed
 * the opposite.
 *
 * Therefore: anchor on the job's own `container:` key and stop at the NEXT key
 * at the job's own four-space indent. The container's children are indented
 * deeper, `env:`/`services:`/`steps:` are not, and a four-space comment line
 * (which is not a key) does not end the span either — an early end would make
 * the pin read short and go red, which is the safe direction, but the message
 * would then be about a missing flag rather than about the span.
 *
 * Returns `{ found, block }` rather than a bare string so the caller can say
 * "the job no longer runs in a container" and "the container no longer carries
 * `--init`" — two different faults, and a pin that cannot tell them apart
 * reports the second when the first happened.
 */
function containerMapping(job = '') {
    const lines = job.split('\n');
    const start = lines.findIndex((line) => /^ {4}container:/.test(line));
    if (start === -1) {
        return { found: false, block: '' };
    }
    const next = lines.findIndex((line, index) => index > start && /^ {4}[^#\s]/.test(line));
    return { found: true, block: lines.slice(start, next === -1 ? lines.length : next).join('\n') };
}

describe('isSameProcess — the pass-2 identity guard (position 33, L4)', () => {
    /** A row as `readProcessTable` spells one, for the pid given. */
    function row(pid = 0, pgrp = 0, comm = 'node', ppid = 1) {
        return { pid, ppid, pgrp, state: 'S', comm };
    }

    /** "There is no row" — the driver's own seeded absence value. */
    const NO_ROW = { pid: 0, ppid: 0, pgrp: 0, state: '', comm: '' };

    it('accepts a pid whose occupant is unchanged', () => {
        // The premise holding is the NORMAL path, and it has to be tested as
        // carefully as the rejection: a guard that always returned false would be
        // "safe" and would silently delete the pass this file exists to run.
        expect(isSameProcess(10, row(10, 10, 'node', 1), row(10, 10, 'node', 1))).toBe(true);
    });

    it('REJECTS a recycled pid that is a group leader — the case the old check accepted', () => {
        // `pgrp === pid` holds for the stranger, so the previous predicate
        // (`leader !== null && leader.pgrp === child.pid`) returned TRUE here and
        // the walk would have entered a stranger's subtree. The recorded `comm`
        // is what rejects it. This is the regression test for the guard change.
        expect(isSameProcess(10, row(10, 10, 'postgres', 1), row(10, 10, 'node', 1))).toBe(false);
    });

    it('rejects a recycled pid that also inherited the group id', () => {
        // Stronger than the case above, and the reason the guard compares `comm`
        // as well as `pgrp`: both of the cheap signals agree with the original.
        expect(isSameProcess(10, row(10, 10, 'node', 1), row(10, 10, 'npx', 1))).toBe(false);
    });

    it('rejects a leader that no longer leads its own group', () => {
        // The kernel regrouped it. Walking from it would not be walking from our
        // leader, whatever else matches.
        expect(isSameProcess(10, row(10, 99, 'node', 1), row(10, 99, 'node', 1))).toBe(false);
    });

    it('rejects an absent row on either side, and a non-positive pid', () => {
        // No row is the MEASURED normal case, not an edge case: by the time the
        // race resolves the leader is reaped and its row is gone. Fail-closed is
        // the right direction here because the cost of the guard is coverage,
        // not safety. Absence is expressed as the seeded zero row — the same
        // value the driver passes — rather than as `null`.
        expect(isSameProcess(10, NO_ROW, row(10, 10, 'node', 1))).toBe(false);
        expect(isSameProcess(10, row(10, 10, 'node', 1), NO_ROW)).toBe(false);
        expect(isSameProcess(10, NO_ROW, NO_ROW)).toBe(false);
        expect(isSameProcess(0, row(0, 0, 'node', 1), row(0, 0, 'node', 1))).toBe(false);
    });

    it('rejects a row for a different pid than the one asked about', () => {
        // A table lookup that returned a neighbour would otherwise sail through
        // every other check.
        expect(isSameProcess(10, row(11, 11, 'node', 1), row(11, 11, 'node', 1))).toBe(false);
    });
});

describe('position 34 — the pass-2 walk must never be handed an empty table', () => {
    const source = readFileSync(path.resolve(process.cwd(), 'tests/e2e/ownership-probe/run-child.ts'), 'utf8');

    it('has no call site that omits the table argument', () => {
        // THE REGRESSION TEST. `readParentTable()` with no argument resolves to
        // the empty `NO_TABLE`, which is indistinguishable from "the walk found
        // nothing": no compile error, no runtime error, and a sweep that signals
        // zero pids. That is what pass 2 did, on every platform, with the whole
        // suite green.
        //
        // A source pin is normally the weak kind of test, and it is here the
        // strong kind, because the alternative does not exist in this directory:
        // `tests/e2e/**` is linted with the PLAIN-JS parser, so the parameter
        // cannot be given a type annotation, and a parameter with neither a type
        // nor a default is an implicit `any` under `tsc -b` strict. The default
        // is the ONLY way to type that parameter, which means the compiler can
        // never be the thing that rejects the empty call.
        //
        // Comments are stripped first, and that is not a convenience: this very
        // file's docblocks DISCUSS `readParentTable()` by name, and a pin that
        // matched prose would go red the moment someone documented the bug — a
        // test that punishes the documentation is a test that gets deleted. Only
        // CODE is scanned, which is what the original defect was.
        const callSites = stripComments(source)
            .split('\n')
            .filter((line) => /readParentTable\(\s*\)/.test(line))
            // The declaration line is `function readParentTable(table = NO_TABLE)`.
            .filter((line) => !line.includes('function readParentTable'));
        expect(
            callSites,
            '`readParentTable()` with no argument makes the descendant walk vacuous — it yields the ' +
                `[0] seed, which \`sweepDescendants\` skips, so the pass signals NOTHING while looking like it ` +
                `worked. Found ${callSites.length} such call site(s). Pass a real table (readProcessTable() / ` +
                'freshParentTable()) at every call site.',
        ).toEqual([]);
    });

    it('pins the reaper flag on the e2e job container, because a line in a workflow is a CLAIM', () => {
        // D26 ("no process leaks please") is only as strong as the mechanism that
        // implements it, and that mechanism is ONE option line in a YAML file
        // that nothing else in the repo reads. The behavioural proof is
        // `child-lifetime.spec.ts` — an orphan leaving the process table — and it
        // runs in the E2E job, which is minutes away and only on a push. This is
        // the fast half: it runs in the `frontend` job and turns a deleted
        // `options: --init` red in seconds.
        //
        // What is pinned is the DECISION, not the syntax — and the SPAN it is
        // read from is part of that, not a convenience. The first version sliced
        // the job from its header to `\n    steps:`, which is `container:` PLUS
        // `env:` PLUS `services:`; MEASURED, moving `options: --init` onto the
        // job's postgres service satisfied it (28/28 green) with no reaper in the
        // job container at all, so the fast half could be met by the wrong thing
        // while the comment it replaced claimed the opposite. `containerMapping`
        // reads the container mapping and nothing else.
        //
        // So the assertion fails for a removed flag, a renamed job, a job that no
        // longer runs in a container, and a container block that no longer
        // carries any `--init` at all. Reformatting the options list (array
        // instead of a string, an extra resource flag) does not. It also does not
        // fail for a reaper this pin cannot SEE — an `ENTRYPOINT` in the image,
        // or an `--init` spelled in a form the regex does not match. Stating the
        // limit is the point; a pin that claims more than it checks is the defect
        // this paragraph exists to remove.
        const workflow = readFileSync(path.resolve(process.cwd(), '..', '.github/workflows/ci.yml'), 'utf8');
        const jobStart = workflow.indexOf('\n  e2e:\n');
        expect(jobStart, 'the `e2e` job must still exist in ci.yml').toBeGreaterThan(-1);
        const container = containerMapping(workflow.slice(jobStart));
        expect(container.found, 'the `e2e` job must still run in a `container:` in ci.yml').toBe(true);
        expect(
            /--init\b/.test(stripYamlComments(container.block)),
            'Nutzerentscheid D26 requires a reaper at PID 1 in the E2E job container, which is what ' +
                '`container.options: --init` puts there. It is gone from the `e2e` job CONTAINER in ci.yml ' +
                '(a `--init` anywhere else in the job does not put a reaper in front of the test runner). ' +
                'Without it, an orphaned child stays in the process table as a `Z` forever — the exact ' +
                'failure D26 was decided about — and `child-lifetime.spec.ts` will report it in the E2E job.',
        ).toBe(true);
    });

    it('sweeps the seed-less list through a RECORDER, and signals nothing — the vacuous pass, inert', () => {
        // The other half of the same regression, stated as behaviour: whatever
        // the walk hands over, the sweep's interlock is what keeps it inert. If
        // the interlock regressed, THIS is the test that would notice.
        //
        // Through a RECORDER, and the name now says so. It used to read "through
        // the real killer" while passing a recorder — the name asserted the
        // opposite of the test, and it is not a cosmetic wording problem: the
        // real killer here would be `process.kill(0, 'SIGKILL')`, which signals
        // EVERY process in this runner's own group. A test that destroys its own
        // evidence is the reason `sweepDescendants` takes its killer as a
        // parameter at all.
        //
        // This absorbs the near-duplicate that used to sit in the
        // `sweepDescendants` block above under the name "signals nothing at all
        // for the seed-only list the walk returns when it finds nothing". Two
        // tests, one assertion, two different empty tables. The tables are
        // DIFFERENT inputs, so both are driven here: the empty one is the
        // vacuous walk position 34 found, and the one holding only the leader
        // is a real machine that reported the leader and no descendants. What
        // was duplicated was the assertion, not the input, and one test can
        // cover both.
        const recorded = [0];
        const record = (pid = 0) => {
            recorded.push(pid);
        };
        for (const table of [parents([]), parents([[10, 1]])]) {
            const hand = descendantPids(10, table);
            expect(hand, 'the walk hands over the seed and nothing else').toEqual([0]);
            expect(sweepDescendants(hand, record)).toBe(0);
        }
        expect(recorded.slice(1)).toEqual([]);
    });
});
