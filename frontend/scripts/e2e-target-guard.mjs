/**
 * The gate every script that WRITES to the dev database has to pass first.
 *
 * ## What it is for
 *
 * `scripts/e2e-per-spec-leaks.mjs` drives the full write mechanics of the suite —
 * dozens of Playwright invocations, each creating and deleting real rows — and it
 * inherited `E2E_BASE_URL` **unchanged**. The whole repository had no guard
 * against pointing that at anything but a disposable stack, and the default of an
 * inherited environment variable is "whatever the shell already had": a shared
 * staging host, a colleague's preview deployment, or a production URL left over
 * in `.env`.
 *
 * The failure this prevents is not a slow run. It is a script that, with every
 * assertion green, writes hundreds of rows into somebody else's database and
 * reports a table of deltas.
 *
 * ## Why the guard is not only a hostname check
 *
 * A loopback hostname is necessary and cheap, but it is not a proof: an SSH
 * tunnel, a `socat` forward or a reverse proxy all answer on `localhost` and all
 * point somewhere real. And no script can prove the far end is disposable — that
 * is a fact about the environment, not about the URL. So the guard asks for
 * THREE things and says plainly which of them a machine can establish:
 *
 * 1. **Provable, here:** the target host is loopback (or an RFC-6761 `*.localhost`
 *    name, which is what the harness itself uses for the `empty` tenant).
 * 2. **Not provable, so asserted by a human:** `E2E_LEAKS_TARGET=local`. A
 *    deliberate, greppable, per-invocation statement that this stack is
 *    disposable. A value other than `local` is refused rather than warned about.
 * 3. **Provable, but only live:** the target really is THIS project's dev stack —
 *    it serves the app, and the database the script is about to count carries
 *    this project's seeded bootstrap admin (`admin@example.com`).
 *
 * ## Ordering, which is the load-bearing part
 *
 * The guard throws BEFORE the tool takes its first snapshot and before it spawns
 * its first Playwright process. `e2e-per-spec-leaks.mjs` calls it as its first
 * statement, so a refusal means **nothing was touched** — which is what makes
 * "it refused" a safe answer rather than a late one.
 */

/** Raised when a target is not provably a disposable dev/test stack. */
export class DevStackRefusal extends Error {
    constructor(reasons) {
        super(
            'refusing to run against a target that is not a provably disposable dev/test stack:\n' +
                reasons.map((reason) => `  - ${reason}`).join('\n') +
                '\n\nThis tool WRITES to the target database (it runs the suite\'s full create/delete ' +
                'mechanics many times). It has not touched anything: no snapshot was taken and no Playwright ' +
                'process was started.\n' +
                'Start a local stack and re-run with\n' +
                '    E2E_LEAKS_TARGET=local\n' +
                'pointed at it. If you really do mean to point this at a disposable stack on another host, ' +
                'give it a loopback name (a tunnel, an /etc/hosts entry) — the guard checks the host it can ' +
                'reach, not the one you meant.',
        );
        this.name = 'DevStackRefusal';
        this.reasons = reasons;
    }
}

/**
 * Is this hostname one nothing but this machine can answer for?
 *
 * `0.0.0.0` is in the list because it is what a socket binds, not what it dials,
 * and a dial to it lands on localhost — treating it as remote would refuse a
 * configuration that is not one. Everything else is refused, including bare
 * hostnames: a name that resolves through DNS is a name the guard cannot vouch
 * for.
 */
export function isLoopbackHost(hostname) {
    const host = hostname.toLowerCase().replace(/^\[|\]$/g, '');
    if (host === 'localhost' || host === '::1' || host === '0.0.0.0' || host === '::') {
        return true;
    }
    if (host.endsWith('.localhost')) {
        return true;
    }
    return /^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(host);
}

/**
 * The checks that need nothing but the URL and the environment. Returned as a
 * LIST of reasons rather than thrown, so a caller (and the test) can assert on
 * all of them at once instead of discovering them one run at a time.
 */
export function devStackRefusals(baseUrl, acknowledgement) {
    const reasons = [];
    let target = null;
    try {
        target = new URL(baseUrl);
    } catch {
        reasons.push(`E2E_BASE_URL is not a URL at all: ${JSON.stringify(baseUrl)}`);
    }
    if (target !== null) {
        if (target.protocol !== 'http:' && target.protocol !== 'https:') {
            reasons.push(`E2E_BASE_URL uses the scheme "${target.protocol}" — only http and https can be checked`);
        }
        if (!isLoopbackHost(target.hostname)) {
            reasons.push(
                `E2E_BASE_URL points at "${target.hostname}", which is not a loopback host. This tool creates ` +
                    'and deletes hundreds of rows there; the guard only trusts a host this machine answers for.',
            );
        }
    }
    if (acknowledgement !== 'local') {
        reasons.push(
            `E2E_LEAKS_TARGET is ${JSON.stringify(acknowledgement ?? null)}, not "local". No URL can prove the ` +
                'far end is disposable — a tunnel and a reverse proxy both answer on localhost — so the ' +
                'disposability is asserted, per invocation, by a human.',
        );
    }
    return reasons;
}

/**
 * The live half: is the reachable loopback target actually this project's dev
 * stack? Injected `fetch`/`probe` so the test can drive every branch without a
 * server, and so this file has no import-time side effect.
 *
 * @param {object} deps
 * @param {(url: string) => Promise<{ ok: boolean, status: number }>} deps.fetchImpl
 * @param {() => Promise<boolean>} deps.databaseHoldsSeededAdmin
 */
export async function liveDevStackRefusals(baseUrl, deps) {
    const reasons = [];
    let served = false;
    try {
        const response = await deps.fetchImpl(baseUrl);
        served = response.status > 0;
    } catch {
        reasons.push(`nothing answers at ${baseUrl} — no dev server is running there`);
    }
    if (served) {
        let hasAdmin = false;
        try {
            hasAdmin = await deps.databaseHoldsSeededAdmin();
        } catch (error) {
            reasons.push(`the database could not be read: ${error instanceof Error ? error.message : String(error)}`);
        }
        if (!hasAdmin && reasons.length === 0) {
            reasons.push(
                'the database at that target does not contain this project\'s seeded bootstrap admin ' +
                    "(admin@example.com). A stack without it is not this project's dev database, and counting " +
                    'rows in somebody else\'s database is exactly what this guard exists to prevent.',
            );
        }
    }
    return reasons;
}

/** The real fetch, split out so the exported functions stay injectable. */
export async function fetchOk(url) {
    const response = await fetch(url, { redirect: 'manual' });
    return { ok: response.ok, status: response.status };
}
