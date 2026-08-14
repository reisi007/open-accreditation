# UI Review — Findings Report

Consolidate all vision-agent findings here. One row per finding; group by
screenshot batch (filled → empty, desktop → mobile). A verdict is only
APPROVED when no `critical` or `high` findings remain open.

## Template

```markdown
# UI Review Findings — <app> — <date>

Capture: `pnpm test:screenshots` · output: `test-results/ui-screenshots/`
Verdict: `APPROVED` | `CHANGES REQUIRED`

## Filled · Desktop

| Severity | File:Line | Screenshot | Finding | Suggested fix |
|----------|-----------|------------|---------|---------------|
|          |           |            |         |               |

## Filled · Mobile
...

## Empty · Desktop
...

## Empty · Mobile
...

## Open follow-ups
- ...
```

## Severity definitions

| Severity | Meaning |
|----------|---------|
| `critical` | Blank/broken page, content overlap or cut-off, hard functional blocker visible in the render. |
| `high` | Visible layout/contrast/hierarchy defect, empty-state regression, inconsistent spacing that degrades usability. |
| `medium` | Polish issue, minor alignment, suboptimal hierarchy. |
| `low` | Nitpick / stylistic preference. |

## Rules

- **`critical`/`high` block the verdict `APPROVED`** → verdict is `CHANGES REQUIRED`.
- Every finding must carry a `File:Line` for the fix (source file + line, not the
  screenshot) and the exact screenshot file name that exposed it.
- `File:Line` must point into the source (`File:Line` of the component/view), not
  into generated screenshots.
- Duplicate findings across states are one finding with both screenshots listed.
- Re-verify after fixes: re-capture the affected routes and diff old vs new.
