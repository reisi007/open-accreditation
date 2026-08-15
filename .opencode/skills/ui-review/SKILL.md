---
name: UI Review
description: Generate Playwright screenshots of every page (empty/filled states), analyze them with the vision agent, and drive repeatable UI improvements. TRIGGER on UI review/improvement/screenshot requests or when the user wants to check the visual state of a web app.
---

# UI Review — repeatable visual QA loop

This skill turns a web app's visual state into a repeatable, reviewable artifact:
Playwright captures screenshots of every page in both **filled** and **empty**
states, a vision-capable agent analyzes them against a checklist, and the
resulting findings drive targeted UI fixes.

Each route/state/viewport combo produces a **full-page PNG** **plus viewport-
height SECTION captures** (`<name>-secN.png`) that scroll the whole page in
80 % steps. Long pages must not rely on the full-page PNG alone: a full-page
image of a page >2000 px tall gets downscaled for the vision model and regions
below the fold become unreadable (regression: a clipped badge in a table far
down the page). Sections keep every region at readable resolution. Apps that
scroll in an inner overflow container (100vh layout with `<main …overflow-auto>`)
are handled by scrolling that container — see `references/harness.md`.

The screenshot set is **intentionally separate from the normal E2E suite**: it is
not a functional test. It never asserts behavior — it only *captures pixels* so a
human or the vision agent can judge the design. Nothing here gates CI.

## Prereqs

- The project must run locally (dev server + backend/API up).
- The project must have the **screenshot harness**: a dedicated
  `playwright.screenshots.config.ts`, a route/state **manifest**, and a generic
  manifest-driven **spec**.
- If the harness is missing, scaffold it first — see
  `references/harness.md` and the copyable templates in `references/templates/`.
- A vision-capable agent must be available (the repo's `vision` subagent).

## Step 1 — Capture

Run the screenshot set with the project's script (this repo):

```bash
cd frontend && pnpm test:screenshots
```

Equivalent in other projects: `playwright test -c playwright.screenshots.config.ts`
(or the configured `test:screenshots` npm script). The dedicated config means the
standard suite (`playwright test`) stays untouched — the screenshot set never
runs in CI's normal E2E pipeline.

PNGs land under the config's `outputDir` (this repo:
`frontend/test-results/ui-screenshots/`), organized as:

```
<outputDir>/<state>/<viewport>/<name>.png        # full-page
<outputDir>/<state>/<viewport>/<name>-secN.png   # viewport-height sections (N = 0,1,2,…)
  filled/desktop/home.png
  filled/desktop/home-sec0.png
  filled/desktop/home-sec1.png
  empty/desktop/home.png
  filled/mobile/admin-users.png
  ...
```

Long pages produce **multiple** `-secN` files; a short page only `-sec0`. The
manifest (`tests/screenshots/ui-review.config.ts` here) defines which routes ×
states × viewports are captured. A failed screenshot test means the harness (or
the page) is broken — fix it before moving on.

## Step 2 — Vision analysis

Read the generated PNGs from disk and hand them to the vision agent. The vision
agent is **limited to ~10 images per call** — always split into batches:

1. **By state** first: analyze all `filled` screenshots, then all `empty`.
2. **By viewport** within each state: desktop batch, then mobile batch.
3. **By route** when a batch is still too large: chunk routes alphabetically.

For each batch, ask the vision agent to:

- Run the checklist in `references/ui-review-checklist.md` against every image.
- Report findings per image, referencing the file name.

## Step 3 — Findings report

Consolidate every batch into a single findings report using the template in
`references/findings-report.md` (columns `Severity | File:Line | Screenshot |
Finding | Suggested fix`). Assign each finding a severity:

- `critical` — broken/blank page, content overlap or cut-off, hard functional
  blocker visible in the render.
- `high` — visible layout/contrast/hierarchy defect, empty-state regression,
  inconsistent spacing that degrades usability.
- `medium` — polish issues, minor alignment, suboptimal hierarchy.
- `low` — nitpicks, stylistic preferences.

`critical` and `high` findings **block** an APPROVED verdict for a page.

## Step 4 — Fix & verify

Delegate fixes following the repo's `AGENTS.md` delegation rules (implementer +
separate verifier). After a change:

1. Re-run the screenshot set **only for the affected routes** — either via
   Playwright's `--grep`/`-g` filter (each test is tagged `@screenshot` and
   named after the route) or a manifest edit.
2. Let the vision agent compare **old vs new** screenshots (diff) for the
   affected routes and confirm the finding is resolved (or a NEW issue appeared).
3. Re-verify the whole affected batch before closing the loop.

## Adding routes / states

Edit the manifest (`tests/screenshots/ui-review.config.ts`): add one entry per
route to the `routes` array. Each entry declares the route pattern, the states
(`filled`/`empty`), the required auth, the viewports, and — for dynamic params —
a `seeds` map (per state) that resolves real ids/credentials from the seeded
data at runtime. The generic spec picks the new route up automatically; nothing
else changes. Keep login-bearing seeds per-worker-cached (see
`references/harness.md`) so the backend login throttle is not exhausted.

### The Caddy-per-mandant static-file fallback idea

This app renders mandant branding from `frontend/public/brands/`. The long-term
goal is a web-server layer (Caddy) that overrides those static brand assets per
mandant host (Caddy serves the per-mandant file if present), while the React
files in `frontend/public/` remain the **fallback**. The `ui-review` harness
dovetails with this: the "empty mandant" tenant (see `references/harness.md`)
is also the natural fixture for verifying the brand fallback — capture the empty
tenant's pages and confirm the fallback brand shows where no override exists.

## Reference files

- `references/ui-review-checklist.md` — the checklist the vision agent applies.
- `references/findings-report.md` — the findings report template + severities.
- `references/harness.md` — how to scaffold the harness in any web project.
- `references/templates/` — copyable starting points for the harness.
