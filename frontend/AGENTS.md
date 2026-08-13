# AGENTS.md — Frontend (React Vite SPA)

Module-scoped operating guidelines for the React frontend in `frontend/`.

Global rules (Definition of Done, AI workflow & TODO management, E2E tag policy, agent roles, security risk register) live in the repo root `AGENTS.md` and apply here as well. This file only covers what is specific to the frontend module.

## Stack

- React 19 + Vite (TypeScript strict, `noUnusedLocals`) + Tailwind CSS v4 + daisyUI v5
- React Router v7, SWR, react-hook-form + zod (`@hookform/resolvers/zod`), Lingui (i18n, UI strings German, DE + EN)
- Vitest (unit tests) + Playwright (E2E)

## Commands

Frontend Unit tests (pnpm, NICHT npm):

```bash
cd frontend && pnpm run test:run
```

Frontend Lint + Build (pnpm, NICHT npm; `build` runs `tsc -b && node scripts/check-i18n.mjs` via prebuild, then `vite build`):

```bash
cd frontend && pnpm lint:fix && pnpm build
```

E2E (Playwright, baseURL `http://localhost:5173`):

- Full suite, nur vor Deployment: `cd frontend && npx playwright test`
- Nur @smoke, nach jedem Code-Change: `cd frontend && npx playwright test --grep @smoke`
- Nur spezifisches Feature, z. B. accreditation: `cd frontend && npx playwright test --grep @feature:accreditation`
- Nur fehlgeschlagene wiederholen: `cd frontend && npx playwright test --last-failed`

E2E Workflow:

1. Nach jedem Code-Change: `pnpm test:e2e:smoke`
2. Feature-spezifisch: `npx playwright test --grep @feature:<name>`
3. Nur vor Deployment: `npx playwright test` (full suite)
4. Flaky Tests in AGENTS.todo.md dokumentieren (Datei + Testname, Fehlerursache, `flaky` tag im Commit/PR)

Bug-Fixing: Bei fehlschlagenden E2E-Tests `npx playwright test --last-failed` wiederholt ausführen, bis alle grün sind.

## React Compiler (STRICT)

React Compiler is enabled in `vite.config.ts` via `reactCompilerPreset` passed to `@rolldown/plugin-babel`. Order matters: the Lingui macro preset is listed **second** because Babel runs presets in reverse order, so Lingui expands macros *before* the compiler runs:

```ts
babel({presets: [reactCompilerPreset(), linguiTransformerBabelPreset()]})
```

Because the compiler performs automatic memoization:

- `useMemo`, `useCallback`, `React.memo`, and `forwardRef` are **antipatterns — do not use them**.
- Write plain functions/values and let the compiler memoize.
- Effects whose deps no longer contain a manual callback may re-run more often — that is intended compiler behavior. Do not add `useCallback` back.
- The compiler bails safely (leaves code uncompiled) on unsupported constructs: try/catch around value blocks, throw inside try/catch, try/finally, mutation of module-scope variables, ref access during render. Keep such logic in module-level helper functions (or in effect bodies).
- If a genuinely problematic infinite loop appears, restructure minimally (e.g. the ref-delegation pattern: sync a `useRef` with the unstable function, use the ref inside the effect).

## STRICT Frontend Rules

### useEffect & Derived State Policy (STRICT)
Forbid the use of `useEffect` for side effects triggered by user events (e.g. creating object URLs). Handlers MUST perform these actions. Forbid the use of `useState` for values that can be derived during rendering.

### Tailwind JIT Policy (STRICT)
Dynamische String-Konkatenation für Tailwind-Klassen (z.B. `btn-${color}`) ist **strikt verboten**, da der JIT-Compiler diese beim Build-Prozess übersieht und restlos entfernt (Purge). Klassen müssen immer vollständig und statisch ausgeschrieben werden (z.B. per explizitem Mapping-Objekt oder Ternary-Operator).

### Tailwind-Only Policy (STRICT)
Das `style`-Attribut ist **strikt verboten** – mit Ausnahme von dynamischen Werten, die sich zur Laufzeit ändern (z. B. berechnete Breiten/Höhen aus Benutzereingaben, animierte Werte). Statische Layout-Werte (insb. vh/vw/dvh/dvw-basierte Größen) MÜSSEN via Tailwind-Klassen gelöst werden. Werte in eckigen Klammern (JIT-Bracket-Syntax wie `w-[30%]`, `text-[10px]`) bleiben **strikt verboten** — außer bei Iconify-Icons. Tailwind 4 bietet native Fraktionen (`w-3/10`, `w-1/5`), Spacing-Werte (`max-w-xs`, `text-xs`, `h-80`) und `dvh`-Utilities (`h-dvh`, `max-h-dvh`). Reichen diese nicht aus, ist eine Erweiterung der Tailwind-Konfiguration (z. B. via `@utility` in `index.css`) dem Inline-Style vorzuziehen.

### Validation (Zod) Policy (STRICT)
* Alle `react-hook-form` Implementierungen MÜSSEN `@hookform/resolvers/zod` nutzen.
* Daten aus unsicheren, lokalen Quellen (wie `localStorage`) MÜSSEN via Zod geparst werden (`safeParse` oder `catch`), bevor sie in den State übernommen werden.

### ESLint & TypeScript (STRICT)
The use of `eslint-disable`, `@ts-ignore`, or `any` is **strictly forbidden**. All typing issues must be resolved structurally using exact interfaces, `unknown`, or generic type constraints. ESLint runs with `--max-warnings 0` — unused imports and unused locals are errors.

### Semantic Locator Scoping
Agenten MÜSSEN Playwright-Locators über Landmarks (`main`, `aside`, `footer`) scopen, um Eindeutigkeit sicherzustellen und Abhängigkeiten von rein visuellen CSS-Klassen zu minimieren.

### No `page.goto` for SPA Navigation (STRICT)
`page.goto()` ist ein Anti-Pattern und darf nicht für SPA-Navigation verwendet werden. Ausnahmen:
* Externe Links (Invite, Magic-Link, Setup-Link)
* Initialer Seitenaufruf bei Gästen (`/`)
* Route-Guard-Tests (direkter URL-Zugriff testen)
Navigation MUSS via UI-Klicks oder API-Aufrufe erfolgen.

### localStorage Injection (STRICT ANTI-PATTERN)
Daten via `page.evaluate()` oder `addInitScript` in `localStorage` zu injizieren ist verboten. localStorage ist ein Implementierungsdetail des Frontends. Tests MÜSSEN den User-Flow abbilden (Login → Navigation → Formular-Interaktion).

### Field Label Policy (STRICT)
* Pflichtfelder MÜSSEN das `required`-HTML-Attribut tragen — der Star (`*`) wird automatisch via CSS angehängt (`.form-control:has(input[required], select[required], textarea[required]) .label-text::after`).
* `(Optional)` oder `(optional)` in Labels ist **strikt verboten**. Optionale Felder werden schlicht ohne Zusatz gekennzeichnet.
* Die CSS-Regel in `index.css` ist der zentrale Mechanismus und darf nicht umgangen werden.

### Lingui / i18n — No Module-Scope `t` (STRICT)
Das `t`-Makro MUSS innerhalb von Funktions- oder Render-Bodies aufgerufen werden. **Auf Modulebene ist es strikt verboten** (z. B. `const schema = z.object({ ... t\`...\` ... })`, `const msg = t\`...\``).

Grund: Im **Produktions-Bundle** werden statisch importierte Shell-Chunks vor dem `i18n.activate("de")` in `I18nProvider.tsx` evaluiert. Ein module-scope `t` crasht dort mit `Lingui: Attempted to call a translation function without setting a locale` und die Seite bleibt leer (blank). Im Dev-Server (native ESM) tritt der Fehler NICHT auf — reproduzierbar nur via `pnpm build` + `pnpm preview`.

Regel bei Schemas in Shell-Komponenten (statisch von `App` erreichbar):
- Schema als **Factory-Funktion** anlegen und im Component-Body aufrufen:
  ```tsx
  const createLoginSchema = () => z.object({ email: z.string().email(t`...`), password: z.string().min(1, t`...`) });
  type LoginFormValues = z.infer<ReturnType<typeof createLoginSchema>>;
  // im Component: const loginSchema = createLoginSchema();
  ```
- Neue i18n-Strings MÜSSEN katalogisiert werden: `pnpm lingui:extract && pnpm lingui:compile` (Guard: `pnpm check:i18n`, läuft automatisch im `prebuild`).
