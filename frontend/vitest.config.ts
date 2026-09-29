import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import lingui from '@lingui/vite-plugin';
import babel from '@rolldown/plugin-babel';
import { linguiTransformerBabelPreset } from '@lingui/vite-plugin';

export default defineConfig({
  plugins: [react(), lingui(), babel({ presets: [linguiTransformerBabelPreset()] })],
  test: {
    // Load reserve, NOT a per-test exception: under CPU oversubscription every test in a
    // file inflates 3-5x (measured 14→56 ms on 24 spinners/18 cores) while the heaviest
    // test costs 563 ms with an idle box. Uniform inflation across ALL tests — including
    // a 14 ms one — is the signature of CPU starvation, not a localised slow wait, so
    // there is no defect here to fix and the budget belongs on the whole run.
    //
    // 15000, raised from 10000 on 2026-09-27 because the reserve was measurably
    // under-provisioned, not guessed: 10000 held at load ~19.6 (2999 ms for the heaviest
    // test) but was EXCEEDED at load ~24.8 on the same code. This box carries a standing
    // background load of ~20 on 18 cores, so "quiet" is not the normal case here. An
    // earlier pass had already recorded 8933 ms at load 24-33 — 89% of a 10000 ms budget,
    // i.e. 1.12x headroom, which was flagged as too thin at the time. This is the event
    // that flag predicted, so the pre-registered step is what gets applied.
    //
    // This does NOT mask a defect: the test's intrinsic cost is ~563 ms, so a genuine
    // slow-wait regression would land at ~1-2 s isolated, an order of magnitude below this
    // ceiling, while a real hang still blows through 15 s fast and loudly.
    testTimeout: 15000,
    environment: 'jsdom',
    globals: false,
    // `scripts/**` as well as `src/**`: the i18n guard's classification logic
    // lives in scripts/po-catalog.mjs (plain ESM, so the guard itself needs no
    // build step) and is covered by a test next to it.
    //
    // `tests/**` as well, and for the same reason: the child-process driver's
    // decision functions (`isZombieState`, `isExecuting`, the PPID walk, the
    // sweep) are PURE or take their one impure input as an argument, but they
    // live under `tests/e2e/`, which Playwright collects and Vitest did not. A
    // rule reachable only by booting a browser and a backend has no test on a
    // developer machine — and the state alphabet on darwin (`? R S U`) never
    // contains the `Z` that rule exists for, so the gap was not even visible
    // locally. MEASURED before widening: `find tests -name '*.test.ts'` returned
    // nothing, so the pattern collects no pre-existing file.
    //
    // The two runners cannot collide: Playwright's `testMatch` is
    // `**/*.spec.ts`, so a `*.test.ts` under `tests/` is invisible to it.
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx', 'scripts/**/*.test.ts', 'tests/**/*.test.ts'],
    setupFiles: ['src/test-setup.tsx'],
    css: true,
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['**/*.test.*', '**/node_modules/**', '**/dist/**', 'src/test-setup.tsx', 'src/locales/**'],
      reporter: ['text', 'html'],
      reportsDirectory: 'coverage',
    },
  },
});
