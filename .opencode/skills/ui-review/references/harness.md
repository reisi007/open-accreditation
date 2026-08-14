# Scaffolding the UI-review screenshot harness in any web project

Generic instructions to bring up the harness in a web project (React/Vue/… with
Playwright). Copyable starting points live in `references/templates/`.

## 1. Dedicated Playwright config

Create `playwright.screenshots.config.ts` next to the standard
`playwright.config.ts`. It must be a **separate** config so the screenshot set
never runs inside the normal E2E suite:

- `testDir` points at a dedicated folder (e.g. `./tests/screenshots`).
- Own `outputDir` for artifacts (e.g. `test-results/ui-screenshots`).
- Same browser projects as the standard config (desktop + mobile viewport) so
  the set reviews both form factors.
- `baseURL` pointing at the local dev server.
- **Do NOT** import or extend the standard config.

Template: `templates/playwright.screenshots.config.ts.example`

## 2. Route/state manifest

Create a typed manifest (e.g. `tests/screenshots/ui-review.config.ts`) exporting:

- A `UiReviewRoute` interface: `name`, `path` (may contain `:params`), `states`
  (`'filled' | 'empty'`), optional `auth` (`'guest' | 'admin' | 'user'`),
  optional `viewports` (`'desktop' | 'mobile'`), optional `note`, optional
  `seed` (resolves dynamic ids/credentials from seeded data at runtime) and
  optional `nav` steps describing how the spec reaches the route via UI clicks
  (or a justified direct-URL load).
- A `routes` array covering the app's page matrix.

The manifest is the single place to add/remove routes. The generic spec reads
it — adding a route is a manifest edit only.

Template: `templates/ui-review-manifest.ts.example`

## 3. Generic manifest-driven spec

Create `tests/screenshots/ui-screenshots.spec.ts`. For every route × state ×
viewport it:

1. seeds deterministic data (filled states) via the route's `seed`,
2. logs in through the UI when the route requires auth,
3. navigates via the route's `nav` steps (UI clicks; `page.goto` only for
   justified direct-URL/deep-link routes — document why in the manifest),
4. waits for the page to settle (network idle + key UI),
5. saves a full-page PNG at `<outputDir>/<state>/<viewport>/<name>.png`.

Screenshot path resolution: **`page.screenshot({ path })` resolves relative
paths against the process working directory, not the config `outputDir`** — build
the path with `path.resolve(process.cwd(), outputDir, state, viewport, name)`.

Tag every test (e.g. `{ tag: ['@screenshot'] }`) so it is groupable and clearly
separate from functional E2E tags.

Template: `templates/ui-screenshots.spec.ts.example`

## 4. npm script + gitignore

```jsonc
// package.json
"scripts": {
  "test:screenshots": "playwright test -c playwright.screenshots.config.ts"
}
```

Ensure the screenshot output dir is gitignored (the config's `outputDir` — e.g.
`test-results/`).

## 5. The "empty mandant" idea (generalized)

"Empty state" means a **fixture environment or tenant without data**, not an
empty database:

- Multi-tenant: create a dedicated fixture tenant (e.g. `empty` with domain
  `empty.localhost`) that resolves via the host/Referer, so public pages and
  admin pages render with zero data. A globally-scoped super-admin login works
  on that tenant without per-tenant user setup.
- Single-tenant: reset/blank the domain data the pages read (or point the dev
  environment at an empty fixture database).
- Where a truly empty environment is infeasible for a specific page, restrict
  that page to the filled state and document it in the manifest `note` — do not
  fake the empty state.

The fixture must be **idempotent** (find-or-create by slug) so parallel runs and
re-runs are safe, and must **never touch** the primary tenant's data.

## Reference templates

- `templates/playwright.screenshots.config.ts.example`
- `templates/ui-review-manifest.ts.example`
- `templates/ui-screenshots.spec.ts.example`
