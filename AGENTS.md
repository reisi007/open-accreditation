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
  4. **Der Verifikator darf die Arbeit des Implementierers nicht zerstören (STRICT, 2026-09-28).** *Anlass, gemeldet und nicht verschwiegen:* ein Verifikator hat beim Mutationstest `git checkout <datei>` auf eine Datei mit **uncommitteter** Diff-Arbeit ausgeführt und sie **zerstört**. Wiederhergestellt wurde sie aus dem Sitzungsprotokoll des Implementierers (ein `write` + 7 `edit`s + ein Heredoc, alle `oldString` validiert), geprüft an Diffstat, Markern, `tsc`, ESLint und vier grünen Suiten — **aber nicht byteweise gegen das Original**, weil es das nicht mehr gab. **Das ist die eigentliche Lücke:** nicht die Wiederherstellung war gut, sondern dass sie **nötig** war. Ein Verifikator, der in einem **geteilten, uncommitteten** Baum mutiert, hat keinen Rückweg — `git checkout` nimmt den Zustand aus dem **Index**, und der Commit-Stand ist nicht der Arbeitsstand. **Drei zulässige Wege, alle vor dem Mutationstest zu wählen:** (a) **der Build-Agent committet den Implementiererstand vor der Verifikation** — der Normalfall, weil §4 einen Commit ohnehin zur Fertigstellung zählt; (b) der Verifikator arbeitet auf einer **Kopie**; (c) `git stash` **bewusst und dokumentiert**, mit `git stash pop` am Ende. **Nicht** zulässig: `git checkout` / `git restore` / `git reset --hard` auf Pfade mit uncommitteten Änderungen. **Und die Meldepflicht:** passiert es trotzdem, ist es **sofort** und **vollständig** zu melden — dieser Verifikator hat genau das getan, inklusive der Angabe, welche Aussage er danach **nicht** mehr machen kann. Das ist der Grund, warum aus dem Fehler kein dauerhafter Schaden wurde.
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

**Fixture-Besitz: kein Test hinterlässt Daten (STRICT, 2026-09-28).** *Anlass, gemessen:* der
E2E-Namens-Sweep räumte nur auf, was er **zufällig** wiederfand — `teamNames: ['E2E Heimverein ']`
traf **keinen** der 54 Teams und **keine** der 53 Venues, die tatsächlich liegen geblieben waren. Der
`DELETE` **409te** (das Team referenzierte die Venue noch), der Status wurde **nicht** geprüft, und
`admin-data.ts:1143-1148` steckte in `try{…}catch{console.warn}`. Der eigentliche Schaden war nicht
der Müll, sondern eine **Messzahl, die fremde Daten zählte**: die Bandzahl des UI-Reviews stieg mit
jeder E2E-Suite (`36 → 48 → 51 → 52`), und `home filled/mobile` lag **6 px** von einer Bandgrenze
entfernt — die Abnahme „drei Läufe ergeben dieselbe Zahl" hing damit an Daten, die der Harness nicht
besitzt.

- **Jeder E2E-Test registriert, was er angelegt hat, und löscht es selbst** — auch wenn er **halb**
  scheitert: drei Fixtures erstellt, das vierte wirft, dann müssen **die drei** weg. Kein Test
  hinterlässt eine `E2E %`-Zeile.
- **Ein Sweep über Namensmarker ist Übergang, nicht Modell.** Er darf zusätzlich laufen, aber er darf
  nie der Grund sein, dass die Suite aufräumt. Referenzimplementierung: `portal.reisinger.pictures`.
- **Die Ausrede ist abgeschafft:** jedes `DELETE` im Purge prüft den Status, und eine fehlgeschlagene
  Rückräumung lässt den Lauf **scheitern**, statt sie zu verschlucken. Das ist ein **Sicherheitsnetz**
  hinter der Besitzregel — es darf nicht deren Ersatz werden.

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
`playwright.screenshots.config.ts` (outputDir `test-results/ui-screenshots` = **Scratch**, das
Playwright leert; die Captures liegen in `test-results/ui-review/` daneben, siehe Schritt 1;
Desktop Chrome 1920×950 + Mobile Chrome/Galaxy A55; fix 2 Worker wegen Backend-Login-Throttle).
Ausführen NUR via
`cd frontend && pnpm test:screenshots` (= `playwright test -c playwright.screenshots.config.ts`) — läuft
**nicht** in der Standard-E2E-Suite (`playwright.config.ts` / `tests/e2e`) und **nicht** im CI-E2E-Job.

Loop (Schritte 1–4):

1. **Capture:** Dev-Server (Vite 5173) + Backend (8000) laufen lassen — die Screenshot-Config
   hat **kein** `webServer` und nutzt `baseURL` aus `E2E_BASE_URL` (Default 5173) →
   `cd frontend && pnpm test:screenshots`. Pro Route × State
   (`filled`/`empty`) × Viewport entstehen ein **Full-Page-PNG** plus **Section-Captures**
   (`<name>-sec-N.png`, Scroll in 80-%-Schritten, damit unterhalb des Folds nichts unlesbar
   skaliert) — zusammen die „Bänder". **Und** für die Ausweis-Route zusätzlich das **gerenderte
   PDF** (siehe unten).

   **Ablage — `test-artifacts/ui-review/`, ausserhalb von `test-results/` überhaupt.** Playwright
   leert `outputDir` **rekursiv vor dem ersten Test**, und `--output` ist **konfigurierbar** —
   auf `test-results/` gemessen **192 → 0 PNG**. Auch ein Wächter in `globalSetup` löst das
   **nicht**: er läuft **nach** dem Löschen. Ein Sibling *innerhalb* von `test-results/` ist damit
   nur gegen den **Standard** geschützt, nicht gegen die **Möglichkeit** — der Store gehört
   **ganz** aus Playwrights Verzeichnis heraus. Layout: `<state>/<viewport>/<name>.png`
   (Vollseite), `-sec-N.png` (Bänder, eine einheitliche Serie), **`<name>.meta.json`** mit
   `bands` / `scrollHeightPx` / `dataset` / `runKey` / `entityIds`, und **`prev/<datei>`** — **eine**
   Generation, die das Bild vor dem Überschreiben sichert (unbegrenzte Historie ließe den
   Review-Batch mit jedem Lauf wachsen).
   `node scripts/ui-review-captures.mjs` liefert den Sammelbericht inkl. Δ zur Vorergeneration.

   **Bandzahl:** die „Bänder" umfassen Sections **und** gedruckte Seiten. **Bandzahl im
   Findings-Report nennen** — sie ist das Mass, mit dem ein Batch überprüfbar wird. **Und sie ist
   an den Datenbestand gekoppelt, nicht nur an die Seeds** — gemessen: `admin-users` **4** Bänder
   bei **620** Usern, `admin-mandant-detail` **23** Bänder bei **50** Teams. Ein Lauf gegen eine
   gewachsene Datenbank liefert **mehr** Bänder, und zwar für Routen, die der Review gar nicht
   ansteuert.

   Schlägt ein Screenshot-Test fehl, ist Harness oder Seite kaputt — zuerst fixen.
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
   **Bekannte Kopplung, und sie ist stärker als zunächst gemessen.** Eine Abnahme ist nur so
   stark wie ihre schwächste Annahme: **drei Läufe _ohne_ E2E-Lauf dazwischen** sind der Test,
   ein Screenshot-Lauf **nach** einem E2E-Lauf ist ein **anderer** Test. Gemessen: die Bandzahl
   stieg **36 → 48**, weil die E2E-Suite ihre Rückstände **nicht** abräumt (es gibt **keine
   DELETE-Route für User**) und damit eine bereits geprüfte Liste **länger** wird — `admin-users`
   **4** Bänder bei **620** Usern, `admin-mandant-detail` **23** Bänder bei **50** Teams.
   **Solange die Bandzahl an Fremddaten hängt, ist sie ein Messwert des Datenbestands, nicht des
   Verfahrens.** Die Entkopplung ist eine **Purge-Route**, keine Screenshot-Änderung — der Loop
   ist der Betroffene, nicht die Ursache.
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
und beendet sich ungleich 0, wenn etwas fehlt oder das Bild nicht vertrauenswürdig ist
(u. a. Exit 3, wenn nur `sips` verfügbar ist — das kann keinen
Alpha entfernen). Danach die PNGs wie oben an den `vision`-Subagenten, Checkliste: QR-Position,
Feld-Überlappung, Abschneiden, Kontrast, Font-Skalierung. Messwerte, Render-Vertrag und die
Stolperfallen: `features/badges-qr.md` → „Visuelle Verifikation des gerenderten PDF".

**Drei Postconditions, und warum gerade diese.** (1) **Kein Alpha-Kanal** — der Konsument
entscheidet, wie das aussieht. (2) **Eckpixel opak** statt „weiss": eine
**Transparenz**-Postcondition hätte Graustufen als Weiss missverstanden; Opazität ist die
Eigenschaft, die tatsächlich gebraucht wird. (3) **Tinte vorhanden** (`INK_SIGMA_MIN=0.001`,
Graustufen-σ im 1-%-Rand) — eine **leere oder schwarze** Seite gilt nicht mehr als erfüllt.

*Und die Zahlen, weil eine Schwelle ohne sie eine Setzung ist statt einer Rechnung:*

- **σ ist `sqrt(k(n−k))/n`** für `k` dunkle unter `n` Pixeln, auf ≤3,2·10⁻⁸ genau nachgemessen
  (5 von 5 Fixtures). Damit ist sie **nachrechenbar**, nicht behauptet.
- **`0,001` ist die Ein-Pixel-Linie — aber nur bei 200 dpi.** Gemessen: 0,002855 (72) · 0,002058
  (100) · 0,001372 (150) · **0,001029 (200)** · 0,000686 (300) · 0,000514 (400). Bei 300/400 dpi
  braucht die Schwelle also **2 bzw. ~4 Pixel** — **fail-closed in die richtige Richtung**, aber
  „genau ein Pixel" gilt **nur** bei der Default-Dichte. Wer die Zusage zitiert, muss die Dichte
  dazusagen.
- **Die Leerseite misst exakt 0** — 30 Kombinationen (5 Füllungen × 6 Dichten), auf einer zweiten
  Toolchain **32/32 bit-gleich**. Einschränkung: beide Läufe waren aarch64; x86-Linux ist
  ungemessen.
- **Der 1-%-Rand ist tragend — aber nicht aus dem Grund, der hier früher stand.** Ohne Rand
  liefert die antialiaste Seitenkante **0,01300** (72 dpi) bis **0,00465** (400) auf dem
  `magick`-Primärweg, bei 150/300 dpi exakt 0 — und **gar nicht** über `gs -sDEVICE=png16m`. Der
  Effekt tritt **nur bei gemaltem Seitenhintergrund** auf, also genau bei dem Fall, den unsere
  Ausweise seit D22 **nicht** mehr sind. Er bleibt fail-closed und wird nicht weggetestet.
- **Der Rand verschluckt nichts:** 4 px links/rechts = **0,51 mm**, 6 px oben/unten = **0,76 mm**,
  gegen das kleinste legale Element von **3 mm** (`MIN_TEXT_H_MM`) und die 10-mm-QR-Box.

**Drei Grenzen, die bleiben — und keine davon durch Absenken der Schwelle heilen:**

1. **σ misst Tinte, nicht Lesbarkeit.** 9771 Tintenpixel bei 0,06 % Kontrast lesen 0,00109, auf
   Weiss 0,00063 — beide **um** der Schwelle. Solche Seiten sind unbrauchbar und werden dem
   `vision`-Agenten gemeldet oder nicht; zu entscheiden ist das **nicht** diese Postcondition. Ein
   Kontrast-Verdikt wäre eine **zweite** und ist **nicht** gebaut.
2. **Ein Dekorrahmen maskiert fehlenden Inhalt.** Ein 1-pt-Rahmen, 1 pt eingerückt, ohne Text und
   ohne QR, misst **0,049…0,111** und gilt als „Inhalt" — ein **Fail-open**, und zwar gegen genau
   den Fall, den die Postcondition fangen soll. Das ist die Schwäche, die man kennen muss, bevor
   jemand die Schwelle „etwas höher" dreht, um ein Validitätsproblem zu heilen, das sie nicht
   verursacht.
3. **Straddeln existiert, aber anders als zuerst berichtet.** Ein 1-pt-Wort misst **0,0027…0,0041**
   und liegt bei allen sechs Dichten **über** der Schwelle — es straddelt **nicht**. Die Zone, in
   der das Urteil kippt, ist ein **einzelnes 0,5-pt-Zeichen**. Die Struktur ist real (σ = √(k/n),
   `k` ganzzahlig), die zuerst genannte Einzelzahl war fixture-spezifisch.

**Das gerenderte PDF gehört in den Loop (NUTZERENTSCHEIDUNG 2026-09-28).** Der Editor-Screenshot
prüft HTML/CSS im Browser; der Druck entsteht in dompdf. **dompdf kennt `object-fit` nicht** —
`width:100%;height:100%` bedeutet dort STRECK, und ein gestreckter QR-Code **wird nicht
gescannt**. Zwei Fehler dieser Sitzung (die `object-fit`-Streckung, die QR-Verzerrung) waren im
Editor **unsichtbar**. **Ein Editor-Screenshot kann einen dompdf-Fehler prinzipiell nicht zeigen.**

- **Über den echten Export-Weg**, nie ein direkt aufgerufenes `renderPdf()` im Test: sonst prüft
  man einen **anderen** Pfad als die Produktion und schliesst genau den Unterschied aus, den man
  prüft. Route: `POST /api/admin/accreditations/{id}/badges/export` (**POST mit Body**, nicht GET)
  mit `{format:'pdf', template_id}` via `page.request` aus dem Browser-Kontext, also mit der
  httpOnly-Cookie der echten Anmeldung. **Beleg, dass es diese Session ist:** dieselbe Route
  **vor** dem Login → **401** (gemessen; `GET` → 405, falscher Pfad → 404 — die Diskriminierung
  trägt).
- **Postcondition, die dem Backend fehlt:** Seiten == freigegebene Anträge. `BadgeExportService`
  rendert eine A6-Karte pro Antrag ohne jede Prüfung; ein Verband exportiert 500 Ausweise, und
  **niemand schaut auf jedes einzelne**.
- **Der Auftrag an `vision` lautet ausdrücklich: „zwei Ansichten derselben Vorlage, Editor gegen
  Druck."** **Ein Defekt in genau einer Ansicht ist der Befund.** Die PNGs liegen **neben** den
  Editor-Aufnahmen, damit beide in **einem** Batch liegen.
- **Grenze, die nicht zu schönreden ist:** der Loop prüft **eine** Vorlage, der Export **viele** —
  er findet **Fehlerklassen**, keine Einzelfälle im Massenlauf.

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

- **A5 (accepted 2026-09-28, medium):** Die **Konto-Löschung** (Nutzer selbst, `mandant_admin`
  und `super_admin`) ist **unumkehrbar und protokolliert nur in das Anwendungslog** — es gibt
  **kein** `audit_logs`, weder Migration noch Model (geprüft: in `backend/database/` und in
  `app/Models/` **null** Treffer). **Nutzerentscheid 2026-09-28:** „kein Audit, nur das
  Anwendungslog". **Konsistent mit dem Bestand:** das Löschen eines *Mandanten* protokolliert
  bereits auf dieselbe Weise (`MandantController:283`, `Log::error(…, ['mandant_id' => …])`), die
  Entscheidung führt also kein fremdes Muster ein, sie erweitert ein bestehendes. **Die
  Löschung ist deshalb verpflichtend, strukturiert und mit VOLLSTÄNDIGEM Kontext zu loggen** —
  Akteur, Ziel-Id, Ziel-E-Mail, Mandant, Anzahl der mitgerissenen Anträge, Media und
  Rollenzuordnungen — weil das Anwendungslog der **einzige** Ort ist, an dem die Frage „wer hat
  welches Konto gelöscht" je beantwortet werden kann. **Die Haltbarkeit hängt an einer
  Konfigurationsvariable, nicht an einer Zusage:** `LOG_STACK` ist in `.env.example` auf `single`
  gesetzt (eine Datei, **keine** Rotation per Config); ein Wechsel auf `daily` deckelt auf
  `max_files = 14`, auf `monthly` auf `3` — beide **hart kodiert** in `config/logging.php`. Wer
  den Kanal umstellt, ohne das zu bedenken, macht die Protokollierung unbrauchbar, **ohne** dass
  irgendetwas rot wird. Re-evaluieren, wenn (a) `LOG_STACK`/`LOG_DAILY_DAYS` umgebaut werden,
  (b) Protokolle in ein externes System wandern, oder (c) die Nachweispflicht aus einer
  Vertrags- oder Zertifizierungsanforderung folgt.

- **A6 (accepted 2026-09-28, medium):** `JWTGuard::logout()` (`vendor/php-open-source-saver/jwt-auth/src/JWTGuard.php:219-223`)
  hat `catch (JWTException $e) {}` mit dem Kommentar *„Proceed with the logout as normal if we can't
  invalidate the token"*. Wirft der Cache-Storage beim Sperren eine `JWTException`, antwortet
  `POST /api/auth/logout` mit **200 „Erfolgreich abgemeldet."**, obwohl **nichts** gesperrt wurde —
  gemessen mit einem Storage, der gezielt wirft. **Vendor-Verhalten, im Repo nicht zu ändern**: die
  Datei liegt unter `vendor/` und jede Änderung wäre beim nächsten `composer install` weg; stattdessen
  ist der **Docblock** `AuthController:246` („Invalidates the current JWT (blacklist)") zu relativ und
  beschreibt einen Pfad, der im Fehlerfall schweigt.
  **WICHTIG, zwei Korrekturen vom 2026-09-28, und die zweite heisst die erste nicht zurücknehmen:**
  Der ursprüngliche Beleg („logout widerruft, danach 401") war ein **Falsch-Positiv** — der Harness
  transportierte das Cookie **nicht** (`withCookie()` verwirft still, Board-Position 13), der 401 kam
  aus dem geleerten `JWT::$token`-Singleton. **Die Korrektur dieses Falsch-Positivs ist aber kein
  Beweis gegen Weg B, sondern endlich ein gültiger Beweis dafür**, gemessen über den einzigen
  funktionierenden Kanal (`TestCase::withJwtCookie()`):

  | Messung (Konto **besteht** durchgehend) | Ergebnis |
  |---|---|
  | Replay bei vorhandenem Blacklist-Eintrag | **401** — der Widerruf hält |
  | Replay nach `Cache::flush()` | **200** — Eintrag weg, Token gilt wieder |

  **Damit ist die Schwachstelle, die dieser Eintrag als *konditional* führte, gemessen statt vermutet:**
  sie entsteht genau dann, wenn jemand den Cache leert. Der Deploy tut es nicht
  (`entrypoint.sh`, per Test festgenagelt), also ist sie **nicht aktiv** — aber sie ist auch nicht
  weg, und ihre Obergrenze ist `JWT_TTL`. Wirft der Storage statt dessen eine `QueryException` —
  was eine fehlende Tabelle erzeugen würde —, ist es **laut** (500), nicht still.
  **Entscheidend für die Architektur:** die Konto-Löschung
  stützt sich **nicht** auf diesen Weg, sondern auf den **DB-Treffer** in
  `JWTGuard::user():107` (`retrieveById($payload['sub'])`) — fehlt die Zeile, ist `$this->user` null
  → **401 sofort**, und das gilt **auch nach einem vollständigen `Cache::flush()`** (gemessen mit
  zwei Prämissen, die eine Fehldeutung ausschliessen: Cache nachweislich leer, Token nachweislich
  unexpired — die Ablehnung kann nur aus der fehlenden Zeile kommen). **Weg B scheitert an genau
  derselben Probe** (nach dem Flush wieder 200). **Weg A ist also nicht von Weg B abhängig**, und
  `AccountDeletionRevokesAccessImmediatelyTest` nagelt das fest — mit der Gegenprobe, dass dasselbe
  Token **vor** dem Löschen 200 liefert, und der Mutation (Löschen → Zeile behalten), die **alle
  vier** Tests rot macht. Re-evaluieren, wenn das Paket aktualisiert wird oder wir von der
  Cache-Blacklist auf einen persistenteren Store wechseln.

  **Korrigierte Zahlen (2026-09-28, gemessen statt gerechnet):** Die Blacklist-Einträge leben
  **~10081 Minuten (~7 Tage)**, nicht ~61 — `Blacklist::getMinutesUntilExpired()` nimmt
  `$exp->**max**($iat->addMinutes($refreshTTL))`, den **späteren** der beiden Zeitpunkte. Die
  **sicherheitsrelevante** Grenze bleibt trotzdem `JWT_TTL` = 60 min, gemessen von der **Ausgabe**
  (`iat+59min` → 200, `iat+62min` → 401), denn `exp` greift, sobald der Eintrag fehlt. **Und die
  Ursache ist unkritisch:** `deployment/entrypoint.sh` führt `migrate → storage:link → seed →
  config:cache|config:clear` aus, **kein** `cache:clear`/`optimize:clear`; ein Test nagelt das fest
  (eine Zeile `cache:clear` in den Entrypoint einfügen → rot). Ein **Container-Neustart beim
  Deploy** ändert daran **nichts**: `CACHE_STORE=database`, die Blacklist ist eine Zeile in der
  `cache`-Tabelle im Volume `db_data`, nicht im Dateisystem des Containers. Das Risiko ist damit
  **konditional** — es entsteht erst, wenn jemand ein Cache-Leeren einbaut.

  **Und die Schärfe dieser Silhouette ist höher als zunächst notiert:** wirft der Storage eine
  `JWTException`, antwortet die Route mit **200 „Erfolgreich abgemeldet."**, der Store ist **leer**
  — **und das Token funktioniert weiter** (gemessen, nicht behauptet). Fail-open in einem
  **Auth**-Pfad: Erfolg gemeldet, Wirkung ausgeblieben.

## 11. Bestätigte Stärken / Nicht regredieren (aus Portal übernommen, soweit anwendbar)

- Mandanten-Isolation (`MandantContext`-Middleware + `forCurrentMandant()`-Scopes) — wie Brand im Portal.
- httpOnly-Cookie-Auth (kein Token in localStorage/sessionStorage).
- Keine Raw-SQL mit User-Input, `$fillable`-Disziplin (kein `Model::create($request->all())`).
- Bildupload mehrstufig validiert (Laravel-Rules + `mimes` + `exiftool`-MIME-Check).
- HTML-Sanitize beim Persistieren + Rendern (Symfony `HtmlSanitizer` / DOMPurify).
- Preis-/Freigabe-Logik server-autoritativ (Allocation-Engine), nicht im Client.
