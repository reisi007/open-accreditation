import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint, { parser as tsParser } from 'typescript-eslint';
import pluginLingui from 'eslint-plugin-lingui';

export default tseslint.config(
  { ignores: ['dist', 'node_modules', 'coverage', 'playwright-report', 'test-results', 'src/locales', 'src/locales/**/*.messages.js', 'lingui.config.ts', 'playwright.config.ts', 'src/**/*.test.ts', 'src/**/*.test.tsx'] },
  pluginLingui.configs['flat/recommended'],
  {
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
    files: ['src/**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': [
        'error',
        { allowConstantExport: true },
      ],
      'lingui/no-expression-in-message': 'error',
      '@typescript-eslint/consistent-type-definitions': ['error', 'interface'],
      'lingui/t-call-in-function': 'off',
    },
  },
  {
    files: ['tests/e2e/**/*.ts'],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.node,
    },
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Property[key.name="force"][value.value=true]',
          message: 'Strict QA Enforcement: Do not use { force: true } in Playwright E2E tests. Fix the UI instead.'
        },
        {
          selector: 'CallExpression[callee.property.name="setViewportSize"]',
          message: 'Strict QA Enforcement: Do not use page.setViewportSize(). Use Playwright projects/devices in playwright.config.ts instead.'
        }
      ]
    },
  },
  {
    // The ui-review screenshot harness lives in `tests/screenshots` and is a
    // typed manifest + generic spec (TS syntax), so it needs the TS parser —
    // tests/e2e deliberately keeps plain-ES2020 files for the default parser.
    //
    // Two files under `tests/e2e/` are the exception, and for the same kind of
    // reason: a parameter annotation anywhere else in that directory is a PARSE
    // ERROR (measured: `Parsing error: Unexpected token :`), and JSDoc does not type
    // a parameter in a `.ts` file (measured: TS7006) — so the typed form needs the
    // parser. Both are listed explicitly rather than by a glob, so that the rest of
    // the directory keeps its plain-ES2020 rule:
    //
    //   `helpers/teams-enabled.ts` — takes an `APIRequestContext`. Keeping that
    //     switch in its own typed module is also what lets a vitest test drive it:
    //     `import type` is erased, so the module has no runtime dependency on
    //     `@playwright/test` (whose real import costs 121 s under Vitest, vs 526 ms
    //     in plain node). See that file's own docblock.
    //   `teams-precondition.test.ts` — its test of the above, which needs the same
    //     types plus `import type` for them.
    files: [
      'tests/screenshots/**/*.ts',
      'tests/e2e/helpers/teams-enabled.ts',
      'tests/e2e/teams-precondition.test.ts',
    ],
    languageOptions: {
      parser: tsParser,
      ecmaVersion: 2020,
      globals: globals.node,
    },
  }
);
