# Skills-Marker

agents-skills-consumed: 820c140
geprüft am: 2026-10-21

Bezugsrahmen — die gemeinsame Regel, **zitiert statt kopiert**: `skills-marker/SKILL.md` →
„Bezugsrahmen-Regel" (Skill-ID + Datei + Anker, **ohne Zeilennummer** — die driftet, der
Anker bleibt); Wortlaut, Belege und Fälle: `skills-marker/references/notes.md`
§ „Der Bezugsrahmen". **Lokale Ergänzung:** `<stand>` = der committete Stand dieses
Commits (die SHA, die erst mit dem Commit existiert), `<messstand>` = der Stand, an dem
gemessen wurde. Der Cursor ist **kein** Rahmen — er zeigt den Stand des Skills-Repos.

> Fünftmarker. Geprüfte Range `0449b05..820c140` — **9 Commits** des Skills-Repos
> (`git -C <skills-repo> rev-list --count 0449b05..820c140` → **9**, gemessen am
> **Skills-Repo-Commit `820c140`**, den dieser Marker konsumiert; **Messzeitpunkt
> 2026-10-21**). **Der Cursor `820c140` ist der Stand des Skills-Repos, den dieser
> Marker konsumiert.** Beim **Messen** (unmittelbar vor dem Commit, 2026-10-21) stand
> der Skills-Repo-HEAD auf `820c140` und war mit `origin/main` identisch
> (`git -C <skills-repo> rev-list --count origin/main..HEAD` → **0**,
> `... HEAD..origin/main` → **0**). Der Cursor ist **9 Commits** vorgerückt
> (`git -C <skills-repo> rev-list --count 0449b05..820c140` → **9**).
> Die Range berührte **genau 2 Skill-Verzeichnisse** unter `.agents/skills/`
> (`git log --name-only --format= 0449b05..820c140 -- .agents/skills | sort -u` →
> **4 Dateien** in **2 Verzeichnissen**): `skills-marker` (7 Commits, 3 Dateien:
> `SKILL.md`, `references/notes.md`, `templates/AGENTS.skills.md`) und `ratchet-gates`
> (1 Commit `d5dc52f`, 1 Datei `references/cli.md`).
> **Der Stand des Skills-Repos ist während dieses Laufs weitergewandert:** bei der
> **ersten** Messung stand der HEAD auf `f7b8f9c`, bei der **zweiten** (nach dem Lauf der
> Anker-Prüfung) auf `820c140` — der Commit `820c140` landete **während** dieses Laufs
> (Commit-Datum 2026-10-21 20:28:26 +0000). Die Range ist deshalb auf den **gemessenen**
> Endstand `820c140` gezogen (**9** Commits); `f7b8f9c` ist ein **Vorgänger** darin.
> **Nach** dieser Messung ist **ein weiterer** Commit im Skills-Repo gelandet:
> `37cefe2` („docs(skills-marker): the noun does not decide the case, and an
> enumeration carries two claims"). Er ist **NICHT** Teil der geprüften Range und **NICHT
> geprüft** — er berührt **nur** `skills-marker` (`SKILL.md`, `references/notes.md`,
> `templates/AGENTS.skills.md`; **89** Einfügungen, **35** Löschungen) und gehört in den
> nächsten Marker-Lauf; **der Cursor bleibt `820c140`**. Zur Kontrolle gemessen: die
> **Anker** dieses Markers lösen auch am Stand `37cefe2` auf (je **1** Treffer,
> `Bezugsrahmen-Regel` weiterhin **7-mal** im Skill-Verzeichnis) — das ändert nichts an
> der Prüfpflicht: der Cursor nennt den **konsumierten** Stand, und dieser ist `820c140`.
> **Neu in der Range:** **0** Skills (`git log --diff-filter=A --name-only --format=
> 0449b05..820c140 -- .agents/skills` → **leer**) — **neu versteckt: 0** —
> **entfernt: 0**. **Versteckt gesamt:** unverändert **17** von **19** Skills mit
> `metadata.opencode/autoinvoke: false` (Frontmatter-Messung an beiden Revs: die Range
> fasst nur `skills-marker/SKILL.md` an, und dessen `metadata.opencode/autoinvoke:
> false` ist an beiden Revs gleich).
> **Nicht-Skill-Abschnitt:** genau **1** der **9** Commits berührt überhaupt keinen
> `.agents/`-Pfad (`7910ec6` — nur `AGENTS.todo.md` des Skills-Repos; gemessen je Commit
> über `git log --name-only --format='COMMIT %h %s' 0449b05..820c140`).
> **Lokale Ergänzung:** Jede Zeile nennt eine **stabile Referenz** (Abschnitt,
> Überschrift oder Suchstring); Zeilennummern nur als Lesehilfe. Der Pfad zum
> Skills-Repo steht hier bewusst nicht — der Marker bleibt bei einer Verschiebung gültig.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Konfiguration (globale `opencode.jsonc`, MCP, Skill-/Command-Registrierung) — das Projekt hat keine `opencode.jsonc` und keine Modell-/MCP-Datei. **Seit `817c01e` hidden** (nur per exakter ID ladbar) — das ändert die Anwendbarkeit nicht: die Pflicht bleibt eine Pflicht der Maschine, nicht des Projekts |
| `build-verify` | offen | `AGENTS.md` §5 Punkt 4 „Build-/Verify-Flow — zentral, nicht hier" (`:114-117`) | Position 52 — zwei Sätze in `AGENTS.md` §5 widersprechen der Skill-Fassung; die zitierten Upstream-Sätze sind in dieser Range **unverändert** |
| `codegraph-project-setup` | offen | `.githooks/pre-commit` (CodeGraph-Sync-Hook, versioniert — **läuft aber nicht**: `core.hooksPath` nirgends gesetzt, `.git/hooks/pre-commit` fehlt); `.codegraph/` trägt **nur** `.gitignore`, **keinen** Index (`codegraph status`, gemessen 2026-10-21: „⚠ Not initialized") | Position 54 — der Pflichtteil des Skills (Index + verdrahteter Hook) ist **nicht erfüllt**; am Vorgänger `a4551c8` gemessen, seitdem unverändert (`git diff --stat a4551c8..<stand> -- .codegraph/` → leer) |
| `docker-test-image` | ja | `.github/workflows/e2e-image.yml` (Job `build` `:67`; PLAYWRIGHT_VERSION-Auflösung `:91-94` im Schritt „Extract build args from repository" `:79` — der `PNPM_VERSION`-Wert steht im **Workflow**-`env:` `:64`; der Step holt ihn in seinem **Step**-`env:` `:81`/`:87` (`PNPM_VERSION: ${{ env.PNPM_VERSION }}`) und prüft ihn `:93-95`; ein **Job**-`env:`-Block existiert nicht (der Job `build` hat keinen); **BASE_REF**-Auflösung im eigenen Schritt „Resolve base image reference (digest pass-through)" `:107-147`, der Wert `base_ref` steht in `:147` und wird `:164` als Build-Arg verbraucht), `deployment/Dockerfile.e2e` (ARG-Block `:68`, `:115`), `features/05-e2e-test-image.md` | keine |
| `generated-docs` | offen | `features/venue-master-data.md` „Portabilität (§2)" (`:358-368`: die Gate-Zahlen nennen Lauf `37280377310`, Datum und Leser — die Zahl steht **in** der Prosa), `features/auth/01-auth-and-roles.md` (`:430-454`: die unbezifferte Zahl wurde durch **andere Prosa** ersetzt) | **Position 56** — die Pflicht trifft zu und ist **nicht erfüllt**: das Projekt hat **weder einen Generator noch ein Drift-Gate** (gemessen am Vorgänger `a4551c8`, seitdem unverändert — `git diff --stat a4551c8..<stand> -- scripts/` → leer: `scripts/` enthält nur `assert-db-driver.php`, `dev-worker.sh`, `e2e-up.sh`, `lib/`, `pdf-to-png-vision.sh`, `render-badge-photo-placeholder.mjs`, `test-pgsql.sh`; im committeten Baum **keine** Datei mit `gen_`/`drift`/`doc_fact`/`generate` im Namen). Die Skill-Regel nennt die Zahl in Prosa ausdrücklich als Fehlerfall (Upstream `generated-docs/SKILL.md`, Anker „## Numbers in prose" — **ohne Zeilennummer**, die driftet; **1** Treffer am Stand `820c140`, die Datei ist in dieser Range unverändert). **Kein „nein"**, weil das Projekt die Pflicht in eigener Form trägt: `AGENTS.md:80` („für ein Dokument Diff gegen den Code, nicht gegen die Absicht") — die Lücke ist der **fehlende maschinelle Check**, nicht die fehlende Pflicht |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` (Kommentar „ein public GHCR-Package" `:526-530` + `image: ghcr.io/…` `:550`), `features/05-e2e-test-image.md` (`:167-168`) | keine — beide Packages public (geprüft 2026-10-20: `gh api visibility=public` für `accriditation-e2e` und `accriditation-base`) |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` → `paths-ignore: '**/*.md'` auf `push` **und** `pull_request` (`:63`, `:66`) | keine — **seit `817c01e` hidden**: die Pflicht gilt unverändert, nur der Ladeweg ändert sich (per exakter ID) |
| `laravel` | offen | `backend/AGENTS.md` (Commands, DB-/Migrations-Policy, Parallel-Testing, Datei-Tests), `backend/phpunit.xml` (Hermetik-Pins), `backend/composer.json` (PHP ^8.5, pint, paratest, jwt-auth) | Position 53 — Validierungs-Konvention nicht umgesetzt; am Vorgänger `a4551c8` gemessen, seitdem unverändert (`git diff --stat a4551c8..<stand> -- backend/` → leer): `FormRequest`-Klassen **0**, `rules()` bei **8** von **23** Controllern, Inline-Array bei **17** |
| `node-deps` | ja | `frontend/package.json` (keine Pins/Overrides), `ci.yml` (`version: 11` `:481`, `:739`), `e2e-image.yml` (`PNPM_VERSION: "11"` `:64`), `deployment/Dockerfile.e2e` (`ARG PNPM_VERSION=11` `:117`) | keine |
| `permissions` | nein | | opencode.jsonc-Policy (Maschine) — das Projekt definiert keine; `backend/config/permissions.php` ist die Gate-Matrix des Projekts, nicht diese Policy |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` (Run-Step: die Worker-Begründung `:792-802`, die Kommandos `:866-870`), `frontend/playwright.config.ts` (`fullyParallel: true` `:24`, `workers: process.env.CI ? 4 : 8` `:27`) | keine |
| `ratchet-gates` | offen | `features/security/accepted-risks.md` (A1–A8: kanonischer Status `accepted` + kanonische Niveaus `low`/`medium`, stabile Ids — **getragen**), `backend/config/permissions.php` („Single source of truth for the authorization layer") | **Position 57** — die **Owner-/Ticket-Regel** fehlt: Upstream `ratchet-gates/references/vocabulary.md`, Anker „**A finding needs an owner and a ticket**" (unless it is `fixed`) — und Upstream `ratchet-gates/SKILL.md`, Anker „an owner and a ticket per entry" (der Anker steht über den Zeilenumbruch). **Beide sind ohne Zeilennummer zitiert** (die Zeilennummer driftet) und **beide sind unverändert**: **1** Treffer je Anker am Stand `820c140` wie am Vorgänger `0449b05` (`git diff --stat 0449b05..820c140 -- .agents/skills/ratchet-gates/SKILL.md .agents/skills/ratchet-gates/references/vocabulary.md` → leer). Die Regeln waren in der Range `787670a..0449b05` **neu**; das Projekt trägt sie bis heute nirgends. **Die Range `0449b05..820c140` hat diesen Skill berührt** (`d5dc52f` → `references/cli.md`), **an der Gate-Substanz aber nichts**: die geänderte Datei ist die CLI-Karte, nicht die Gates (Niveaus, Stände, Zählregel, Owner-/Ticket-Regel, Baseline-Teil) — das Verdikt `offen` und die Position **57** bleiben deshalb sachlich richtig. Die Release-Aussage dreht sich um („**No tag was pushed yet** … build from source" → „**The releases are published**"), das Projekt hat **keinen** `ratchet`-Werkzeugbaum, **keine** Baseline und **keine** Release-Pipeline, und die **alte** Aussage stand nie in diesem Marker oder im Board (gemessen: `git grep -inE 'No tag was pushed|build from source|kein Tag.*(push|veröffentlicht)' <stand> -- AGENTS.skills.md AGENTS.todo.md` → **0** Treffer) — es ist **nichts** zu korrigieren. **Zwei Messungen, zwei Rahmen:** das **Register** ist eine Fremddatei — am Vorgänger `a4551c8` gemessen, seitdem unverändert (`git diff --stat a4551c8..<stand> -- features/security/accepted-risks.md` → leer): `git grep -ciE 'owner|ticket' a4551c8 -- features/security/accepted-risks.md` → **0** Treffer. Der **Board** ist eine der beiden geänderten Dateien — daher am **committeten Stand dieses Commits** gemessen, und die Kommandos sind auf **`<stand>` gepinnt**, damit der Leser genau den Stand setzt, den der Rahmen nennt (die Form aus dem Marker von `/projects/LuminaRust`): `git grep -inE '\bowner\b|\bticket\b' <stand> -- AGENTS.todo.md` trifft ausschließlich den **Meta-Text** (die Kopfzeile mit dem Positions-Index und die Positionstexte 55–57), in den **Befund-Zeilen der Batch-Tabelle** (`| **N** | …`) **0** (`git show <stand>:AGENTS.todo.md | grep -E '^\| \*\*[0-9]+\*\* \|' | grep -icE '\bowner\b|\bticket\b'` → 0). **Der Ratchet-Teil ist nicht anwendbar** — dafür ist **keine** Position nötig, das ist eine begründete Abweichung: im **Projektbaum** gibt es **kein** Baseline-Artefakt im Sinne des Skills (ein festgehaltenes bekannter Fehler). **Projektbaum** (die beiden geänderten Doku-Dateien ausgeschlossen; am Vorgänger `a4551c8` gemessen — der Projektbaum ist gegenüber dem Vorgänger unverändert, **45** Zeilen-Treffer in **20** Dateien an beiden Ständen): `git grep -in baseline 2da0404 -- . ':(exclude)AGENTS.skills.md' ':(exclude)AGENTS.todo.md'` → **45**; Verteilung `items-baseline` (Tailwind-CSS-Klasse) **10**, `$baseline` (PHP-Variable) **9**, `baseline-browser-mapping` (npm-Paket im Lockfile) **3**, Rest **23**; nach Bereich: 1 Dokumentationsdatei, 10 Backend-Dateien (davon 7 in `backend/tests/`), 9 Frontend-Dateien. **Repo-weit** (mit den beiden Doku-Dateien) sind es **56** Zeilen-Treffer in **22** Dateien am committeten Stand dieses Commits — **Lokale Ergänzung:** dieser Wert kann **keinen** Vorgänger-Rahmen tragen, weil die Doku Teil des Repos ist. Ebenso **kein** known-failure/xfail-Marker im Projekt: mit **Wortgrenze** (`\bxfail\b`) liegen die Treffer im Repo **ausschließlich** in der **Doku, die das Muster selbst nennt** (dieser Marker, das Board) — in `backend/`, `frontend/`, `features/`, `deployment/`, `AGENTS.md`: **0**; ohne Wortgrenze kommen die `maxFailures`-Stellen dazu: **30** Zeilen-Treffer am Stand `a4551c8` (**14** in Konfiguration und Code: `ci.yml` 8, `frontend/playwright.config.ts` 1, `frontend/playwright.regression.config.ts` 5; **16** in Prosa: `AGENTS.todo.md` 5, `features/05-e2e-test-image.md` 4, `AGENTS.md` 3, `Agents.headless.md` 3, `frontend/tests/e2e/helpers/admin-data.ts` 1 — davon **15** in Markdown-Prosa und **1** als Prosa in einer Code-Kommentarzeile), **25** Zeilen-Treffer im **Projektbaum** (die beiden geänderten Doku-Dateien ausgeschlossen; an den Ständen `a4551c8`, `2da0404`, `c353313` und `78051f0` identisch — der Vorgänger-Vergleich trägt nur das, was **gleich** bleibt: `git diff --stat 2da0404..78051f0 -- . ':(exclude)AGENTS.skills.md' ':(exclude)AGENTS.todo.md'` → leer; dieser Commit fasst nur die beiden Doku-Dateien an, der Wert gilt an seinem committeten Stand genauso) und repo-weit **32 Zeilen-Treffer, vorkommensweise 34 Vorkommen** am committeten Stand dieses Commits — deshalb die Wortgrenze, denn ohne sie zählt jede `maxFailures`-Stelle mit. `AGENTS.md:99-101` verbietet getragene Altfehler (Zero-Pre-existing-Failures-Policy), und die **7** `markTestSkipped` (gemessen am Vorgänger `a4551c8`, 5 Dateien) sind je **umgebungsbedingt** und mit Ursache benannt, also kein getragener Fehlerbestand |
| `react-i18n` | ja | `frontend/AGENTS.md` (§ „Lingui / i18n — No Module-Scope `t` (STRICT)" `:97`), `frontend/vite.config.ts` (lingui-Plugin + Babel-Preset `:32-33`), `frontend/src/main.tsx` (I18nProvider als erster Import `:1`, `:5`), `frontend/package.json` (`lingui:extract`/`lingui:compile`, `check:i18n` in `prebuild`) | keine — das Projekt weicht bewusst ab: zwei Locales (de+en) mit Vollständigkeits-Gate statt der Portal-Referenz (eine Locale + Rohstring-Pass) |
| `security-review` | ja | `AGENTS.md` §10 (Fundort der Befunde) + `features/security/accepted-risks.md` (Register A1–A8), `AGENTS.md` §3 (Regressionstest je Fix `:59`) | keine — das Register trägt die kanonischen Stände (`accepted`) und Niveaus (`low`/`medium`) mit stabilen Ids; die Klassen BELEGT/HEURISTIK/AKZEPTIERT bleiben daneben stehen und kollidieren nicht **Board-Messung am committeten Stand dieses Commits** (der Board ist eine der beiden geänderten Dateien): die Klassen-Namen in **Großschreibung** (`BELEGT`/`HEURISTIK`/`AKZEPTIERT`/`ZURÜCKGENOMMEN`) stehen **0** Mal im Board — **case-sensitiv** gemessen (`git show <stand>:AGENTS.todo.md | grep -cE 'BELEGT|HEURISTIK|AKZEPTIERT|ZURÜCKGENOMMEN'` → 0; `<stand>` = committeter Stand dieses Commits); das case-insensitive Muster greift zusätzlich Prosa wie „belegt“/„akzeptiert“ und taugt hier nicht. Als Schweregrade kommen dort nur die kanonischen Wörter vor — `critical`/`high`/`medium`/`low`/`info`; `Major`/`hoch`/`niedrig` nur in Prosa |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei; Abschnitt „Fortschreiben"; die Regel wird **zitiert statt kopiert** — Upstream `skills-marker/SKILL.md`, Anker „Bezugsrahmen-Regel" (Skill-ID + Datei + Anker, **ohne Zeilennummer**), belegt im Kopf dieser Datei) | keine |
| `tailscale-serve` | nein | | kein Tailscale-Bezug im Projekt (repo-weiter Treffer: nur diese Datei) |
| `ui-review` | offen | `AGENTS.md` §7 „Visuelle Verifikation / UI-Review (Design-QA, STRICT)" (`:262-271`, Loop-Schritte 1–4 `:273-300`), `frontend/tests/screenshots/` | Position 55 — der Findings-Report in `AGENTS.md` §7 Schritt 3 (`:293-295`) führt die **alte** Vorlage (4 Spalten, 4 Niveaus, kein Statusfeld, keine Id/Eigentümer/Ticket) |
| `update-models` | nein | | Modell-Registry (Maschine) — das Projekt hat keine `opencode.jsonc` und keine Modell-Datei; **seit `817c01e` hidden**, und `219171c`/`8c6b9af` korrigieren nur Snapshot und Namespace-Regel der **Maschine** |
| `web-audit` | nein | | trifft nicht zu, weil das Projekt keine Marketing-/Content-Website hat (kein SEO-, Sitemap- oder Performance-Audit-Gegenstand) — die visuelle Prüfung der App ist der Screenshot-Design-QA-Loop (`ui-review`), ausdrücklich getrennt von funktionalen Tests |

`ja` = die Pflicht des Skills **trifft hier zu** und ist **erfüllt** (am Code geprüft) ·
`nein` = trifft nicht zu, Begründung in der Zeile · `offen` = die Pflicht **trifft hier
zu**, ist aber **nicht erfüllt** — die Lücke ist **gemessen** und steht als **Position**
in `AGENTS.todo.md` (Log-Eintrag im Board, kein Auftrag). **Die drei Werte sind
disjunkt.** Eine `ja`-Zeile trägt in der Spalte „offene Position" eine Begründung
(„keine — …"), wenn die Pflicht erfüllt ist, aber eine bewusste Abweichung gilt; eine
`offen`-Zeile trägt dort die Positionsnummer. Die Tabelle ist ein **Zustand**, kein
Auftrag. **Die sechs `offen`-Zeilen** (`build-verify`, `laravel`,
`codegraph-project-setup`, `ui-review`, `generated-docs`, `ratchet-gates`) sind alle
geprüft — `offen` heißt hier **nicht** „ungeprüft", sondern „geprüft, und die Pflicht ist
nicht erfüllt".

**Lokale Ergänzung:** **`offen` ist das eigene Verdikt dieses Markers.** Das
Upstream-Template kennt nur zwei Werte — „`angewendet?` = ja | nein"
(`skills-marker/templates/AGENTS.skills.md`, Anker „ja | nein") —, und „ja" bedeutet
dort *geprüft*, nicht *erfüllt*; das steht in derselben Datei im Anker „"Angewendet"
heißt: am Code dieses Projekts geprüft — nicht: die Doku gelesen." und in
`skills-marker/SKILL.md` (Anker „**Angewendet** heißt **am Code geprüft**, nicht
gelesen"); der dritte Fall steht dort als Spalte („`offene Position` = Verweis auf den
Eintrag in `AGENTS.todo.md`" — `skills-marker/SKILL.md`, Anker „| Trifft zu, ist aber
nicht drin |"). Dieser Marker macht daraus ein **eigenes** drittes Verdikt, damit „trifft
zu" und „ist erfüllt" nicht dieselbe Zelle tragen. **Die Upstream-Auswahlregel gilt
unverändert und betrifft die Anwendbarkeit, nicht die Erfüllung:** *„im Zweifel gewinnt
**ja** mit offener Position, nicht **nein**"* (`skills-marker/SKILL.md`, Anker „im Zweifel
gewinnt **ja** mit offener Position, nicht **nein**") — sie entscheidet zwischen `ja` und
`nein`, nicht zwischen `ja` und `offen`.

## Offen aus dem Bereich 0449b05..820c140 (2026-10-21)

> **Herkunft:** Fortschreibung des Markers `AGENTS.skills.md`
> (`agents-skills-consumed: 0449b05` → `820c140`). Die Range umfasst **9 Commits**
> und berührte **genau 2 Skill-Verzeichnisse**. **Die Tabelle ist ein Zustand, kein Auftrag.**
> Die 2 Skill-Verzeichnisse oben sind **keine** offenen Positionen und **keine** 2
> unerledigten Arbeiten: die Tabelle sagt je Skill, ob seine Pflicht hier **trifft**
> und ob sie **erfüllt** ist. **Lokale Ergänzung:** eine Bedeutung von „offen" — und sie
> ist nicht „ungeprüft". Das **Verdikt `offen`** heißt in dieser Tabelle: *geprüft, die
> Pflicht trifft zu und ist nicht erfüllt* — die Lücke steht als **Position** im Board.
> Die **Prüfung** selbst ist für **beide** Skill-Verzeichnisse aus dieser Range
> abgeschlossen; beide Zeilen sind entschieden. Eine **Lücke** ist **gemessen** und
> steht als **Position** im Board (`AGENTS.todo.md`): 52 (`build-verify`), 53 (`laravel`),
> 54 (`codegraph-project-setup`), 55 (`ui-review`), 56 (`generated-docs`), 57
> (`ratchet-gates`) — alle **sechs** sind `offen`-Zeilen. Beides ist ein Log, kein
> Arbeitsauftrag.

**Je Commit der Range, in der Reihenfolge der Range** — jeder Effekt am **jeweiligen
Commit** gemessen (`git show --stat <sha>`, `git show --numstat <sha>`):

| Commit | Was |
|---|---|
| `d5dc52f` | **`ratchet-gates`** — die Release-Aussage dreht um: „**No tag was pushed yet** … build from source" → „**The releases are published**: every version tag carries the assets of five platforms and a `checksums.txt`"; dazu eine neue Tabellenzeile für `ratchet release` und die Regel „`ratchet release` **exits `1`**, wenn es nichts zu veröffentlichen gibt". `references/cli.md`: **7** Einfügungen, **3** Löschungen, **1** Datei. |
| `7910ec6` | **berührt überhaupt keinen Skill** — nur `AGENTS.todo.md` des Skills-Repos (**27** Löschungen, **0** Einfügungen). Das ist der **eine** Commit der Range ohne jeden `.agents/`-Pfad. |
| `e523df9` | **`skills-marker`** — die Rahmen-Regel kommt: eine Aussage über einen Stand nennt den Stand, in dem sie gemessen wurde; der Cursor ist **kein** Rahmen. `SKILL.md` **+4**, `references/notes.md` **+106**, `templates/AGENTS.skills.md` **+9** (**119** Einfügungen, **0** Löschungen). |
| `21b29e4` | **`skills-marker`** — die Befunde der Regel-Verifikation geschlossen: die **dritte** Rahmenform (eine Datei, die sich seit der Messung geändert hat) kommt in die Tabelle, der genannte SHA ist der **Messstand** (nicht zwingend der direkte Vorgänger), der Cursor-Ausschluss und der Zeilenverweis auf `references/notes.md` stehen in der Kurzform. `SKILL.md` **+4/−3**, `references/notes.md` **+41/−24**, `templates/AGENTS.skills.md` **+3/−3**. |
| `7341bb0` | **`skills-marker`** — die dritte Form in **beide** Kopien getragen (Regelabsatz in `SKILL.md` und der Fortschreiben-Abschnitt der Vorlage, die im Projekt bleibt) und die zwei Platzhalter getrennt: `<stand>` = der committete Stand des Commits, `<messstand>` = der Stand, an dem gemessen wurde. `SKILL.md` **+2/−2**, `references/notes.md` **+7/−5**, `templates/AGENTS.skills.md` **+5/−1**. |
| `992a9eb` | **`skills-marker`** — die dritte Form bekommt ihren **eigenen Beleg** (`git diff --stat <messstand>..<stand> -- <datei>`) und ihre eigene Fallbezeichnung („eine Datei, die sich seit der Messung geändert hat"); die Belegzeile steht in allen drei Kopien. `SKILL.md` **+1/−1**, `references/notes.md` **+4/−3**, `templates/AGENTS.skills.md` **+4/−2**. |
| `65fbecc` | **`skills-marker`** — die **drei** fehlenden Klauseln und die Zitierform: (1) eine Messung, deren Umfang der Commit selbst schreibt, trägt **keinen** Vorgänger-Rahmen; (2) eine Aussage über **wann** etwas galt, nennt den Stand **und** den Messzeitpunkt; (3) ein Marker **zitiert** die Regel statt sie zu kopieren — Pfad im Skill + **Anker, ohne Zeilennummer**. Der Anker `Bezugsrahmen-Regel` kommt hier ins Skill-Verzeichnis: an `0449b05` **0** Treffer, an `65fbecc` **4** (`SKILL.md` **1**, `references/notes.md` **2**, `templates/AGENTS.skills.md` **1**). `SKILL.md` **+37/−36**, `references/notes.md` **+149/−5**, `templates/AGENTS.skills.md` **+24/−14**. |
| `f7b8f9c` | **`skills-marker`** — die Rahmenformen werden präzisiert (die Formen (b) und (c) unterscheiden sich an der **Zeitform**, nicht am Nomen; der bisherige Wortlaut beider Formen bleibt) und die **Cursor-Klausel** kommt: ein Verweis trägt nur, wenn der Cursor den Stand deckt, in dem die Zitierstelle existiert — wer zitiert, rückt den Cursor vor und begeht die Range dazwischen. `SKILL.md` **+31/−31**, `references/notes.md` **+145/−32**, `templates/AGENTS.skills.md` **+13/−8**. |
| `820c140` | **`skills-marker`** — die sieben Befunde der Runde-5-Verifikation geschlossen; **nur** `references/notes.md` (der Belege-Teil): `SKILL.md` und die Vorlage behalten den Text von `f7b8f9c` — der Anker und der Wortlaut der Formen bleiben, wo sie sind. Geschlossen sind die doppelte „Fallstrick"-Sektion, ein Zitat der ersetzten Upstream-Fassung, die Verteilung der `baseline`-Treffer, die der Upstream-Text in **diesem** Projekt gemessen hatte, sowie die Fundstellen der Nachbarprojekte. `references/notes.md`: **21** Einfügungen, **30** Löschungen, **1** Datei. **Für dieses Projekt ohne Wirkung auf eine Pflicht** — der Anker `Bezugsrahmen-Regel` steht am neuen Stand weiterhin **7-mal** (`SKILL.md` **1**, `references/notes.md` **5**, `templates/AGENTS.skills.md` **1**), der Abschnitt „## Der Bezugsrahmen" steht da (er wandert von Zeile 200 auf 181 — das Beispiel dafür, dass die **Zeilennummer** driftet, der **Anker** nicht). |

**Neu in der Range:** **keiner** (`git log --diff-filter=A --name-only --format=
0449b05..820c140 -- .agents/skills` → **leer**).

**Nicht Teil dieser Range:** `37cefe2` — der Commit ist **nach** der Messung gelandet,
auf die der Cursor gestellt ist, und ist **nicht** geprüft; er berührt **nur**
`skills-marker` (`SKILL.md`, `references/notes.md`, `templates/AGENTS.skills.md`; **89**
Einfügungen, **35** Löschungen). Er gehört in den nächsten Marker-Lauf; **der Cursor
bleibt `820c140`**.

**Neu versteckt:** keiner — die Range fasst **keine** Frontmatter an; **versteckt gesamt**
unverändert **17** von **19** Skills (`git grep -l "opencode/autoinvoke: false" <stand> --
.agents/skills/*/SKILL.md` → **17**, an beiden Revs gleich).

**Entfernt in der Range:** keiner.

**Ergebnis je Skill aus der Range** — die Range hat **2** Skill-Verzeichnisse berührt
(gezählt mit `git log --name-only --format= 0449b05..820c140 -- .agents/skills | cut -d/ -f3
| sort -u` → `ratchet-gates`, `skills-marker`). **Je Skill genau eine der drei
Entscheidungen aus §3:**

- **`skills-marker` → `angewendet: ja`** (trifft zu **und** ist drin). Die sieben Commits
  ändern **die Regel, nach der dieser Marker gebaut ist**, nicht eine Pflicht, die das
  Projekt trägt. Geprüft wurde, was davon die **Anwendung hier** betrifft: die
  **Zitierform** (der Marker zitiert die Regel mit Pfad + Anker und **ohne
  Zeilennummer** — von `734d7d1` an so, jetzt mit dem Anker `Bezugsrahmen-Regel` aus
  `65fbecc`), die **`**Lokale Ergänzung:**`-Markierung** (am committeten Stand dieses
  Commits **6** Markierungen im Marker und **1** im Board — `grep -c 'Lokale Ergänzung'`
  zählt im Marker **8**, die zwei zusätzlichen Treffer sind diese Nennung und das
  Suchkommando selbst; von `65fbecc` verlangt), die
  **Rahmenformen** (a)/(b)/(c) und die neue
  **Cursor-Klausel**. Die Zeile `skills-marker | ja | AGENTS.skills.md (diese Datei)`
  **trägt weiter** — am neuen Stand **gemessen**, nicht übernommen: sie belegt jetzt die
  Zitierstelle im Kopf dieser Datei.
- **`ratchet-gates` → `angewendet: offen`** (trifft zu, ist aber **nicht** drin) —
  **Position 57 bleibt**. Gemessen: die Range berührt **nur** `references/cli.md`, die
  **CLI-Karte** — die Gate-Substanz (Niveaus, Stände, Zählregel, Owner-/Ticket-Regel,
  Baseline-Teil) ist unverändert (`git diff --stat 0449b05..820c140 -- .agents/skills/
  ratchet-gates/SKILL.md .agents/skills/ratchet-gates/references/vocabulary.md` →
  **leer**). Die gedrehte Release-Aussage und die neue `ratchet release`-Zeile begründen
  **hier** nichts: das Projekt hat **keinen** `ratchet`-Werkzeugbaum, **keine** Baseline
  und **keine** Release-Pipeline. Die **alte** Aussage stand nie in diesem Marker oder im
  Board (gemessen: `git grep -inE 'No tag was pushed|build from source' <stand> --
  AGENTS.skills.md AGENTS.todo.md` → **0** Treffer) — es ist **nichts** zu korrigieren. Die
  Owner-/Ticket-Lücke, die Position 57 festhält, ist unverändert (unten nachgemessen).

**Nicht berührt (17)** — von der Range nicht angefasst, das Verdikt ist der unveränderte
Bestand: `build-verify` → **offen** (Position 52) · `laravel` → **offen** (Position 53) ·
`codegraph-project-setup` → **offen** (Position 54) · `ui-review` → **offen** (Position 55) ·
`generated-docs` → **offen** (Position 56) · `docker-test-image`, `ghcr-visibility`,
`github-ci-filters`, `node-deps`, `playwright-parallel`, `react-i18n`, `security-review` →
**ja** · `agent-config`, `permissions`, `tailscale-serve`, `update-models`, `web-audit` →
**nein**.

**Die Alt-Anker wurden vollständig neu geprüft: 41 Anker-Vorkommen der Tabelle, 41 treffen
zu, 0 gedriftet, 0 tot.** Gezählt so: **38** Anker mit Zeilennummer — **36** in der Form
„`:N`" (Fortsetzung des unmittelbar davor genannten Pfads) **+ 2** in der vollen Form
„`Pfad:N`" (`AGENTS.md:80` und `AGENTS.md:99-101`) — **+ 3** Upstream-Zitate in
**Ankerform** (vorher mit Zeilennummer, unten umgestellt) = **41** Vorkommen; **40** davon
sind verschiedene Anker (`:64` — `e2e-image.yml`, `PNPM_VERSION` — wird aus zwei Zeilen
genannt). Geprüft wurde gegen den **committeten Stand dieses Commits**
— den Datei-Stand, den dieser Commit festschreibt — mit `git show <rev>:<datei>` /
`git grep <rev> -- <pfad>`, nicht gegen einen Working Tree; die Fremddateien sind gegenüber
dem Vorgänger `a4551c8` **unverändert** (`git diff --stat a4551c8..<stand> -- .
':(exclude)AGENTS.skills.md' ':(exclude)AGENTS.todo.md'` → **leer**; `AGENTS.md`
ebenso: `git diff --stat a4551c8..<stand> -- AGENTS.md` → **leer**). **Kein Anker ist
verroten** — kein Zeilenanker ist gedriftet, kein Anker ist tot. Die geprüften Stellen,
Zeile für Zeile der Tabelle:
`build-verify` — `AGENTS.md:114-117` (Build-/Verify-Flow) ·
`docker-test-image` — `e2e-image.yml:67` (Job `build`), `:79` (Schritt „Extract build
args"), `:91-94` (PLAYWRIGHT_VERSION), `:64` (PNPM_VERSION im Workflow-`env:`),
`:81`/`:87` (Step-`env:`), `:93-95` (Prüfung und Outputs), `:107-147` (Schritt
„Resolve base image reference"), `:147` (Wert `base_ref`), `:164` (Verbrauch als
Build-Arg), `Dockerfile.e2e:68`/`:115` (ARG-Block) ·
`generated-docs` — `features/venue-master-data.md:358-368`,
`features/auth/01-auth-and-roles.md:430-454`, in voller Form `AGENTS.md:80` (Pflicht
„Diff gegen den Code") und — **nach der Umstellung unten** — Upstream
`generated-docs/SKILL.md`, Anker „## Numbers in prose" ·
`ghcr-visibility` — `ci.yml:526-530` (GHCR-Kommentar), `:550` (`image: ghcr.io/…`),
`features/05-e2e-test-image.md:167-168` ·
`github-ci-filters` — `ci.yml:63`/`:66` (paths-ignore) ·
`node-deps` — `ci.yml:481`/`:739` (pnpm-Version), `e2e-image.yml:64`,
`Dockerfile.e2e:117` ·
`playwright-parallel` — `ci.yml:792-802` (Worker-Begründung), `:866-870` (Kommandos),
`frontend/playwright.config.ts:24`/`:27` ·
`react-i18n` — `frontend/AGENTS.md:97`, `frontend/vite.config.ts:32-33`,
`frontend/src/main.tsx:1`/`:5` ·
`security-review` — `AGENTS.md:59` (Regressionstest) ·
`ui-review` — `AGENTS.md:262-271` (Loop-Einleitung), `:273-300` (Loop-Schritte 1–4),
`:293-295` (Findings-Report) ·
`ratchet-gates` — in voller Form `AGENTS.md:99-101` (Zero-Pre-existing-Failures-Policy) und
— **nach der Umstellung unten** — Upstream `ratchet-gates/references/vocabulary.md`, Anker
„**A finding needs an owner and a ticket**", und Upstream `ratchet-gates/SKILL.md`, Anker
„an owner and a ticket per entry".
Die dateiweisen Anker ohne
Zeile
(`.githooks/pre-commit`, `.codegraph/.gitignore`, `backend/AGENTS.md`,
`backend/phpunit.xml`, `backend/composer.json`, `backend/config/permissions.php`,
`frontend/package.json`, `frontend/tests/screenshots/`,
`features/security/accepted-risks.md` (A1–A8)) wurden am committeten Stand **dieses
Commits** ebenfalls geprüft. **Kein Anker ist verroten.** Die Zahlen der betroffenen Zeilen
wurden neu gemessen (Position 53: `FormRequest`-Klassen **0**, `rules()` bei **8** von
**23** Controllern, Inline-Array bei **17** — `git grep -l … <stand> -- backend/app…`,
gemessen am Vorgänger `a4551c8`, seitdem unverändert: `git diff --stat a4551c8..<stand> --
backend/` → leer).

**Drei Upstream-Zitate der Tabelle trugen eine Zeilennummer — sie sind auf **Anker**
umgestellt.** Die Zitierregel der Range: „Ein Marker zitiert die Regel, statt sie zu
kopieren — Pfad **im Skill** (ID + Datei) und Anker, **ohne Zeilennummer**: die driftet"
(Zitierstelle `skills-marker/SKILL.md` → „Bezugsrahmen-Regel"; Wortlaut, Belege und Fälle
in `skills-marker/references/notes.md` § „Der Bezugsrahmen"). Die Zeilennummern zeigten
heute noch auf das Richtige — die betroffenen Upstream-Dateien sind in dieser Range
unverändert — aber sie **driften** und sind damit **keine Anker**:

| Stelle | alt | neu | Grund |
|---|---|---|---|
| Tabelle, Zeile `generated-docs` | `generated-docs/SKILL.md:74-80` | Upstream `generated-docs/SKILL.md`, Anker „## Numbers in prose" | Zitierregel der Range: Pfad (Skill-ID + Datei) + Anker, **ohne Zeilennummer**. Treffer am Stand `820c140`: **1** (wie am Vorgänger `0449b05`). |
| Tabelle, Zeile `ratchet-gates` | `ratchet-gates/references/vocabulary.md:44` | Upstream `ratchet-gates/references/vocabulary.md`, Anker „**A finding needs an owner and a ticket**" | dito — **1** Treffer an beiden Ständen. |
| Tabelle, Zeile `ratchet-gates` | `ratchet-gates/SKILL.md:119` | Upstream `ratchet-gates/SKILL.md`, Anker „an owner and a ticket per entry" | dito — **1** Treffer an beiden Ständen; der Anker steht über den Zeilenumbruch. |

**Dieselbe Umstellung im Board** (`AGENTS.todo.md`, die Positionen **52–57** — die
Positionen, die diesem Marker selbst führt; **22** Upstream-Zitate mit Zeilennummer —
gezählt am Stand `0449b05` in diesem Bereich: **20** Backtick-Token mit Zeilennummer, die
**nicht** auf eine Projektdatei zeigen, **+ 2** Klartext-Funde „Zeile 18" (die Regeldatei
`.agents/rules/build-verify.md` nannte ihre Zeile im Fließtext), dazu **17**
Fortsetzungs-Token der Form „`:N`", die denselben Zitierstellen folgen):
Position 52 (`.agents/rules/build-verify.md` „Zeile 18" → **Regel 3** mit Anker;
`.agents/skills/build-verify/SKILL.md:34` und `:74-77` → Anker „Fresh session per round") ·
Position 53 (`.agents/skills/laravel/SKILL.md:20, 60-61` → Anker „one `FormRequest` per
write endpoint" und „Write endpoints validate via `FormRequest`") · Position 55
(`vocabulary.md:10-18`/`:25-33`/`:35-37`/`:44`/`:51`, `findings-report.md:61-62`,
`ui-review/SKILL.md:99-100`/`:99-109`, `references/findings-report.md:18` → je ein Anker) ·
Position 56 (`.agents/skills/generated-docs/SKILL.md:74-80` → Anker „## Numbers in prose") ·
Position 57 (`ratchet-gates/references/vocabulary.md:44`/`:48-50`,
`ratchet-gates/SKILL.md:119-120`/`:119-122` → Anker „**A finding needs an owner and a
ticket**", „**A finding has a stable id.**", „an owner and a ticket per entry"). **Jeder
Anker trifft genau 1-mal** am Stand `820c140`. **Nicht** umgestellt wurde das Zitat in
Position 50 (`SKILL.md:20-25`, `:8-10` des `node-deps`-Skills) — das ist eine **geschlossene**
Position aus einer früheren Runde, und die Regel gilt für Zitate, die dieser Marker
**anwendet**; die Historie des Boards wird hier nicht umgeschrieben.

**Ein Anker im Board war zudem **tot** — er ist korrigiert.** Position 55 nannte
`findings-report.md:17` der **alten** Vorlage; an `820c140` ist diese Zeile **leer**
(`git show 820c140:.agents/skills/ui-review/references/findings-report.md | sed -n '17p'`
→ leer), die alte Spaltenliste stand dort nur im Stand `787670a`
(`git show 787670a:.agents/skills/ui-review/references/findings-report.md | sed -n '17p'`
→ die alte Kopfzeile). Der Verweis ist jetzt an den **benannten** Stand gebunden (der
alte Stand **mit** dem Kommando, das ihn belegt) und verweist für den **heutigen** Stand
auf den Anker der neuen Vorlage.

**Die Zitierstelle löst erst am neuen Stand auf — der Nachweis, alt gegen neu:**
`Bezugsrahmen-Regel` steht im Skill-Verzeichnis am **alten** Cursor `0449b05`
**0-mal** (`git grep -c "Bezugsrahmen-Regel" 0449b05 -- .agents/skills/skills-marker/` →
keine Ausgabe) und am **neuen** Stand `820c140` **7-mal**
(`git grep -n "Bezugsrahmen-Regel" 820c140 -- .agents/skills` → `SKILL.md` **1**,
`references/notes.md` **5**, `templates/AGENTS.skills.md` **1**). Die Zitierstelle kam mit
`65fbecc` (`git rev-list --count 0449b05..65fbecc` → **7** Commits nach dem alten Cursor).
**Vor** dieser Vorrückung hätte dieser Marker eine Stelle zitiert, die an seinem
deklarierten Cursor **nicht existierte**; **jetzt** ist sie **im Frame** — und die Range
dazwischen ist **begangen**: sie berührt **2** Skills, und für **beide** steht eine der
drei Entscheidungen. Das ist die Klausel, die `f7b8f9c` in die Regel aufnimmt: ein Verweis
trägt nur, wenn der Cursor den Stand deckt, in dem die Zitierstelle existiert.

**Die fünf Anker-Zitate, die `734d7d1` umgestellt hat, lösen am neuen Stand weiterhin auf
(jeweils genau 1 Treffer am Stand `820c140`):** `skills-marker/SKILL.md` → „**Angewendet**
heißt **am Code geprüft**, nicht gelesen" · `skills-marker/SKILL.md` → „| Trifft zu, ist
aber nicht drin |" · `skills-marker/SKILL.md` → „im Zweifel gewinnt **ja** mit offener
Position, nicht **nein**" (der Anker steht über den Zeilenumbruch) ·
`skills-marker/templates/AGENTS.skills.md` → „ja | nein" ·
`skills-marker/templates/AGENTS.skills.md` → „"Angewendet" heißt: am Code dieses Projekts
geprüft — nicht: die Doku gelesen.".

**Die offenen Positionen wurden nachgemessen, keine ist gegenstandslos geworden:**
52 — `AGENTS.md:123-125` (Restart-Regel) und `:131` (Verifikator-Rolle) stehen
unverändert im Widerspruch zur Upstream-Fassung, und die Upstream-Sätze selbst sind in
dieser Range **unverändert** (`git diff --stat 0449b05..820c140 -- .agents/skills/
build-verify/ .agents/rules/build-verify.md` → **leer**); 53 — die Zahlen oben; 54 —
`.codegraph/` trägt weiterhin **nur** `.gitignore` (`git ls-tree -r <stand> -- .codegraph/`
→ **1** Eintrag), `core.hooksPath` ist weiterhin **nicht** gesetzt (`git config --list
--show-origin | grep -i hookspath` → keine Ausgabe), `.git/hooks/pre-commit` existiert
weiterhin **nicht**; 55 — der Findings-Report in `AGENTS.md:293-295` führt unverändert die
alte Vorlage; 56 — kein Generator und kein Drift-Gate im Baum (`scripts/` ohne
Doku-Werkzeug, **0** Dateien mit `gen_`/`drift`/`doc_fact`/`generate` im Namen); 57 — kein
Owner und kein Ticket je Registereintrag (`git grep -ciE 'owner|ticket' <stand> --
features/security/accepted-risks.md` → **0** Treffer; das Register trägt weiterhin **8**
Einträge `A1`–`A8` mit kanonischem Status `accepted` und den kanonischen Niveaus
`low`/`medium`).

**Regel-Drift (§5 Schritt 3) auf dieser Maschine: kein Befund.** Die globale
`~/.config/opencode/AGENTS.md` besteht aus **vier** Blöcken (`## CodeGraph` L2,
`## Grundsätze` L12, `## Always-on rules` L23, `## Dieser Host (code-dev)` L29) — **eine**
Regel daraus ist aus `.agents/rules/` eingebettet: die Frage-Regel aus
`.agents/rules/ask.md`. Die anderen drei Blöcke haben eigene Quellen (CodeGraph-Setup,
allgemeine Projekt-Grundsätze, Host-Beschreibung) und sind **keine**
`.agents/rules/`-Einbettung. **Keine Regeldatei ist in dieser Range geändert**
(`git diff --stat 0449b05..820c140 -- .agents/rules/` → **leer**), die Pflicht der
eingebetteten Frage-Regel ist also unverändert — es ist **nichts** nachzuziehen. Die
`skills-marker`-Regel selbst ist **keine** eingebettete Regel: die globale `AGENTS.md`
verweist auf den Skill, nicht auf eine Kopie seiner Fassung. `agent-config` §2a und die
Aussage der globalen Config decken sich weiterhin: `~/.config/opencode/opencode.jsonc:7`
sagt „Kein `instructions`-Key".

Dieser Abschnitt wird beim nächsten Pull im Skills-Repo durch den neuen
Range-Abschnitt (`<dieser-sha>..<neuer-sha>`) ersetzt; bleibt die Liste leer,
entfällt er ganz.

## Fortschreiben

1. `agents-skills-consumed` ablesen → `<alt>`.
2. Im Skills-Repo die Range bilden (erst **nach** dem Pull):
   `git -C <skills-repo> log --oneline <alt>..HEAD`
   `git -C <skills-repo> log --name-only --format= <alt>..HEAD -- .agents/skills | sort -u`
   `git -C <skills-repo> log --diff-filter=A --name-only --format= <alt>..HEAD -- .agents/skills`
3. Jeden betroffenen Skill gegen **dieses** Projekt prüfen. Trifft eine Änderung zu, wenn
   sie eine Pflicht, Schwelle, Ausnahme, Konvention oder ein Kommando betrifft, das
   **hier** gilt:
   - trifft zu und ist **erfüllt** → `angewendet: ja` + **stabile Referenz** (Abschnitt/
     Überschrift/Suchstring; Zeilennummer nur als Lesehilfe)
   - trifft zu, ist aber **nicht erfüllt** → `angewendet: offen` + offene Position:
     Eintrag in `AGENTS.todo.md` anlegen
   - trifft nicht zu (das Projekt hat das nicht) → `angewendet: nein` + kurzer Grund
   - **gelöschte Skills** nicht stillschweigend weg: im Bereichs-Abschnitt
     „`<skill>` — entfernt in `<sha>`" notieren, dann verschwindet die Zeile
   - die **drei** Werte `ja` / `nein` / `offen` sind disjunkt: `ja` = trifft zu und ist
     erfüllt, `nein` = trifft nicht zu, `offen` = trifft zu und ist nicht erfüllt
     (die Lücke steht dann als Position im Board)
4. **Alle bestehenden Anker neu prüfen** — Advancing ist kein Updating; eine
   Zeilennummer in einer wachsenden Datei hat ein Verfallsdatum. **Lokale Ergänzung:**
   Gemessen wird gegen den **committeten** Stand (`git show <rev>:<datei>`), nicht gegen
   einen Working Tree. Der **Bezugsrahmen** steht im **Kopf** dieser Datei — als
   Zitierstelle, nicht als Kopie (Upstream `skills-marker/SKILL.md` → „Bezugsrahmen-Regel").
5. Offene Positionen nach `AGENTS.todo.md`; Belege als stabile Referenz.
6. Erst wenn für **jeden** Skill aus der Range eine Zeile steht:
   `agents-skills-consumed: $(git -C <skills-repo> rev-parse --short HEAD)`,
   `geprüft am:` auf heute, Abschnitte ersetzen.
7. Falls Regeln oder Skills betroffen waren: die eingebetteten Regeln in der
   globalen `~/.config/opencode/AGENTS.md` gegen ihre Quelle in `.agents/rules/*`
   prüfen und bei geänderter **Pflicht** sinngemäß nachziehen. Drift ist eine
   geänderte Pflicht, kein abweichender Wortlaut — die Einbettung ist die Kurzform
   der Pflicht, kein wörtliches Abbild. Dann `touch ~/.config/opencode/opencode.jsonc`
   (Reload der globalen Config; ein `instructions`-Key existiert dort **nicht** —
   `~/.config/opencode/opencode.jsonc:7` sagt das ausdrücklich: die always-on
   Regeln stehen inline in der globalen `AGENTS.md`).
8. **Jede Zahl**, die etwas über den Commit oder seine Gates behauptet, nennt ihren
   **Bezugsrahmen** (Zustand: Working Tree/committeter Stand; Umfang: gefilterte
   Teilmenge, Range, Stufe) — Regel 5 des `build-verify`-Rule-Files, seit `0449b05`.
