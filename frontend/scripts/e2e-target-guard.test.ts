import { describe, expect, it, vi } from 'vitest';
import {
    DevStackRefusal,
    devStackRefusals,
    isLoopbackHost,
    liveDevStackRefusals,
} from './e2e-target-guard.mjs';

/**
 * The guard is the only thing standing between a script that creates and deletes
 * hundreds of rows and a URL it inherited from the environment. These tests pin
 * the two halves of that promise: it REFUSES the targets that must be refused,
 * and it refuses them for the stated REASON — because a refusal with the wrong
 * reason is one nobody can act on.
 */

const NOT_A_LOCALHOST = 'https://e2e.example.internal';
const LOCALHOST = 'http://localhost:5173';

describe('isLoopbackHost', () => {
    it('accepts every spelling of this machine and nothing else', () => {
        for (const host of ['localhost', 'LOCALHOST', '127.0.0.1', '127.1.2.3', '::1', '[::1]', '0.0.0.0', 'empty.localhost']) {
            expect(isLoopbackHost(host), `${host} should be loopback`).toBe(true);
        }
        for (const host of [
            'e2e.example.internal',
            'staging.example.com',
            'localhost.evil.example',
            '127.0.0.1.evil.example',
            '10.0.0.1',
            '192.168.1.10',
            'localhost:5173',
            '',
        ]) {
            expect(isLoopbackHost(host), `${host} should NOT be loopback`).toBe(false);
        }
    });
});

describe('devStackRefusals', () => {
    it('refuses a NON-localhost target, and says that is why', () => {
        const reasons = devStackRefusals(NOT_A_LOCALHOST, 'local');
        expect(reasons).toHaveLength(1);
        expect(reasons[0]).toContain('e2e.example.internal');
        expect(reasons[0]).toContain('not a loopback host');
    });

    it('refuses a localhost target that was never acknowledged, naming the missing acknowledgement', () => {
        const reasons = devStackRefusals(LOCALHOST, undefined);
        expect(reasons).toHaveLength(1);
        expect(reasons[0]).toContain('E2E_LEAKS_TARGET');
        expect(reasons[0]).toContain('not "local"');
    });

    it('reports BOTH reasons at once, so one run tells the whole story', () => {
        const reasons = devStackRefusals(NOT_A_LOCALHOST, 'production');
        expect(reasons).toHaveLength(2);
        expect(reasons.join('\n')).toContain('not a loopback host');
        expect(reasons.join('\n')).toContain('E2E_LEAKS_TARGET');
    });

    it('refuses a value that merely CONTAINS the word local', () => {
        // A substring match here would be a foot-gun: `E2E_LEAKS_TARGET=not-local`
        // and `E2E_LEAKS_TARGET=localhost-but-shared` would both sail through.
        expect(devStackRefusals(LOCALHOST, 'not-local')).toHaveLength(1);
        expect(devStackRefusals(LOCALHOST, 'LOCAL')).toHaveLength(1);
    });

    it('refuses a target that is not a URL at all, and a non-http scheme', () => {
        expect(devStackRefusals('not a url', 'local')[0]).toContain('not a URL at all');
        expect(devStackRefusals('ftp://localhost/x', 'local')[0]).toContain('scheme');
    });

    it('passes a loopback target that carries the acknowledgement', () => {
        expect(devStackRefusals(LOCALHOST, 'local')).toEqual([]);
    });
});

describe('liveDevStackRefusals', () => {
    const seeded = () => Promise.resolve(true);

    it('refuses when nothing serves the target', async () => {
        const reasons = await liveDevStackRefusals(LOCALHOST, {
            fetchImpl: () => Promise.reject(new Error('ECONNREFUSED')),
            databaseHoldsSeededAdmin: seeded,
        });
        expect(reasons).toHaveLength(1);
        expect(reasons[0]).toContain('no dev server is running');
    });

    it("refuses when the server answers but the database is not this project's", async () => {
        const reasons = await liveDevStackRefusals(LOCALHOST, {
            fetchImpl: () => Promise.resolve({ ok: true, status: 200 }),
            databaseHoldsSeededAdmin: () => Promise.resolve(false),
        });
        expect(reasons).toHaveLength(1);
        expect(reasons[0]).toContain('admin@example.com');
    });

    it('refuses when the database cannot be read, and quotes the reason', async () => {
        const reasons = await liveDevStackRefusals(LOCALHOST, {
            fetchImpl: () => Promise.resolve({ ok: true, status: 200 }),
            databaseHoldsSeededAdmin: () => Promise.reject(new Error('no such container: accriditation_db')),
        });
        expect(reasons[0]).toContain('no such container');
    });

    it('passes when the server answers and the seeded admin is there', async () => {
        const reasons = await liveDevStackRefusals(LOCALHOST, {
            fetchImpl: () => Promise.resolve({ ok: true, status: 200 }),
            databaseHoldsSeededAdmin: seeded,
        });
        expect(reasons).toEqual([]);
    });

    it('does not query the database at all when nothing serves the target', async () => {
        // Ordering: the cheap network probe decides first, so a refused run costs
        // one failed request instead of a failed request PLUS a database exec.
        const probe = vi.fn(() => Promise.resolve(false));
        await liveDevStackRefusals(LOCALHOST, {
            fetchImpl: () => Promise.reject(new Error('ECONNREFUSED')),
            databaseHoldsSeededAdmin: probe,
        });
        expect(probe).not.toHaveBeenCalled();
    });
});

describe('DevStackRefusal', () => {
    it('says plainly that nothing was touched, and lists every reason', () => {
        const error = new DevStackRefusal(['first reason', 'second reason']);
        expect(error.name).toBe('DevStackRefusal');
        expect(error.reasons).toEqual(['first reason', 'second reason']);
        expect(error.message).toContain('first reason');
        expect(error.message).toContain('second reason');
        expect(error.message).toContain('It has not touched anything');
        expect(error.message).toContain('E2E_LEAKS_TARGET=local');
    });
});
