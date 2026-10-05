import { defineConfig } from '@playwright/test';
import process from 'node:process';

import base from './playwright.config.ts';

/**
 * Nightly regression profile — the DECISIVE E2E gate.
 *
 * Why a separate config instead of a CLI flag on `playwright.config.ts`:
 * `retries` and `maxFailures` are RUN PROFILES, not one global setting. Two
 * gates with opposite jobs share the same suite:
 *
 *   * `@smoke` on push/PR (`playwright.config.ts`): must stay FORGIVING.
 *     A shared GitHub runner has genuine network/timing noise, so the base
 *     config keeps `retries: 2` / `maxFailures: 10` for CI. Do not lower them.
 *   * the scheduled full suite (this config): must be DECISIVE, because its
 *     only job is to DETECT flakiness. A gate that hides a first-attempt
 *     failure cannot detect anything.
 *
 * The two values that make it decisive — note that they are NOT both
 * `process.env.CI`-gated:
 *
 *   retries: 0        Fail once ⇒ failed. UNCONDITIONAL (CI *and* local): a
 *                      developer replicating the nightly locally must see the
 *                      same first-attempt result the CI gate sees, or the local
 *                      reproduction is worthless. With the base config's
 *                      `retries: 2` a test that fails on attempt 1 and passes on
 *                      the retry is reported as passed/flaky and the job stays
 *                      green — precisely the signal this profile must surface.
 *                      Do NOT reintroduce retries "to unblock the nightly": a red
 *                      nightly means "a test is flaky or genuinely broken" and
 *                      is answered by fixing (or explicitly quarantining) that
 *                      test, never by retrying it into green.
 *   maxFailures: 1    Stop at the FIRST failure — and this one IS
 *   (CI) / 0 (local)  `process.env.CI`-gated, like the rest of the repo's
 *                      Playwright config: CI must not burn minutes proving the
 *                      rest of the suite is broken too, while a local full run
 *                      should show the complete report. The base config's
 *                      `maxFailures: 10` does neither well. One red test is the
 *                      whole CI report.
 *
 * Everything else (testDir, testMatch, projects, baseURL, timeout,
 * forbidOnly, globalTeardown, reporter, `use`) is inherited from
 * `playwright.config.ts` on purpose: this profile must run EXACTLY the same
 * tests as the base config, only with a decisive failure budget. Verify with
 * `pnpm exec playwright test -c playwright.regression.config.ts --list` —
 * the list must be identical to the base config's.
 *
 * `workers` is NOT decided in this file: the profile inherits the base
 * config's `workers: process.env.CI ? 4 : 8` (`playwright.config.ts:27`).
 * The `--workers=4` on the strict branch in ci.yml only ECHOES that base
 * value; the two FORGIVING branches are the ones that actually PIN a value,
 * by lowering it to `--workers=1`. Whoever audits the worker count belongs in
 * `playwright.config.ts:27` and then in ci.yml's run step — not here.
 * The backend's per-IP login throttle (`RateLimiter::for('login')` at 40/min
 * in local/testing per AppServiceProvider) was the documented reason for those
 * pins; `CACHE_STORE=array` in the E2E job removed the 429 symptom but not the
 * shared CI IP. The strict branch's parallel measurement run came out GREEN
 * (2026-10-05, board decision), so `--workers=4` there STAYS; the known
 * DB-state accumulation between specs (AGENTS.todo.md, WP-9-D4) is still open.
 * A red strict run is therefore classified, not blindly re-pinned: mutex
 * (named lock) or rate (Position 49). That classification has a THRESHOLD — a
 * red test caused by TIMEOUT / a slow test is NEITHER rate nor mutex (see the
 * vCPU-oversubscription caveat in the ci.yml run step) and must not be filed
 * as a collision.
 */
export default defineConfig(base, {
    // Always 0 (CI and local): a first-attempt failure IS the result.
    retries: 0,
    // CI fail-fast on the first failure; local runs stay unlimited (0) so a
    // developer replicating the full suite locally sees the complete report.
    maxFailures: process.env.CI ? 1 : 0,
});
