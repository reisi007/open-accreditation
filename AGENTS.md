# AI Operating Guidelines & Doc-as-Code Policy — open-accreditation

**Projekt:** Moderne, mandantenfähige Akkreditierungs-Plattform (Multi-Tenant) für Sportverbände
und Vereine. Nachbau des Feature-Umfangs von Sportdata „Accreditation Services" (set.sportdata.org)
mit eigenem Stack: Super Admin verwaltet Mandanten (Verbände), optional Teams (Vereine), Kategorien
(z. B. Presse), Events (Spiele), Selbstanmeldung, Freigabe-Workflow, Ausweis-Druck (PDF), QR-Verifikation,
PKPASS-Wallets, Park-/Sitzkarten als Sub-Akkreditierungen.

**CRITICAL ROLE:** Behandle den Benutzer bei allen Antworten und technischen Entscheidungen vom
Fachwissen her wie einen Senior Architekten. Die direkte Anrede „Senior Architekt" ist untersagt.

## 1. Sprachregeln

- **Language Policy:** Code & Docs: English. UI: German (DE + EN, Lingui).
- **Gemischte Sprache in Doku/Configs erlaubt** (deutsche Fachbegriffe wie „Mandant", „Verein",
  „Frist", „generieren" bleiben).

## 2. Stack (verbindlich)

- **Backend:** Laravel 13 + PHP 8.5. **Postgres** in Dev/Prod (docker-compose), **SQLite `:memory:`**
  für PHPUnit-Tests (wie Portal). phpunit + paratest. JWT (php-open-source-saver/jwt-auth), httpOnly-Cookie.
- **Frontend:** React 19 + Vite + TypeScript strict (`noUnusedLocals`), Tailwind CSS v4 + daisyUI v5,
  React Router v7, SWR, react-hook-form + zod, Lingui (i18n), Vitest + Playwright. React Compiler aktiv
  (`useMemo`/`useCallback`/`React.memo`/`forwardRef` sind Antipatterns).
- **Dependencies:** neueste Versionen aus dem Portal-Projekt (`portal.reisinger.pictures`).
- **Postgres-Regel (CRITICAL):** Schema/Queries müssen **portabel** bleiben, damit die SQLite-Testsuite
  läuft: kein PG-spezifisches SQL in Migrationen/Queries, JSON-Spalten via Laravel `json`-Type (kein
  `jsonb`-roh), Datumsarithmetik über Query-Builder/Eloquent statt roher PG-Funktionen. Wo Postgres-
  Features nötig sind → Service-Abstraktion + separater Integrationstest (dokumentieren).

## 3. Definition of Done (DoD)

Ein Task gilt nur dann als **abgeschlossen**, wenn BEIDE Kriterien erfüllt sind:

**1. Tests existieren**

- Backend-Änderungen (Controller, Services, Modelle, Gates, Middleware): → **PHPUnit Feature/Unit Tests**
- **Allocation-/Freigabe-Logik (Quota, FCFS, Blacklist, VIP, Sub-Akkreditierungen): → eigene, ausführliche
  PHPUnit-Tests (Kernanforderung, STRICT).**
- Frontend-Logik (Hooks, Utils, API-Layer): → **Vitest Unit Tests**
- Frontend-UI/Komponenten (Views, Modals, Formulare): → **Playwright E2E Tests**
- Bug-Fixes: → **mindestens ein Regression-Test**, der den Bug reproduziert (PHPUnit oder E2E)
- Refactoring / Dead-Code-Removal: → kann ohne Tests auskommen, muss im Commit begründet werden

**2. Codequalität ist gut**

- Frontend: `pnpm lint:fix && pnpm build` (oder `tsc -b`) läuft fehlerfrei
- Backend: `php artisan test` (alle bestehenden Tests grün)
- Keine `eslint-disable`, `@ts-ignore` oder `any`
- Keine blinden `.replace()`-Patches (Safe-Patching-Policy, §6)

## 4. Dokumentation & Task-Management

- **`features/`** = **dauerhafter SOLL-Zustand** des Systems. Hier landen nur Architekturentscheidungen,
  Datenmodelle, API-Verträge und Feature-Spezifikationen, die langfristig gültig sind.
- **`AGENTS.todo.md`** = **temporäre Task-Liste** + Code-Review-Notizen + Bug-Analysen + Session-Tracking.
  Alles, was nur für die aktuelle Session oder den nächsten PR relevant ist, gehört hierher, **nicht** in
  `features/`. Es enthält **nur die offenen Punkte** (aktueller Plan).
- **Task & Test Tracking:** Every feature requires actionable TODOs in `AGENTS.todo.md`. You MUST
  explicitly include TODOs for writing test cases (PHPUnit backend, Vitest, Playwright E2E).
- **Completed-TODO Cleanup (STRICT):** Abgeschlossene **und** verifizierte TODOs (Implementierung + Tests
  grün + Verifikator-Approval) werden aus `AGENTS.todo.md` **vollständig entfernt** — nicht abgehakt
  stehengelassen und **nicht** in einen eigenen „Completed"/„Erledigt"-Bereich verschoben. Befunde/Erkenntnisse
  aus der Verifikation wandern als offene Follow-ups oder Notizen in
  `AGENTS.todo.md` bzw. dauerhaft gültige Entscheidungen in `features/`. Die Bereinigung führt ein
  **Subagent** aus (Build-Agent muss sie nicht selbst übernehmen).
- **Zero Pre-existing Failures Policy (STRICT):** Pre-existing Test-Failures (PHPUnit, Vitest, Playwright)
  MÜSSEN immer behoben werden, bevor neue Arbeit beginnt. Ein „pre-existing" Label ist nicht erlaubt —
  jeder Fehlerblock wird analysiert und gefixt, oder als akzeptiertes Risiko in `features/` dokumentiert.

## 5. Agent-Rollen & Delegation (STRICT)

- **Build-Agent (STRICT):** Ein Build-Agent ist **ausschließlich Orchestrator**. Er darf **bis auf kleine
  Edits** (Korrektur von Tippfehlern, Policy-Anpassungen in `AGENTS.md`/`AGENTS.todo.md` selbst) nur
  `AGENTS.todo.md` und `AGENTS.md` (sowie direkt dort referenzierte Dateien) lesen und bearbeiten. Jede
  weitere Datei (Code, Tests, Templates, Komponenten) ist tabu — diese MÜSSEN an Subagenten delegiert
  werden. Seine Aufgabe ist:
  1. Anforderungen analysieren und in `AGENTS.todo.md` als actionable TODOs dokumentieren.
  2. Umsetzungen an Subagenten (Implementer) **delegieren** — der Build-Agent schreibt selbst keinen Code.
  3. Sofern fachlich sinnvoll **parallel delegieren** (unabhängige Tasks gleichzeitig an mehrere
     Implementer) — für den Koordinations-/Token-Footprint prüfen.
  4. Jede Umsetzung von einem **separaten Subagenten verifizieren** lassen (Review, Tests, Build) — der
     Verifikator ist NIE der Implementer desselben Tasks.
  5. Bei visuellen Prüfungen (Layout, Screenshots, Bilder, Screenshots-Analyse) den **`vision`-Subagenten**
     nutzen.
  - **Operational Discipline (bewährt):** (a) Konsolidierung grundsätzlich auf dem **main**-Branch — KEINE per-Task `fix/*`-Branches (verursachten Branch-Churn: Commits auf falschen Branches, Cross-Kontamination der Fixes, verlorene `AGENTS.todo.md`-Edits, ungewollte Stashes). (b) Parallelität auf **max. 2 Subagenten** begrenzen und nur bei disjunkten Ziel-Dateien; sonst sequenziell. (c) In einem geteilten Working-Tree dürfen Subagenten **nie `git add -A`** verwenden — immer explizite Pfade (`git add <datei1> <datei2>`), sonst werden fremde in-flight-Änderungen in den Commit gezogen. (d) Nach Fertigstellung: auf main mergen/cherry-picken, Tests verifizieren, Scratch-Branches + Stashes aufräumen. (e) **Restart-Regel:** Kommt ein Implementer-Subagent mit einem Fehler/abgebrochen zurück → mit `sessionID` + Prompt „continue" neu starten (Kontext wiederaufnehmen), nicht von vorne beginnen; danach erneut separat verifizieren lassen. (f) **CI-Grün hat immer Priorität:** Ist CI nach einem Push rot (oder bricht lokal eine Suite/der Build), hat dessen Fix **Vorrang vor aller neuen Arbeit** — sofort analysieren, isoliert fixen, grün pushen, erst dann neue Tasks fortsetzen.
- **Implementer (Subagent):** läuft in frischem, isoliertem Kontext; erhält präzise Anweisungen +
  Ziel-Dateien; setzt um und erzeugt Tests.
- **Verifikator (Subagent):** separat vom Implementer; führt Tests/Lint/Build aus und prüft den Diff. Die Verifikation umfasst ZUSÄTZLICH:
  1. **Architektur-Review:** Datenmodell-/Service-Grenzen konsistent, Mandanten-Isolation durchgängig (kein Cross-Mandant-Leak), Erweiterbarkeit für spätere Phasen (P2–P6), Einhaltung der Portabilitätsregel (Postgres-Dev vs. SQLite-Tests, §2).
  2. **Security-Review:** keine Secrets/Keys committed, keine offenen Admin-/Debug-Routen in Prod, Auth-/Autorisierungs-Lücken (IDOR, fehlende Gates/Policies), Input-Validierung/Sanitize (Symfony `HtmlSanitizer` / DOMPurify), JWT-httpOnly-Cookie-Konfiguration, Rate-Limit wo nötig, sichere File-Delivery (auth-gated).
  3. **Befunde-Bericht:** je Befund `Datei:Zeile` + Schweregrad `critical/high/medium/low`. `critical`/`high` blockieren das Verdict `APPROVED` (→ `CHANGES REQUIRED`).
- **Build-Agent (Verifikations-Gate):** akzeptiert ein Verdict nur mit vollständigem Befunde-Bericht; `critical`/`high`-Befunde werden als eigene fix-Todos delegiert und erneut verifiziert.

## 6. AI Operating Rules (STRICT)

- **ESLint Auto-Fix Policy (STRICT):** Always use `npm run lint:fix` (= `eslint . --fix`) instead of plain
  `npm run lint`. Auto-fix handles formatting and trivial rules — never fix those by hand. The plain `lint`
  script (without `--fix`) is reserved for CI/PR checks only.
- **Test Debugging Transparency:** When analyzing test failure reports, explicitly document debugging
  progress and thought process before proposing a fix.
- **Patching & File Modification (CRITICAL):**
  - Multi-line Regex for search-and-replace in code is STRICTLY FORBIDDEN.
  - Base64 output for file content is STRICTLY FORBIDDEN.
  - **Safe Patching Policy (CRITICAL):** Alle `patch.mjs` Scripts MÜSSEN den Erfolg einer Ersetzung
    validieren (`includes()`/`indexOf()` vor `.replace()`, danach Diff prüfen, `console.error` + Abbruch
    bei Leerlauf). Blinde `.replace()` Aufrufe sind untersagt!

## 7. Testing & E2E (STRICT)

**Tag Policy** — E2E tests MUST be tagged via Playwright `{tag: [...]}`:

- `@smoke` — Critical path (login, guest, auth, basic CRUD). Run after every code change.
- `@regression` — Full functional coverage. Run before deployment.
- `@feature:<name>` — Feature-spezifisch (z. B. `@feature:accreditation`, `@feature:admin:mandant`).
- `@mobile` — mobile-only gestures/responsive.
- **New E2E tests MUST include at least one tag.**

**Execution:**

- Bei jedem Code-Change: `test:e2e:smoke` (`npx playwright test --grep @smoke`)
- Feature-spezifisch: `npx playwright test --grep @feature:<name>`
- Vor Deployment: `test:e2e` (full suite)
- Wiederholung fehlgeschlagener Tests: `npx playwright test --last-failed`

**Workflow-Reihenfolge für Test-Fixes:** (1) SOLL in `features/` dokumentieren → (2) Backend-Tests
(`php artisan test --filter`) → (3) Frontend-Unit-Tests (`pnpm vitest run`) → (4) erst danach E2E.

**Max 3 Fix-Versuche für Tests (STRICT):** Nach 3 erfolglosen Versuchen MUSS der Agent an den Benutzer
zurückgeben mit einer Analyse. Keine Endlos-Fix-Loops.

**Visuelle Verifikation / UI-Review (Design-QA, STRICT):** Permanent verpflichtender Workflow nach jeder
UI-Änderung (FE-Etappen, neue Seiten, Layout-/daisyUI-Anpassungen). Er ist **explizit getrennt** von den
funktionalen Playwright-E2E-Tests: eigener Ordner `frontend/tests/screenshots/` (Route-Manifest =
`ui-review.config.ts`, Quelle der Wahrheit für Routes × States × Viewports; generischer Spec
`ui-screenshots.spec.ts`, alle Tests mit Tag `@screenshot`), eigene Config
`playwright.screenshots.config.ts` (outputDir `test-results/ui-screenshots`; Desktop Chrome 1920×950 +
Mobile Chrome/Galaxy A55; fix 2 Worker wegen Backend-Login-Throttle). Ausführen NUR via
`cd frontend && pnpm test:screenshots` (= `playwright test -c playwright.screenshots.config.ts`) — läuft
**nicht** in der Standard-E2E-Suite (`playwright.config.ts` / `tests/e2e`) und **nicht** im CI-E2E-Job.

Loop (Schritte 1–4):

1. **Capture:** Dev-Server + Backend starten → `cd frontend && pnpm test:screenshots`. Pro Route × State
   (`filled`/`empty`) × Viewport entstehen ein **Full-Page-PNG** plus **Section-Captures**
   (`<name>-secN.png`, Scroll in 80-%-Schritten, damit unterhalb des Folds nichts unlesbar skaliert):
   `frontend/test-results/ui-screenshots/<state>/<viewport>/<name>.png`. Schlägt ein Screenshot-Test fehl,
   ist Harness oder Seite kaputt — zuerst fixen.
2. **Vision-Analyse:** Die PNG-Pfade werden dem **`vision`-Subagenten** übergeben (§5: visuelle Prüfungen
   immer via vision-Subagent). Max. **10 Bilder pro Batch**; Batching-Reihenfolge: erst nach State
   (`filled` → `empty`), dann Viewport (desktop → mobile). Geprüft wird gegen die Checklist des
   **UI-Review-Skills**
   (`/Users/florianreisinger/dev/agents-skills/.agents/skills/ui-review/references/ui-review-checklist.md`).
3. **Findings-Report:** Konsolidierter Bericht je Befund `Severity | Screenshot | Finding | Suggested fix`
   (Template im Skill: `references/findings-report.md`); Severities `critical/high/medium/low`;
   **`critical`/`high` blockieren das Verdict `APPROVED`** (→ eigene Fix-Todos, wie §5 Verifikations-Gate).
4. **Fix-Loop:** Fixes delegieren (Implementer + separater Verifikator, §5) → **Re-Capture nur der
   betroffenen Routen** (`cd frontend && pnpm test:screenshots -g <routenname>`) → `vision`-Subagent
   vergleicht **old vs new** und bestätigt die Behebung bzw. meldet neue Befunde; gesamten betroffenen
   Batch re-verifizieren, bevor der Loop geschlossen wird.

**Abgrenzung (STRICT):** Dieser visuelle Loop ist **kein funktionaler Test** — er asserted kein Verhalten,
sondern erzeugt ausschließlich Pixel zur Design-QA durch den vision-Subagenten. Er gate **nicht** CI oder
Deployment; funktional verbindlich bleiben ausschließlich die E2E-Suiten dieses §7.

## 8. Domain-Modell (Kurzreferenz)

Hierarchie: **Super Admin → Mandant (Verband, eigene Domain) → Team (Verein, optional je Mandant) →
Kategorie (erbt vom Mandant, Team überschreibt) → Akkreditierung (Quota + Frist) → Application
(Status requested/approved/denied/blacklisted).** Details/SOLL in `features/`. Personen: **pro Mandant
eigenes Konto** (eigene Domain). Rollen: super_admin, mandant_admin, team_admin, user, verifier (Ordner).

## 9. Modules

Module-spezifische Regeln in per-module `AGENTS.md`:

- **`frontend/AGENTS.md`** — React Vite SPA: React Compiler Policy, Tailwind JIT/Only, Zod, ESLint/TS,
  Lingui (no module-scope `t`), Semantic Locator Scoping, no `page.goto`, localStorage-Injection-Verbot,
  Field-Label-Policy.
- **`backend/AGENTS.md`** — Laravel: Test-Kommando, DB-Setup + Migration-Policy (immer seeden),
  SQLite `:memory:`-Tests, Postgres-Dev, paratest-Konkurrenzregel.

## 10. Security Risk Register (Accepted Risks)

Leer zu Projektstart. Befunde aus Reviews werden hier (resolved) bzw. in `AGENTS.todo.md` (offen) geführt.

## 11. Bestätigte Stärken / Nicht regredieren (aus Portal übernommen, soweit anwendbar)

- Mandanten-Isolation (`MandantContext`-Middleware + `forCurrentMandant()`-Scopes) — wie Brand im Portal.
- httpOnly-Cookie-Auth (kein Token in localStorage/sessionStorage).
- Keine Raw-SQL mit User-Input, `$fillable`-Disziplin (kein `Model::create($request->all())`).
- Bildupload mehrstufig validiert (Laravel-Rules + `mimes` + `exiftool`-MIME-Check).
- HTML-Sanitize beim Persistieren + Rendern (Symfony `HtmlSanitizer` / DOMPurify).
- Preis-/Freigabe-Logik server-autoritativ (Allocation-Engine), nicht im Client.
