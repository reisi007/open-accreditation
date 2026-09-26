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
 * The two values that make it decisive, both `process.env.CI`-gated like the
 * rest of the repo's Playwright config:
 *
 *   retries: 0        Fail once ⇒ failed. With the base config's `retries: 2`
 *                      a test that fails on attempt 1 and passes on the retry
 *                      is reported as passed/flaky and the job stays green —
 *                      precisely the signal this profile must surface. Do NOT
 *                      reintroduce retries "to unblock the nightly": a red
 *                      nightly means "a test is flaky or genuinely broken" and
 *                      is answered by fixing (or explicitly quarantining) that
 *                      test, never by retrying it into green.
 *   maxFailures: 1    Stop at the FIRST failure. The base config's
 *                      `maxFailures: 10` lets a run continue through nine more
 *                      failures and then burn CI minutes proving the rest of
 *                      the suite is broken too. One red test is the whole
 *                      report.
 *
 * Everything else (testDir, testMatch, projects, baseURL, timeout,
 * forbidOnly, globalTeardown, reporter, `use`) is inherited from
 * `playwright.config.ts` on purpose: this profile must run EXACTLY the same
 * tests as the base config, only with a decisive failure budget. Verify with
 * `pnpm exec playwright test -c playwright.regression.config.ts --list` —
 * the list must be identical to the base config's.
 *
 * `workers` is deliberately NOT raised here. ci.yml pins `--workers=1` for
 * this profile (the backend's per-IP login throttle, `RateLimiter::for('login')`
 * at 40/min in local/testing per AppServiceProvider, is the documented reason;
 * `CACHE_STORE=array` in the E2E job removed the 429 symptom but not the shared
 * CI IP), and parallelising now would inject self-inflicted load flakiness into
 * the run that was just made decisive — while the known DB-state accumulation
 * between specs (AGENTS.todo.md, WP-9-D4) is still open.
 */
export default defineConfig(base, {
    // Always 0 (CI and local): a first-attempt failure IS the result.
    retries: 0,
    // CI fail-fast on the first failure; local runs stay unlimited (0) so a
    // developer replicating the full suite locally sees the complete report.
    maxFailures: process.env.CI ? 1 : 0,
});
