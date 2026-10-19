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
  für PHPUnit-Tests. phpunit + paratest. JWT (php-open-source-saver/jwt-auth), httpOnly-Cookie.
- **Frontend:** React 19 + Vite + TypeScript strict (`noUnusedLocals`), Tailwind CSS v4 + daisyUI v5,
  React Router v7, SWR, react-hook-form + zod, Lingui (i18n), Vitest + Playwright. React Compiler aktiv
  (`useMemo`/`useCallback`/`React.memo`/`forwardRef` sind Antipatterns).
- **Dependencies:** neueste Versionen aus dem Portal-Projekt (`portal.reisinger.pictures`).
- **Postgres-Regel (CRITICAL):** Schema/Queries müssen **portabel** bleiben, damit die SQLite-Testsuite
  läuft: kein PG-spezifisches SQL in Migrationen/Queries, JSON-Spalten via Laravel `json`-Type (kein
  `jsonb`-roh), Datumsarithmetik über Query-Builder/Eloquent statt roher PG-Funktionen. Wo Postgres-
  Features nötig sind → Service-Abstraktion + separater Integrationstest (in `features/` dokumentieren).
- **Transaktionsabbruch bei Unique-Verletzung (CRITICAL):** Postgres bricht bei einer Unique-Verletzung
  die **gesamte** Transaktion ab (SQLSTATE `25P02`), SQLite **nicht** (dort lässt sich nach dem
  Fehlschlag weiterarbeiten). Das Muster „insert versuchen → Exception fangen → weiterarbeiten" ist
  damit **nicht portabel**: solche Stellen brauchen ein **SAVEPOINT** (oder `DB::transaction()` pro
  Operation), um den erwarteten Fehlschlag abzufangen.
- **`LIKE … ESCAPE` ist auf SQLite nicht optional (CRITICAL):** Auf Postgres ist eine `ESCAPE`-Klausel
  ein semantisches No-op, auf SQLite ist sie **zwingend** (kein Standard-Escape-Character). `ESCAPE`
  deshalb immer **mitschreiben** — auch wenn es auf Postgres nichts zu ändern scheint.
- **Portabilitäts-Gate (CRITICAL):** Ein zusätzlicher, **blockierender** CI-Job `backend-pgsql` fährt
  **dieselbe** Suite gegen echtes PostgreSQL 17, **seriell** (nie `--parallel` — paratest braucht eine
  eigene Test-DB pro Worker), umgeschaltet rein über ENV (`DB_CONNECTION=pgsql` + `DB_HOST/PORT/DATABASE/
  USERNAME/PASSWORD` aus `config/database.php`); `phpunit.xml` bleibt unangetastet, und der Job prüft
  vorab, dass der aufgelöste Driver wirklich `pgsql` ist (sonst ein zweiter SQLite-Lauf, der grün ist
  und nichts prüft). **Lokal:** `bash scripts/test-pgsql.sh` (`DB_HOST=dind`, s. `Agents.headless.md`
  §2.1; `migrate:fresh` trifft nur die Wegwerf-DB, **nie** die Dev-DB). **Regel:** beide Engines laufen
  grün und zählen dieselben Tests (`n = m + k`, die Differenz ist genau die dokumentierten Skips). Wer
  eine Zahl zitiert, prüft sie gegen einen Lauf — Messwerte sind Snapshots, kein Zustand.

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
- **i18n: jede neue Nachricht braucht DE *und* EN.** Nach neuen Strings `pnpm lingui:extract &&
  pnpm lingui:compile` **und** die EN-`msgstr` ausfüllen. Das `check:i18n`-Gate (Teil von `prebuild`)
  bricht mit exit 1 ab, wenn eine aktive Nachricht in einer Locale ohne `msgstr` steht — sonst fällt
  Lingui still auf den deutschen Quelltext zurück. **Einzige Ausnahme:** obsolete `#~`-Einträge (Lingui
  leert ihre `msgstr` selbst). **Kein Skip-Mechanismus** — eine handgepflegte Ausnahmeliste wäre nur ein
  Ort, an dem sich die nächste echte Lücke versteckt.
- Keine `eslint-disable`, `@ts-ignore` oder `any`
- Keine blinden `.replace()`-Patches (Safe-Patching-Policy, §6)

**3. Das Verfahren tut, was das Dokument verspricht**

- **Ein Vertrag in `AGENTS.md`/`features/` schützt nur, wenn jemand prüft, ob er eingehalten wird.**
- **Bevor eine Position als erledigt abgehakt wird:** prüfe sie gegen das, was das Verfahren
  **tatsächlich erzeugt** — nicht gegen das, was es **verspricht**. Für Code heisst das Mutation, nicht
  grüne Suite; für ein Dokument Diff gegen den Code, nicht gegen die Absicht; für ein Verdict die Zahl,
  die es belegt, nicht der Satz, der es zusammenfasst.

## 4. Dokumentation & Task-Management

- **Nur Regeln, keine Entscheidungen:** Diese Datei enthält ausschließlich Regeln — Anweisungen, die für jede Arbeit in diesem Repo gelten. Dauerhafte Entscheidungen, Begründungen, Messwerte und Historie gehören nicht hierher, sondern in `AGENTS.md`-nahe Dokumentation bzw. `features/`; offene Punkte nach `AGENTS.todo.md`; Erledigtes in die Git-Historie.
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
  aus der Verifikation wandern als offene Follow-ups oder Notizen in `AGENTS.todo.md` bzw. dauerhaft gültige
  Entscheidungen in `features/`. Die Bereinigung führt ein **Subagent** aus (der Build-Agent muss sie nicht
  selbst übernehmen).
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
  4. **Build-/Verify-Flow — zentral, nicht hier:** Der komplette Flow (Delegation, separater
     Verifikator, Befunde mit `Datei:Zeile` + Schweregrad, `nach-verify`-Modus, Verifikations-Gate)
     **gilt hier unverändert** — diese Datei hält nur die Repo-Spezifika, die Kommandos in
     `frontend/AGENTS.md` und `backend/AGENTS.md`.
  - **Operational Discipline (STRICT):** (a) Konsolidierung grundsätzlich auf dem **main**-Branch — KEINE
    per-Task `fix/*`-Branches. (b) Parallelität auf **max. 2 Subagenten** begrenzen und nur bei disjunkten
    Ziel-Dateien; sonst sequenziell. (c) In einem geteilten Working-Tree dürfen Subagenten **nie
    `git add -A`** verwenden — immer explizite Pfade (`git add <datei1> <datei2>`), sonst werden fremde
    in-flight-Änderungen in den Commit gezogen. (d) Nach Fertigstellung: auf main mergen/cherry-picken,
    Tests verifizieren, Scratch-Branches + Stashes aufräumen. (e) **Restart-Regel:** Kommt ein
    Implementer-Subagent mit einem Fehler/abgebrochen zurück → mit `sessionID` + Prompt „continue" neu
    starten (Kontext wiederaufnehmen), nicht von vorne beginnen; danach erneut separat verifizieren
    lassen. (f) **CI-Grün hat immer Priorität:** Ist CI nach einem Push rot (oder bricht lokal eine
    Suite/der Build), hat dessen Fix **Vorrang vor aller neuen Arbeit** — sofort analysieren, isoliert
    fixen, grün pushen, erst dann neue Tasks fortsetzen.
- **Implementer (Subagent):** läuft in frischem, isoliertem Kontext; erhält präzise Anweisungen +
  Ziel-Dateien; setzt um und erzeugt Tests.
- **Verifikator (Subagent):** separat vom Implementer; führt Tests/Lint/Build aus und prüft den Diff.

- **Wem gehört ein Befund — und wem nicht (STRICT).** Der Bericht einer Verifikationsrunde ist
  **Beweismaterial, kein Projektzustand.** Er wird nicht committet und nicht zitiert; was eine Runde
  findet, steht in der Commit-Message des **Fix-Laufs, der darauf folgt** — **mit Modus und Datum.**
  Das gilt auch, wenn der Befund des Verifikators **falsch** war: ein widerlegter Befund ist ein Ergebnis
  und gehört in dieselbe Nachricht, mit dem Befehl, der ihn widerlegt, und nicht in eine eigene Akte.
  **Ein Verify-Lauf committet nicht** — es sei denn, er hat wirklich Code geändert (etwa, weil er einen
  Widerspruch zwischen zwei Tests auflösen musste); dann sagt die Message das ausdrücklich und trennt,
  was Beobachtung und was Änderung war. **Ein Befund wird von dem Lauf beschriftet, der ihn behandelt,
  nicht von dem, der ihn fand.**

### Modellwahl für Eskalationen — Peak-Regel (STRICT)

**`opencode-go/deepseek-v4.1-flash`** ist das Eskalationsmodell für Korrektur- **und**
Verifikationsläufe, wenn eine Verifikationsrunde **negativ** zurückkommt. **Es kostet Geld und wird
ausschließlich mit ausdrücklicher Genehmigung des Nutzers eingesetzt** — diese Datei ist die Genehmigung
**nicht**.

- **Peak-Stunden (UTC): `01:00–04:00` und `06:00–10:00`.** Wochenende (Sa/So **Peking-Zeit** = UTC+8)
  ist durchgehend Off-Peak, also **Fr 16:00 → So 16:00 UTC OFF**; danach zurück auf die Stundenregel.
- Datenquelle: `https://raw.githubusercontent.com/all-the-rest/ocgo-price-tracker/main/data/latest.json`,
  Feld `peakHours.deepseekv4.1flash` (nicht aus dem Changelog zitieren — dort steht dieselbe Regel in
  Prosa und driftet eher).
- **Die Umschaltregel:** `deepseek-v4.1-flash` darf **nur** eingesetzt werden, wenn im aktuellen
  Off-Peak-Fenster **noch mindestens 1 Stunde** bis zu seinem Ende bleibt. Sonst **Standardmodell**, und
  der Lauf wartet. **Vor jedem Start ausführen, nicht aus dem Kopf beantworten** — die Uhr geht:

```bash
python3 -c "
import datetime,zoneinfo
PEAK=[(1,4),(6,10)]; SH=zoneinfo.ZoneInfo('Asia/Shanghai')
p=lambda d: d.astimezone(SH).weekday()<5 and any(a<=d.hour<b for a,b in PEAK)
n=datetime.datetime.now(datetime.timezone.utc).replace(minute=0,second=0,microsecond=0)
h=n+datetime.timedelta(hours=1)
while not p(h): h+=datetime.timedelta(hours=1)
r=(h-n).total_seconds()/3600
print(f'{n:%Y-%m-%d %H:%M} UTC  jetzt={\"PEAK\" if p(n) else \"OFF\"}  naechste Peak={h:%Y-%m-%d %H:%M} UTC  Rest={r:.2f}h  -> {\"ERLAUBT\" if r>=1 else \"BLOCKIERT\"}')"
```

- **Die abschließende Runde läuft NICHT auf dem Eskalationsmodell:** Die Eskalation gilt für die
  **Arbeit**, die ein negativer Befund auslöst; das **Verdikt**, das danach zitiert wird, gehört auf das
  Modell, das die anderen Runden gefahren haben.

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
- **Verifikationsläufe laufen SEQUENZIELL, nie parallel (STRICT):** Eine volle Suite darf nie gleichzeitig
  mit einer anderen vollen Suite laufen — weder in zwei Subagenten noch im selben Agenten, der nebenbei
  eine zweite Suite fährt. Grund ist **CPU-Überschreibung**, nicht ein geteiltes Dateisystem: unter Last
  bläht jeder Test einer Datei um 3–5× auf, und ein knappes Test-Budget reisst dadurch. Zwei volle Läufe
  **nacheinander** kosten nur Zeit — parallel kosten sie eine grüne Ampel ohne Aussagekraft. Gilt auch für
  gemischte Läufe (Postgres-Gate neben SQLite-Suite, Frontend neben Backend).
- **User-Fragen immer interaktiv (STRICT):** Offene Klärungsfragen an den Benutzer werden IMMER sofort per
  interaktivem Frage-Tool gestellt — nie nur in `AGENTS.todo.md` geparkt. Ausnahme: Der Benutzer ist
  erkennbar abwesend oder hat async Bearbeitung angeordnet; dann Fragen in `AGENTS.todo.md` dokumentieren
  und bei nächster Gelegenheit interaktiv nachholen.

## 7. Testing & E2E (STRICT)

**Tag Policy** — E2E tests MUST be tagged via Playwright `{tag: [...]}`:

- `@smoke` — Critical path (login, guest, auth, basic CRUD). Run after every code change.
- `@regression` — Full functional coverage. Run before deployment.
- `@feature:<name>` — Feature-spezifisch (z. B. `@feature:accreditation`, `@feature:admin:mandant`).
- `@mobile` — mobile-only gestures/responsive.
- **New E2E tests MUST include at least one tag.**

**Execution:**

- Bei jedem Code-Change **lokal**: `test:e2e:smoke` (`npx playwright test --grep @smoke`) — schnelle
  Iterationshilfe. In **CI** fährt der Push-/PR-Job (`e2e`) die **volle Suite** (alle getaggten Specs).
- Feature-spezifisch: `npx playwright test --grep @feature:<name>`
- Vor Deployment: `test:e2e` (full suite)
- Wiederholung fehlgeschlagener Tests: `npx playwright test --last-failed`
- CI manuell anstoßen: `workflow_dispatch` mit `e2e_suite` = `full` (Default, verzeihend) / `smoke` (nur
  `@smoke`) / `strict` (volle Suite mit striktem Budget wie der Nightly). `strict` ist der einzige manuelle
  Weg, einen roten Nightly vor dem nächsten 03:30-UTC-Fenster erneut zu fahren.

**Fixture-Besitz: kein Test hinterlässt Daten (STRICT).**

- **Jeder E2E-Test registriert, was er angelegt hat, und löscht es selbst** — auch wenn er **halb**
  scheitert: drei Fixtures erstellt, das vierte wirft, dann müssen **die drei** weg. Kein Test
  hinterlässt eine `E2E %`-Zeile.
- **Ein Sweep über Namensmarker ist Übergang, nicht Modell.** Er darf zusätzlich laufen, aber er darf
  nie der Grund sein, dass die Suite aufräumt. Referenzimplementierung: `portal.reisinger.pictures`.
- **Die Ausrede ist abgeschafft:** jedes `DELETE` im Purge prüft den Status, und eine fehlgeschlagene
  Rückräumung lässt den Lauf **scheitern**, statt sie zu verschlucken. Das ist ein **Sicherheitsnetz**
  hinter der Besitzregel — es darf nicht deren Ersatz werden.

**Die zwei Laufprofile (gleiches Testset, unterschiedliches Fehler-Budget):**

- **`playwright.config.ts` = verzeihendes Profil** (`retries: 2`, `maxFailures: 10` in CI). Fährt bei
  `push`/`pull_request` und bei `workflow_dispatch` mit `e2e_suite=full`/`smoke` die Suite. Bewusst
  **verzeihend**: geteilte Runner haben echtes Timing-Rauschen. **Nicht** verschärfen.
- **`playwright.regression.config.ts` = striktes Profil** (`retries: 0`, `maxFailures: 1` in CI). Fährt
  die **volle** Suite bei `schedule` (Nightly) und bei `workflow_dispatch` mit `e2e_suite=strict`. Es ist
  der **Flakiness-Detektor** und muss deshalb **strikt** bleiben: Ein erster Fehlversuch IST das Ergebnis.
  Wer `retries`/höheres `maxFailures` hier einführt, macht das Gate blind — der flaky Test wird behoben
  oder mit Datei/Testname + Ursache in `AGENTS.todo.md` begründet.
- Die Profile teilen `testDir`/`projects`/`baseURL` (identisches Testset) — die Wahl zwischen ihnen ändert
  **wann und wie streng** die Suite läuft, **nicht** ihren Umfang. Die `use.baseURL` kommt aus
  `E2E_BASE_URL` (Default `http://localhost:5173`) — beide Vite-Server pinnen Port 5173.

**Workflow-Reihenfolge für Test-Fixes:** (1) SOLL in `features/` dokumentieren → (2) Backend-Tests
(`php artisan test --filter`) → (3) Frontend-Unit-Tests (`pnpm vitest run`) → (4) erst danach E2E.

**Max 3 Fix-Versuche für Tests (STRICT):** Nach 3 erfolglosen Versuchen MUSS der Agent an den Benutzer
zurückgeben mit einer Analyse. Keine Endlos-Fix-Loops.

**AUSNAHME (allgemein):** Die Grenze gilt **nicht**, wenn eine Verifikationsrunde **negativ** zurückkommt
(`CHANGES REQUIRED`). In dem Fall wird die Schleife fortgesetzt — **aber nur unter zwei Auflagen**:
1. **Jede Runde endet mit dem Befund**, nicht mit dem Versuch, ihn zu umgehen. Der Build-Agent
   **berichtet** und legt die Entscheidung offen; er dreht nicht eigenmächtig weiter.
2. **Kein Befund wird stillschweigend verworfen.** Was eine Runde findet, geht in die Commit-Message
   **des Fix-Laufs** mit Modus und Datum ein, auch dann, wenn der Code korrekt war und nur die
   Behauptung nicht (§3 und §5). Ein Verwurf muss **begründet** sein, nicht bequem.

**Umkehrung:** drei Runden *ohne* negatives Verdikt sind weiterhin drei, und dann wird ohne jede Ausnahme
zurückgegeben.

**Visuelle Verifikation / UI-Review (Design-QA, STRICT):** Permanent verpflichtender Workflow nach jeder
UI-Änderung (FE-Etappen, neue Seiten, Layout-/daisyUI-Anpassungen). Er ist **explizit getrennt** von den
funktionalen Playwright-E2E-Tests: eigener Ordner `frontend/tests/screenshots/` (Route-Manifest =
`tests/screenshots/ui-review.config.ts` — **nicht** im Frontend-Wurzelverzeichnis; Quelle der Wahrheit für
Routes × States × Viewports; generischer Spec `ui-screenshots.spec.ts`, alle Tests mit Tag `@screenshot`),
eigene Config `playwright.screenshots.config.ts` (Desktop Chrome 1920×950 + Mobile Chrome/Galaxy A55; fix
2 Worker wegen Backend-Login-Throttle). Ausführen NUR via
`cd frontend && pnpm test:screenshots` (= `playwright test -c playwright.screenshots.config.ts`) — läuft
**nicht** in der Standard-E2E-Suite und **nicht** im CI-E2E-Job. Geprüft wird gegen die Checklist des
**UI-Review-Skills**.

Loop (Schritte 1–4):

1. **Capture:** Dev-Server (Vite 5173) + Backend (8000) laufen lassen — die Screenshot-Config hat **kein**
   `webServer` und nutzt `baseURL` aus `E2E_BASE_URL` (Default 5173) → `cd frontend && pnpm test:screenshots`.
   Pro Route × State (`filled`/`empty`) × Viewport entstehen ein **Full-Page-PNG** plus **Section-Captures**
   (`<name>-sec-N.png`, Scroll in 80-%-Schritten) — zusammen die „Bänder". **Und** für die Ausweis-Route
   zusätzlich das **gerenderte PDF** (siehe unten). Ablage **`test-artifacts/ui-review/`, ganz ausserhalb
   von `test-results/`** (Playwright leert `outputDir` rekursiv vor dem ersten Test; Layout:
   `<state>/<viewport>/<name>.png`, `-sec-N.png`, `<name>.meta.json`, `prev/<datei>` = **eine** Generation).
   `node scripts/ui-review-captures.mjs` liefert den Sammelbericht inkl. Δ zur Vorergeneration **und** das
   `Reproduzierbar:`-Urteil. Das verlangt **zwei** Bedingungen: **jede** Zeile hat einen Vorgänger **und**
   der Batch stammt aus **einer** Generation (`runKeys.length === 1`). Das Urteil nennt im `nein`-Fall
   **beide** Gründe. Schlägt ein Screenshot-Test fehl, ist Harness oder Seite kaputt — zuerst fixen.
2. **Vision-Analyse:** Die PNG-Pfade liest **das Modell selbst** (jedes konfigurierte Modell ist
   vision-fähig; einen Vision-Subagenten braucht der Loop nicht). **Nur** subjektive oder feingranulare
   Bilder liest das Modell **erneut selbst** mit einer konkreten Frage nach (Skill `ui-review`:
   re-read, do not guess) — es gibt keinen Vision-Subagenten mehr, und der Report wird nicht mit
   unsicheren Befunden gefüllt.
   Max. **10 Bilder pro Batch**; Batching-Reihenfolge: erst nach State (`filled` → `empty`), dann
   Viewport (desktop → mobile).
3. **Findings-Report:** Konsolidierter Bericht je Befund `Severity | Screenshot | Finding | Suggested fix`
   (Template im Skill); Severities `critical/high/medium/low`; **`critical`/`high` blockieren das Verdict
   `APPROVED`** (→ eigene Fix-Todos, wie das Verifikations-Gate; §5 Build-Agent Punkt 4). **Bandzahl und
   Section-PNG-Anzahl im Findings-Report nennen** — sie sind das Mass, mit dem ein Batch überprüfbar wird.
4. **Fix-Loop:** Fixes delegieren (Implementer + separater Verifikator, §5) → **Re-Capture nur der
   betroffenen Routen** (`cd frontend && pnpm test:screenshots -g <routenname>`) → **old vs new** wird
   verglichen, die Behebung bestätigt bzw. neue Befunde gemeldet; gesamten betroffenen Batch
   re-verifizieren, bevor der Loop geschlossen wird.

**Wann ein Verdict dieses Loops überhaupt etwas bedeutet (STRICT)** — drei Abnahmeprüfungen, nicht
Behauptungen darüber, was der Harness heute tut:

1. **Die Artefaktmenge ist reproduzierbar.** Drei Läufe in Folge ergeben dieselbe Section-Anzahl, und die
   Bandzahl steht als **Zahl** neben den Bildern. (Drei Läufe **ohne** E2E-Lauf dazwischen sind der Test,
   ein Screenshot-Lauf **nach** einem E2E-Lauf ist ein **anderer** Test.)
2. **Ein Re-Capture erhält das „alt".** Ein `-g`-Lauf lässt die unbetroffenen Routen **bit-identisch**
   erhalten **und** legt der betroffenen Route ihr vorheriges Bild als Vergleichsstück bei.
3. **Der geprüfte Umfang ist der ganze, nicht der sichtbare.** Die Anzahl der Section-PNGs wird
   **mitgezählt** und im Findings-Report genannt. Ein Batch, dessen Umfang niemand beziffern kann, ist
   kein Verdict.

**Abgrenzung (STRICT):** Dieser visuelle Loop ist **kein funktionaler Test** — er asserted kein Verhalten,
sondern erzeugt ausschließlich Pixel zur Design-QA. Er gate **nicht** CI oder Deployment; funktional
verbindlich bleiben ausschließlich die E2E-Suiten dieses §7.

**PDFs statt Screenshots (`PDF-VISION`).** Für **Badge-/Ausweis-PDFs** (dompdf) ist `playwright.screenshots`
nicht das Werkzeug. Der wiederholbare Weg ist **ein Skript**:

```bash
bash scripts/pdf-to-png-vision.sh <file.pdf> [-o OUTDIR] [-d DENSITY] [--keep-step1]
```

Es prüft seine Werkzeuge namentlich, kündigt jeden Fallback **laut** an, verifiziert das Ergebnis und
beendet sich ungleich 0, wenn etwas fehlt oder das Bild nicht vertrauenswürdig ist (u. a. Exit 3, wenn nur
`sips` verfügbar ist). Danach die PNGs wie oben geprüft, Checkliste: QR-Position, Feld-Überlappung,
Abschneiden, Kontrast, Font-Skalierung. Das Skript wird **nicht** aufgeweicht, wenn ein Werkzeug fehlt —
ein Skript, das ohne Prüfung grün durchläuft, ist schlimmer als keines. Messwerte, Render-Vertrag und
Stolperfallen: `features/badges-qr.md` → „Visuelle Verifikation des gerenderten PDF".

- **Drei Postconditions:** (1) **kein Alpha-Kanal**; (2) **Eckpixel opak** (nicht „weiss" — eine
  Transparenz-Postcondition hätte Graustufen als Weiss missverstanden); (3) **Tinte vorhanden**
  (`INK_SIGMA_MIN`, Graustufen-σ im 1-%-Rand) — eine **leere oder schwarze** Seite gilt nicht als erfüllt.
- **Das gerenderte PDF gehört in den Loop.** Der Editor-Screenshot prüft HTML/CSS im Browser; der Druck
  entsteht in dompdf — **ein Editor-Screenshot kann einen dompdf-Fehler prinzipiell nicht zeigen** (z. B.
  kennt dompdf `object-fit` nicht; ein gestreckter QR-Code wird nicht gescannt).
- **Über den echten Export-Weg**, nie ein direkt aufgerufenes `renderPdf()` im Test: Route
  `POST /api/admin/accreditations/{id}/badges/export` (**POST mit Body**, nicht GET) mit
  `{format:'pdf', template_id}` via `page.request` aus dem Browser-Kontext (httpOnly-Cookie der echten
  Anmeldung; vor dem Login → **401**).
- **Postcondition, die dem Backend fehlt:** Seiten == freigegebene Anträge.
- **Auftrag an die Prüfung:** „zwei Ansichten derselben Vorlage, Editor gegen Druck." **Ein Defekt in genau
  einer Ansicht ist der Befund.** Die PNGs liegen **neben** den Editor-Aufnahmen, damit beide in **einem**
  Batch liegen.
- **Grenze:** der Loop prüft **eine** Vorlage, der Export **viele** — er findet **Fehlerklassen**, keine
  Einzelfälle im Massenlauf.

## 8. Domain-Modell (Kurzreferenz)

Hierarchie: **Super Admin → Mandant (Verband, eigene Domain) → Team (Verein, optional je Mandant) →
Kategorie (erbt vom Mandant, Team überschreibt) → Akkreditierung (Quota + Frist) → Application
(Status requested/approved/denied/blacklisted).** Details/SOLL in `features/`. Personen: **pro Mandant
eigenes Konto** (eigene Domain). Rollen: super_admin, mandant_admin, team_admin, user, verifier (Ordner).

## 9. Modules

Module-spezifische Regeln in per-module `AGENTS.md`; Details stehen dort, nicht hier:

- **`frontend/AGENTS.md`** — React Vite SPA (React Compiler, Tailwind JIT/Only, Zod, ESLint/TS, Lingui,
  Semantic Locator Scoping, no `page.goto`, localStorage-Injection-Verbot, Field-Label-Policy).
- **`backend/AGENTS.md`** — Laravel (Test-Kommando, DB-Setup + Migration-Policy, SQLite `:memory:`-Tests,
  Postgres-Dev, paratest-Konkurrenzregel, Queues/Scheduler).
- **`Agents.headless.md`** — headless/geteilter Host (ein Arbeitsstrom, höchstens zwei Agenten, Namens-/
  Gateway-Auflösung `dind` statt `localhost`, `APP_URL`-Falle, `ps`/`pkill` fehlen, Berichtspflicht
  *gemessen / nur kompiliert / nicht prüfbar hier*). **Vor jedem Verifikationslauf lesen.**

## 10. Security Risk Register (Accepted Risks)

Befunde aus Reviews werden **offen** in `AGENTS.todo.md` geführt bzw. als **akzeptiertes Risiko** in
`features/security/accepted-risks.md` dokumentiert.

## 11. Bestätigte Stärken / Nicht regredieren (aus Portal übernommen, soweit anwendbar)

- Mandanten-Isolation (`MandantContext`-Middleware + `forCurrentMandant()`-Scopes) — wie Brand im Portal.
- httpOnly-Cookie-Auth (kein Token in localStorage/sessionStorage).
- Keine Raw-SQL mit User-Input, `$fillable`-Disziplin (kein `Model::create($request->all())`).
- Bildupload mehrstufig validiert (Laravel-Rules + `mimes` + `exiftool`-MIME-Check).
- HTML-Sanitize beim Persistieren + Rendern (Symfony `HtmlSanitizer` / DOMPurify).
- Preis-/Freigabe-Logik server-autoritativ (Allocation-Engine), nicht im Client.
