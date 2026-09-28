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
- **Transaktionsabbruch bei Unique-Verletzung (CRITICAL):** Postgres bricht bei einer Unique-Verletzung
  die **gesamte** Transaktion ab (SQLSTATE `25P02`, „current transaction is aborted"); SQLite tut das
  **nicht** — dort lässt sich nach dem Fehlschlag weiterarbeiten. Das Muster „insert versuchen →
  Exception fangen → weiterarbeiten" ist damit **nicht portabel**: auf Postgres müssen alle folgenden
  Queries in derselben Transaktion sterben, und das Manifest meldet „Datenbank kaputt" statt „diese
  Zeile existierte schon". Solche Stellen brauchen ein **SAVEPOINT** (oder `DB::transaction()` pro
  Operation) um den erwarteten Fehlschlag abzufangen. Stand: **kein Code im Repo macht das** — die
  Regel ist präventiv, für den nächsten, der es versucht.
- **`LIKE … ESCAPE` ist auf SQLite nicht optional (CRITICAL):** Auf Postgres ist eine `ESCAPE`-Klausel
  ein semantisches No-op (dort ist `\` der Standard-Escape-Character), auf SQLite ist sie
  **zwingend**, weil SQLite keinen Standard-Escape-Character hat. Gefahrenrichtung: Wer das Escaping
  im Query beibehält, aber die `ESCAPE`-Klausel entfernt, erhält auf SQLite **0 Treffer statt
  Über-Match** — also **fail-closed, nicht fail-open**. Das ist die sichere Richtung, macht den Bug
  aber trotzdem schwer zu finden. `ESCAPE` deshalb immer **mitschreiben**, auch wenn es auf Postgres
  nichts zu ändern scheint.
- **Portabilitäts-Gate (die beiden Regeln oben sind gemessen, nicht behauptet):** Die Suite läuft
  regulär auf SQLite `:memory:` (`backend`-Job). Ein zusätzlicher CI-Job `backend-pgsql` in
  `.github/workflows/ci.yml` fährt **dieselbe Suite gegen echtes PostgreSQL 17** (`postgres:17-alpine`,
  eigene Wegwerf-DB `accriditation_test`, Bereitschaft über den `pg_isready`-Healthcheck, **seriell** —
  nie `--parallel`, weil paratest eine eigene Test-DB pro Worker braucht). Umgeschaltet wird rein
  über ENV (`DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` —
  die Namen aus `backend/config/database.php`); `phpunit.xml` bleibt unangetastet, weil seine
  `DB_CONNECTION`-/`DB_DATABASE`-Pins **ohne** `force="true"` dastehen und ein vorhandener
  Prozess-Wert gewinnt (`PhpHandler.php:160-168`). Der Job prüft vor dem Lauf, dass der aufgelöste
  Driver wirklich `pgsql` ist — sonst wäre er ein zweiter SQLite-Lauf, der grün ist und nichts prüft.
  **Lokal:** `bash scripts/test-pgsql.sh` (legt die Wegwerf-DB selbst an, braucht weder `docker`
  noch `psql`; `migrate:fresh` läuft dabei nur gegen diese Wegwerf-DB, **nie** gegen die Dev-DB).
  Der Job ist **blockierend**. Er war zunächst `continue-on-error` — **gemessen** begründet, weil
  der erste Lauf zwei in der **Test-Suite** liegende SQLite-Annahmen fand. „Vorbestehend" ist nach
  §4 **kein** zulässiges Label, also wurden beide behoben statt etikettiert:
  `AllocationAtomicityTest` staged den Foreign-Key-Verstoß mit `PRAGMA defer_foreign_keys` — nach
  der Migration nachweislich nicht portabel (sie erzeugt **kein** `DEFERRABLE`, und
  `SET CONSTRAINTS ALL DEFERRED` hebt den Check auf PG 17 nicht auf), dort jetzt ein dokumentierter
  **Skip**; `QrTokenV2Test` band seine Precondition an die feste Id 1, der Legacy-Schlüssel wird
  jetzt **für die tatsächlich erzeugte Id abgeleitet** (auf SQLite byte-identisch zur alten
  Konstante). Stand beider Engines lokal gemessen: SQLite **1534 passed / 0 skipped**, PostgreSQL
  **1533 passed / 1 skipped / 0 failed** — deckungsgleich, denn `1534 = 1533 + 1`.

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
- **i18n: jede neue Nachricht braucht DE *und* EN.** `pnpm build` läuft über
  `prebuild` → `check:i18n`, und das Gate **bricht mit exit 1 ab, wenn eine aktive
  Nachricht in einer Locale ohne `msgstr` steht** — Lingui fällt sonst still auf
  den deutschen Quelltext zurück, und ein englischer Nutzer sieht deutschen Text
  (so war es bei `{name} neu anlegen` und `{name} reaktivieren` in
  `VenueCombobox.tsx`). Nach neuen Strings: `pnpm lingui:extract && pnpm lingui:compile`
  **und** die EN-`msgstr` ausfüllen. **Einzige Ausnahme:** obsolete `#~`-Einträge —
  die behält Lingui als Historie und leert ihre `msgstr` selbst. **Kein Skip-Mechanismus**:
  „msgid == msgstr" ist strukturell abgedeckt, eine handgepflegte Ausnahmeliste wäre
  nur ein Ort, an dem sich die nächste echte Lücke versteckt.
- Keine `eslint-disable`, `@ts-ignore` oder `any`
- Keine blinden `.replace()`-Patches (Safe-Patching-Policy, §6)

**3. Das Verfahren tut, was das Dokument verspricht**

- **Ein Vertrag in `AGENTS.md`/`features/` schützt nur, wenn jemand prüft, ob er eingehalten
  wird.** In einer einzigen Sitzung war das **dreimal** der Fall: §7 versprach Section-Captures,
  die erst später gebaut wurden — der erste Vision-Loop erzeugte **0 von 60** und sah damit nie
  unterhalb des Folds; §7 behauptete nach dem D22-Entscheid **das Gegenteil** der gerade
  getroffenen Entscheidung und argumentierte so scheinbar für die eigene Abschaffung;
  `features/badges-qr.md` beschrieb einen Prüfvertrag, der nach zwei Korrekturen nicht mehr
  existierte. **Bei einer Doku, die man selbst schreibt, ist man der schlechteste Leser, den
  sie haben kann.**
- **Bevor eine Position als erledigt abgehakt wird:** prüfe sie gegen das, was das Verfahren
  **tatsächlich erzeugt** — nicht gegen das, was es **verspricht**. Für Code heisst das
  Mutation, nicht grüne Suite. Für ein Dokument heisst es Diff gegen den Code, nicht gegen die
  Absicht. Für ein Verdict heisst es die Zahl, die es belegt, nicht der Satz, der es zusammenfasst.

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
- **Verifikationsläufe laufen SEQUENZIELL, nie parallel (STRICT, 2026-09-27):** Eine volle
  Suite darf nie gleichzeitig mit einer anderen vollen Suite laufen — weder in zwei Subagenten noch
  im selben Agenten, der nebenbei eine zweite Suite fährt. Grund ist **CPU-Überschreibung**, nicht
  ein geteiltes Dateisystem: unter Last bläht jeder Test einer Datei um 3–5× auf (gemessen
  14 → 56 ms bei 24 Spinnern auf 18 Kernen), und der Reserve-Test in
  `frontend/src/pages/admin/UsersPage.test.tsx` riss dadurch ein 10-s-Budget, obwohl er intrinsisch
  ~563 ms kostet. **Das ist keine theoretische Regel:** der Build-Agent hat sie beim eigenen
  Verifizieren gebrochen (`php artisan test` neben `pnpm test:run` auf einer 18-Kern-Maschine mit
  Grundlast ~20) und den Timeout danach auf 15 000 ms anheben müssen. Zwei volle Läufe **nacheinander**
  kosten nur Zeit — parallel kosten sie eine grüne Ampel ohne Aussagekraft.
  Gilt auch für gemischte Läufe (Postgres-Gate neben SQLite-Suite, Frontend neben Backend).
- **User-Fragen immer interaktiv (STRICT):** Offene Klärungsfragen an den Benutzer werden
  IMMER sofort per interaktivem Frage-Tool gestellt — nie nur in `AGENTS.todo.md` geparkt.
  Ausnahme: Der Benutzer ist erkennbar abwesend oder hat async Bearbeitung angeordnet; dann
  Fragen in `AGENTS.todo.md` dokumentieren und bei nächster Gelegenheit interaktiv nachholen.

## 7. Testing & E2E (STRICT)

**Tag Policy** — E2E tests MUST be tagged via Playwright `{tag: [...]}`:

- `@smoke` — Critical path (login, guest, auth, basic CRUD). Run after every code change.
- `@regression` — Full functional coverage. Run before deployment.
- `@feature:<name>` — Feature-spezifisch (z. B. `@feature:accreditation`, `@feature:admin:mandant`).
- `@mobile` — mobile-only gestures/responsive.
- **New E2E tests MUST include at least one tag.**

**Execution:**

- Bei jedem Code-Change **lokal**: `test:e2e:smoke` (`npx playwright test --grep @smoke`) —
  schnelle Iterationshilfe. In **CI** fährt der Push-/PR-Job (`e2e`) die **volle Suite**
  (alle getaggten Specs), nicht nur `@smoke`.
- Feature-spezifisch: `npx playwright test --grep @feature:<name>`
- Vor Deployment: `test:e2e` (full suite)
- Wiederholung fehlgeschlagener Tests: `npx playwright test --last-failed`
- CI manuell anstoßen: `workflow_dispatch` mit `e2e_suite` = `full` (Default, verzeihend) /
  `smoke` (nur `@smoke`, Iterationshilfe) / `strict` (volle Suite mit striktem Budget wie
  der Nightly). `strict` ist der einzige manuelle Weg, einen roten Nightly vor dem nächsten
  03:30-UTC-Fenster erneut zu fahren.

**Die zwei Laufprofile (gleiches Testset, unterschiedliches Fehler-Budget):**

- **`playwright.config.ts` = verzeihendes Profil** (`retries: 2`, `maxFailures: 10` in CI).
  Fährt bei `push`/`pull_request` und bei `workflow_dispatch` mit `e2e_suite=full`/`smoke`
  die Suite — bei `push`/`pull_request` die **volle** Suite (alle Tests: `@smoke`,
  `@regression`, `@feature:*`), ohne `--grep`. Bewusst **verzeihend**: geteilte Runner haben
  echtes Timing-Rauschen. **Nicht** verschärfen.
- **`playwright.regression.config.ts` = striktes Profil** (`retries: 0`, `maxFailures: 1` in
  CI). Fährt die **volle** Suite bei `schedule` (Nightly) und bei `workflow_dispatch` mit
  `e2e_suite=strict`. Es ist der **Flakiness-Detektor** und muss deshalb **strikt** bleiben:
  Ein erster Fehlversuch IST das Ergebnis. Wer `retries`/höheres `maxFailures` hier einführt,
  macht das Gate blind — der Nightly wird nicht „grüngezogen", sondern der flaky Test wird
  behoben oder mit Datei/Testname + Ursache in `AGENTS.todo.md` begründet.
- Die Profile teilen `testDir`/`projects`/`baseURL` (identisches Testset) — die Wahl zwischen
  ihnen ändert **wann und wie streng** die Suite läuft, **nicht** ihren Umfang. Die `use.baseURL`
  kommt aus `E2E_BASE_URL` (Default `http://localhost:5173`) — beide Vite-Server pinnen Port
  5173, ein Dev→Preview-Wechsel darf darum nicht an einem hartkodierten Port zerschellen.

**Workflow-Reihenfolge für Test-Fixes:** (1) SOLL in `features/` dokumentieren → (2) Backend-Tests
(`php artisan test --filter`) → (3) Frontend-Unit-Tests (`pnpm vitest run`) → (4) erst danach E2E.

**Max 3 Fix-Versuche für Tests (STRICT):** Nach 3 erfolglosen Versuchen MUSS der Agent an den Benutzer
zurückgeben mit einer Analyse. Keine Endlos-Fix-Loops.

**Visuelle Verifikation / UI-Review (Design-QA, STRICT):** Permanent verpflichtender Workflow nach jeder
UI-Änderung (FE-Etappen, neue Seiten, Layout-/daisyUI-Anpassungen). Er ist **explizit getrennt** von den
funktionalen Playwright-E2E-Tests: eigener Ordner `frontend/tests/screenshots/` (Route-Manifest =
`tests/screenshots/ui-review.config.ts` — **nicht** im Frontend-Wurzelverzeichnis;
Quelle der Wahrheit für Routes × States × Viewports; generischer Spec
`ui-screenshots.spec.ts`, alle Tests mit Tag `@screenshot`), eigene Config
`playwright.screenshots.config.ts` (outputDir `test-results/ui-screenshots`; Desktop Chrome 1920×950 +
Mobile Chrome/Galaxy A55; fix 2 Worker wegen Backend-Login-Throttle). Ausführen NUR via
`cd frontend && pnpm test:screenshots` (= `playwright test -c playwright.screenshots.config.ts`) — läuft
**nicht** in der Standard-E2E-Suite (`playwright.config.ts` / `tests/e2e`) und **nicht** im CI-E2E-Job.

Loop (Schritte 1–4):

1. **Capture:** Dev-Server (Vite 5173) + Backend (8000) laufen lassen — die Screenshot-Config
   hat **kein** `webServer` und nutzt `baseURL` aus `E2E_BASE_URL` (Default 5173) →
   `cd frontend && pnpm test:screenshots`. Pro Route × State
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

**Wann ein Verdict dieses Loops überhaupt etwas bedeutet (STRICT).** Drei Voraussetzungen.
Jede ist aus einem **gemessenen** Versagen entstanden, nicht aus einer Vermutung — und jede ist
als **Abnahmeprüfung** formuliert, nicht als Behauptung darüber, was der Harness heute tut:

1. **Die Artefaktmenge ist reproduzierbar.** *Anlass:* drei identische Läufe gegen dieselbe
   Datenbank ergaben **35 / 45 / 66** Sections, weil die Seeds leaken
   (`ensurePrimaryMandantAccreditation()` legt pro Aufruf eine Kategorie an, die Seitenhöhen
   wachsen). Ein Verdict, dessen Umfang von der Datenmenge abhängt, ist ein Verdict **über
   diesen Lauf**, nicht über das System. **Abnahme:** drei Läufe in Folge ergeben dieselbe
   Section-Anzahl, und die Bandzahl steht als **Zahl** neben den Bildern — sonst ist ein Batch
   später nicht mehr nachvollziehbar.
2. **Ein Re-Capture erhält das „alt".** *Anlass:* der Harness leerte sein eigenes Verzeichnis,
   gemessen **95 → 4 PNG** — Schritt 4 war damit für *jede* Route unmöglich, auch für die
   gerade neu aufgenommene. **Abnahme:** ein `-g`-Lauf lässt die unbetroffenen Routen
   **bit-identisch** erhalten **und** legt der betroffenen Route ihr vorheriges Bild als
   Vergleichsstück bei.
3. **Der geprüfte Umfang ist der ganze, nicht der sichtbare.** *Anlass:* der erste Vision-Loop
   dieser Sitzung erzeugte **0 von 60** Section-Captures — er sah nie unterhalb des Folds, und
   **kein Bericht sagte das**. Sein „APPROVED" galt für den Datenstand, gegen den es lief
   (210 User, 14 Badge-Templates). **Abnahme:** die Anzahl der Section-PNGs wird **mitgezählt**
   und im Findings-Report genannt. Ein Batch, dessen Umfang niemand beziffern kann, ist kein
   Verdict.

**Abgrenzung (STRICT):** Dieser visuelle Loop ist **kein funktionaler Test** — er asserted kein Verhalten,
sondern erzeugt ausschließlich Pixel zur Design-QA durch den vision-Subagenten. Er gate **nicht** CI oder
Deployment; funktional verbindlich bleiben ausschließlich die E2E-Suiten dieses §7.

**PDFs statt Screenshots (`PDF-VISION`).** Für **Badge-/Ausweis-PDFs** (dompdf) ist
`playwright.screenshots` nicht das Werkzeug: der Rasterweg liefert **grundsätzlich** einen
Alpha-Kanal, und wie das aussieht entscheidet der Konsument. **Stand 2026-09-28:** unsere
**eigenen** Ausweise tragen jetzt `background-color: #ffffff` auf dem Seitencontainer
(Nutzerentscheidung D22), rasteren also **ohne** Alpha-Kanal — gemessen `srgb`, Eckalpha 1.
**Das Skript bleibt trotzdem streng**, weil es **fremde** PDFs weiterhin robust machen muss;
für unsere Ausweise ist es vom Reparierenden zum Prüfenden geworden. **Wer hier „der Renderer
malt keinen Hintergrund" liest, um die Postcondition abzubauen, liest veraltet** — die
Aussage gilt für dompdf im Allgemeinen, nicht mehr für unser Rendering. Der wiederholbare
Weg ist **ein Skript**, keine Einmal-Anweisung:

```bash
bash scripts/pdf-to-png-vision.sh <file.pdf> [-o OUTDIR] [-d DENSITY] [--keep-step1]
```

Es prüft seine Werkzeuge namentlich, kündigt jeden Fallback **laut** an, verifiziert das Ergebnis
(kein Alpha-Kanal, weisser Eckpixel, Seitenzahl) und beendet sich ungleich 0, wenn etwas fehlt oder
das Bild nicht vertrauenswürdig ist (u. a. Exit 3, wenn nur `sips` verfügbar ist — das kann keinen
Alpha entfernen). Danach die PNGs wie oben an den `vision`-Subagenten, Checkliste: QR-Position,
Feld-Überlappung, Abschneiden, Kontrast, Font-Skalierung. Messwerte, Render-Vertrag und die
Stolperfallen: `features/badges-qr.md` → „Visuelle Verifikation des gerenderten PDF".

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

- **A1 (accepted 2026-09-19, low):** `team_admin` kann per `GET /api/admin/teams/{team}/logo`
  das Logo eines Sibling-Teams im **eigenen** Mandanten lesen (Route-Gate `teams.view` ohne
  Team-Scope; `TeamController::showLogo`). Auth-gated, mandanten-isoliert, Public-Asset —
  bewusst akzeptiert (W4-F2 L1). Re-evaluieren, falls Team-Logos je sensitiv werden.
- **A2 (accepted 2026-09-26, low):** `deployment/dev.env` ist **versioniert** und enthält
  einen `APP_KEY`-Platzhalter aus 32 Null-Bytes plus die Dev-DB-Zugangsdaten
  (`accriditation`/`accriditation`). Kein Geheimnis, aber ein Prod-Footgun: die Basis-
  `docker-compose.yml` erzwingt `APP_KEY` per `${APP_KEY:?…}`, ein unbedachtes
  `docker compose --env-file deployment/dev.env --profile prod up` würde also **starten**.
  Mitigation ist der Boot-Guard `AppServiceProvider::assertProductionAppKeyIsStrong()`, der
  genau diesen Platzhalter in `APP_ENV=production` mit Log-Critical ablehnt — der Dienst
  verweigert den Start statt still mit bekanntem Schlüssel zu laufen. Deckt sich mit A3.
- **A3 (accepted 2026-09-26, medium):** `MANDANTS_CACHE_TTL` (Default 3600 s) cacht die
  Host-Allow-List der Mandant-Domains. Wird eine Mandant-Domain **gelöscht**, muss der
  Cache explizit invalidiert werden (`MandantContext::forgetHostnames()`), sonst bleibt der
  Host bis zum TTL allow-listed. Das ist **kein** Datenzugriff (der Host löst danach auf
  `MandantContext` auf und liefert 404, weil es die Mandant nicht mehr gibt), aber es
  ist eine unnötige Angriffsfläche für Subdomain-Takeover-artige Szenarien. Für jeden
  hostname-schreibenden Pfad (Domain anlegen/ändern/**löschen**, Mandant löschen) ist der
  Aufruf Pflicht — inklusive der inzwischen nachgezogenen `MandantController::destroy()`.
  Re-evaluieren, wenn `trustHosts` oder die Cache-TTL umgebaut werden.
- **A4 (accepted 2026-09-27, low):** zwei offene Dependabot-Advisories für `npm/svgo`
  (1 × high `removeScripts`-Executable-Links, 1 × medium `foreignObject`-Sanitize;
  **beide ohne `first_patched_version`** — es gibt derzeit keinen Fix zum Einspielen).
  `svgo` ist **keine** direkte Abhängigkeit, sondern transitive über
  `@iconify/tailwind4` → `@iconify/utils`, und der Pfad ist **rein build-zeitlich**:
  SVGOs Input sind die gepinnten Icon-Sets `@iconify-json/mdi` und
  `@iconify-json/material-symbols`, die `vite build` über den Tailwind-v4-Plugin
  verarbeitet. Die Icons werden zur Laufzeit ausschließlich als CSS-Klassen
  referenziert (`iconify mdi--menu`), es gibt **keinen** SVG-Optimizer-Lauf und
  **keinen** untrusted-SVG-Durchsatz — hochgeladene Medien laufen serverseitig über
  `symfony/html-sanitizer` (siehe §11), nicht über dieses npm-Paket. Die Advisories
  betreffen `removeScripts`-Sanitize von Angreifer-SVG und greifen daher nur bei
  unvertreutem Input. Bewusst **kein** Lockfile-Churn auf eine nicht existierende
  Version. Re-evaluieren, wenn (a) ein `svgo`-Release mit Fix erscheint, (b) Iconify
  von der CSS-Klassen-Nutzung auf den Vite-Plugin-Pfad wechselt (dann verarbeitet
  SVGO ggf. eigene Icons) oder (c) SVGO je zur Laufzeit auf User-Uploads gelegt wird.

## 11. Bestätigte Stärken / Nicht regredieren (aus Portal übernommen, soweit anwendbar)

- Mandanten-Isolation (`MandantContext`-Middleware + `forCurrentMandant()`-Scopes) — wie Brand im Portal.
- httpOnly-Cookie-Auth (kein Token in localStorage/sessionStorage).
- Keine Raw-SQL mit User-Input, `$fillable`-Disziplin (kein `Model::create($request->all())`).
- Bildupload mehrstufig validiert (Laravel-Rules + `mimes` + `exiftool`-MIME-Check).
- HTML-Sanitize beim Persistieren + Rendern (Symfony `HtmlSanitizer` / DOMPurify).
- Preis-/Freigabe-Logik server-autoritativ (Allocation-Engine), nicht im Client.
