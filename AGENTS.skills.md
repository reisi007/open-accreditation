# Skills-Marker

agents-skills-consumed: 5243791
geprüft am: 2026-10-21

Bezugsrahmen — die gemeinsame Regel, **zitiert statt kopiert**: `skills-marker/SKILL.md` →
„Bezugsrahmen-Regel" (Skill-ID + Datei + Anker, **ohne Zeilennummer** — die driftet, der
Anker bleibt); Wortlaut, Belege und Fälle: `skills-marker/references/notes.md`
§ „Der Bezugsrahmen". **Lokale Ergänzung:** `<stand>` = der committete Stand dieses
Commits (die SHA, die erst mit dem Commit existiert), `<messstand>` = der Stand, an dem
gemessen wurde. Der Cursor ist **kein** Rahmen — er zeigt den Stand des Skills-Repos.

> **Sechstmarker** — der Cursor-Stand `5243791` ist der **sechste Marker-Stand** dieses
> Markers; der Cursor hat dabei **sieben** verschiedene Werte geführt (`aad25d1` → `6629ad2`
> → `787670a` → `0449b05` → `f7b8f9c` → `820c140` → `5243791`; **sieben** Werte, gemessen am
> committeten Stand dieses Commits: `git log --format=%h -- AGENTS.skills.md` und `git show
> <sha>:AGENTS.skills.md | grep -m1 '^agents-skills-consumed:'` — jeder Wert mit seinem
> **ersten** Commit: `aad25d1` → `e4ee755`, `6629ad2` → `bbf02fd`, `787670a` → `9170e03`,
> `0449b05` → `a4551c8`, `f7b8f9c` → `7304a03`, `820c140` → `6c544a9`, `5243791` → **dieser
> Commit**; die beiden ältesten Werte stehen in der Datei in voller 40-Zeichen-Form).
> **Zwischenstand ohne eigenen Marker:** der Commit `7304a03` führte den Cursor
> zwischenzeitlich auf `f7b8f9c`; dieser Zwischenstand wurde von `6c544a9` auf `820c140`
> **erweitert** und ist deshalb **kein** eigener Marker-Stand — die **sechs** Marker-Stände
> sind `aad25d1`, `6629ad2`, `787670a`, `0449b05`, `820c140` und `5243791`.
> **Lokale Ergänzung:** die Cursor-Historie wird **nicht** handgepflegt, sondern
> **abgeleitet** — das Kommando steht oben; es liefert je Wert seinen **ersten** Commit.
> Die Regel, die diese Zählung trägt — „eine Aufzählung trägt zwei Behauptungen: jedes
> Element trägt die Eigenschaft **und** die Liste ist die ganze Menge" — steht im
> Skills-Stand `37cefe2` (`skills-marker/SKILL.md`, Anker „Eine Aufzählung trägt zwei
> Behauptungen"; **1** Treffer in der Datei, gemessen am Stand `37cefe2`; am Stand `5243791`
> ebenfalls **1** Treffer — `SKILL.md` ist zwischen beiden Ständen unverändert). Die Klausel
> ist **Teil der zitierten Regel**, keine eigene Zusatzregel: sie ist die **fünfte** der fünf
> Regeln im Absatz, den der Anker `Bezugsrahmen-Regel` benennt — sie wird hier
> **angewendet**, nicht als lokale Regel geführt.
> Geprüfte Range `820c140..5243791` — **9 Commits** des Skills-Repos
> (`git -C <skills-repo> rev-list --count 820c140..5243791` → **9**, gemessen am
> **Skills-Repo-Commit `5243791`**, den dieser Marker konsumiert; **Messzeitpunkt
> 2026-10-21, 22:04 UTC**). **Der Cursor `5243791` ist der Stand des Skills-Repos, den dieser
> Marker konsumiert.** Bei der **ersten** Messung (2026-10-21, **22:04 UTC**) und bei der
> **zweiten** — der nach dem Lauf der Anker-Prüfung (2026-10-21, **22:10 UTC**) — stand der
> Skills-Repo-HEAD auf `5243791`: der Stand ist während dieses Laufs **nicht**
> weitergewandert. **Der Cursor ist 9 Commits
> vorgerückt** (`git -C <skills-repo> rev-list --count 820c140..5243791` → **9**).
> Die Range berührte **genau 5 Skill-Verzeichnisse** unter `.agents/skills/`
> (`git log --name-only --format= 820c140..5243791 -- .agents/skills | cut -d/ -f3 |
> sort -u` → **5**): `skills-marker` (6 Commits: `37cefe2`, `c056e75`, `857070a`,
> `4205142`, `842e055`, `5243791`), `github-ci-filters` (1 Commit `7328fc3`),
> `ui-review` (1 Commit `2e09608`), `build-verify` (1 Commit `80664ab`) und `agent-config`
> (1 Commit `80664ab`). **Die drei Commits, die der vorige Marker als ungeprüften Rest
> geführt hat, sind jetzt Teil der geprüften Range:** `37cefe2` (`skills-marker`, `SKILL.md`,
> `references/notes.md`, `templates/AGENTS.skills.md`; **89** Einfügungen, **35** Löschungen),
> `7328fc3` (`github-ci-filters`, `SKILL.md`, `references/notes.md`; **189** Einfügungen,
> **123** Löschungen) und `c056e75` (`skills-marker`, nur `references/notes.md`; **21**
> Einfügungen, **14** Löschungen). Für **alle drei** steht in diesem Abschnitt eine der drei
> Entscheidungen; der ungeprüfte Rest ist damit **leer**.
> **Neu in der Range:** **0** Skills (`git log --diff-filter=A --name-only --format=
> 820c140..5243791 -- .agents/skills` → **leer**) — **neu versteckt: 0** —
> **entfernt: 0**. **Versteckt gesamt:** unverändert **17** von **19** Skills mit
> `metadata.opencode/autoinvoke: false` (Frontmatter-Messung an beiden Revs: die Range fasst
> **keine** Frontmatter an — `git diff 820c140..5243791 -- .agents/skills/*/SKILL.md | grep
> -cE '^[+-].*autoinvoke'` → **0**; `git grep -l "opencode/autoinvoke: false" <rev> --
> .agents/skills/*/SKILL.md` → **17** an beiden Revs, `git ls-tree -d --name-only <rev> --
> .agents/skills/` → **19** Skill-Verzeichnisse an beiden Revs).
> **Nicht-Skill-Dateien in der Range:** **kein** Commit der Range berührt **überhaupt** keinen
> `.agents/`-Pfad — **zwei** Commits tragen zusätzlich zu ihren Skill-Dateien eine Datei
> **außerhalb** der Skill-Verzeichnisse: `2e09608` schreibt das `AGENTS.todo.md` des
> Skills-Repos (**0** Einfügungen, **23** Löschungen — die dortige Board-Position „ui-review/
> SKILL.md still carries its own severity list" ist damit **geschlossen**), `80664ab` schreibt
> die **Regeldatei** `.agents/rules/build-verify.md` (**2** Einfügungen, **2** Löschungen).
> **Lokale Ergänzung:** Jede Zeile nennt eine **stabile Referenz** (Abschnitt,
> Überschrift oder Suchstring); Zeilennummern nur als Lesehilfe. Der Pfad zum
> Skills-Repo steht hier bewusst nicht — der Marker bleibt bei einer Verschiebung gültig.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Konfiguration (globale `opencode.jsonc`, MCP, Skill-/Command-Registrierung) — das Projekt hat keine `opencode.jsonc` und keine Modell-/MCP-Datei. **Seit `817c01e` hidden** (nur per exakter ID ladbar) — das ändert die Anwendbarkeit nicht: die Pflicht bleibt eine Pflicht der Maschine, nicht des Projekts. **Die Änderung in `80664ab` betrifft §6 (die Commit-Konvention)**: die neue Ausnahme („eigener frischer, gepushter Commit darf per `--force-with-lease` ersetzt werden") ist dort **ausdrücklich dem Skills-Repo verweigert** („This repo does not take it") — das ist eine Aussage über ein **anderes** Repo und keine Pflicht dieses Projekts |
| `build-verify` | offen | `AGENTS.md` §5 Punkt 4 „Build-/Verify-Flow — zentral, nicht hier" (`:114-117`) | Position 52 — zwei Sätze in `AGENTS.md` §5 widersprechen der Skill-Fassung; die zitierten Upstream-Sätze sind in dieser Range **unverändert**. **Neu in dieser Range (`80664ab`):** Regel 6 der `.agents/rules/build-verify.md` hat eine **Ausnahme** bekommen — der eigene, frische, gepushte Commit darf per `--force-with-lease` ersetzt werden. **Dieses Projekt trägt keine eigene, strengere Amend-Policy** (gemessen am committeten Stand dieses Commits: `grep -n -iE 'amend\|unpushed\|force-with-lease' AGENTS.md` → **0** Treffer; repo-weit über die eigenen `.md`-Dateien ebenfalls **0**) — die Ausnahme gilt hier also **unverändert**; ihr Nutzen ist durch die Markdown-Filter begrenzt (ein `.md`-Commit löst keinen CI-Lauf aus) |
| `codegraph-project-setup` | offen | `.githooks/pre-commit` (CodeGraph-Sync-Hook, versioniert — **läuft aber nicht**: `core.hooksPath` nirgends gesetzt, `.git/hooks/pre-commit` fehlt); `.codegraph/` trägt **nur** `.gitignore`, **keinen** Index (`codegraph status`, gemessen 2026-10-21: „⚠ Not initialized") | Position 54 — der Pflichtteil des Skills (Index + verdrahteter Hook) ist **nicht erfüllt**; am Vorgänger `a4551c8` gemessen, seitdem unverändert (`git diff --stat a4551c8..<stand> -- .codegraph/` → leer) |
| `docker-test-image` | ja | `.github/workflows/e2e-image.yml` (Job `build` `:67`; PLAYWRIGHT_VERSION-Auflösung `:91-94` im Schritt „Extract build args from repository" `:79` — der `PNPM_VERSION`-Wert steht im **Workflow**-`env:` `:64`; der Step holt ihn in seinem **Step**-`env:` `:81`/`:87` (`PNPM_VERSION: ${{ env.PNPM_VERSION }}`) und prüft ihn `:93-95`; ein **Job**-`env:`-Block existiert nicht (der Job `build` hat keinen); **BASE_REF**-Auflösung im eigenen Schritt „Resolve base image reference (digest pass-through)" `:107-147`, der Wert `base_ref` steht in `:147` und wird `:164` als Build-Arg verbraucht), `deployment/Dockerfile.e2e` (ARG-Block `:68`, `:115`), `features/05-e2e-test-image.md` | keine |
| `generated-docs` | offen | `features/venue-master-data.md` „Portabilität (§2)" (`:358-368`: die Gate-Zahlen nennen Lauf `37280377310`, Datum und Leser — die Zahl steht **in** der Prosa), `features/auth/01-auth-and-roles.md` (`:430-454`: die unbezifferte Zahl wurde durch **andere Prosa** ersetzt) | **Position 56** — die Pflicht trifft zu und ist **nicht erfüllt**: das Projekt hat **weder einen Generator noch ein Drift-Gate** (gemessen am Vorgänger `a4551c8`, seitdem unverändert — `git diff --stat a4551c8..<stand> -- scripts/` → leer: `scripts/` enthält nur `assert-db-driver.php`, `dev-worker.sh`, `e2e-up.sh`, `lib/`, `pdf-to-png-vision.sh`, `render-badge-photo-placeholder.mjs`, `test-pgsql.sh`; im committeten Baum **keine** Datei mit `gen_`/`drift`/`doc_fact`/`generate` im Namen). Die Skill-Regel nennt die Zahl in Prosa ausdrücklich als Fehlerfall (Upstream `generated-docs/SKILL.md`, Anker „## Numbers in prose" — **ohne Zeilennummer**, die driftet; **1** Treffer am Stand `5243791`, die Datei ist in dieser Range unverändert). **Kein „nein"**, weil das Projekt die Pflicht in eigener Form trägt: `AGENTS.md:80` („für ein Dokument Diff gegen den Code, nicht gegen die Absicht") — die Lücke ist der **fehlende maschinelle Check**, nicht die fehlende Pflicht |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` (Kommentar „ein public GHCR-Package" `:526-530` + `image: ghcr.io/…` `:550`), `features/05-e2e-test-image.md` (`:167-168`) | keine — beide Packages public (geprüft 2026-10-20: `gh api visibility=public` für `accriditation-e2e` und `accriditation-base`) |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` → `paths-ignore: '**/*.md'` auf `push` **und** `pull_request` (`:63`, `:66`); die **Include-Listen** der beiden Image-Workflows `.github/workflows/base-image.yml` (`:67-69`) und `.github/workflows/e2e-image.yml` (`:43-47`) | keine — **seit `817c01e` hidden**: die Pflicht gilt unverändert, nur der Ladeweg ändert sich (per exakter ID). **Neu in dieser Range (`7328fc3`):** Schritt 2 verbietet jetzt nicht mehr das Wort `paths`, sondern die **auswählende** Positivliste — die **abziehende** Form (`paths-ignore`, wie in `ci.yml`) bleibt erlaubt, die **Catch-all**-Form (`paths` mit `'**'`) ist neu ausdrücklich erlaubt, die **auswählende** Form (wie in `base-image.yml`/`e2e-image.yml`) bleibt **verboten**. Die Pflicht dieses Projekts (Docs-only-Commits sollen die Pipeline nicht starten) ist damit in `ci.yml` **erfüllt**; die **bewusste Abweichung** der beiden Image-Workflows — dort ist die Include-Liste **Build-Eingabe**, nicht Doku-Filter — ist an beiden Stellen **dokumentiert** (Kommentarblock in `e2e-image.yml`, Verweis auf die ausführliche Begründung in `base-image.yml`) und wird durch die neue Fassung **nicht legalisiert** |
| `laravel` | offen | `backend/AGENTS.md` (Commands, DB-/Migrations-Policy, Parallel-Testing, Datei-Tests), `backend/phpunit.xml` (Hermetik-Pins), `backend/composer.json` (PHP ^8.5, pint, paratest, jwt-auth) | Position 53 — Validierungs-Konvention nicht umgesetzt; am Vorgänger `a4551c8` gemessen, seitdem unverändert (`git diff --stat a4551c8..<stand> -- backend/` → leer): `FormRequest`-Klassen **0**, `rules()` bei **8** von **23** Controllern **mit Schreib-Endpunkten** (store/update/destroy/create/edit), Inline-Array bei **17** Controllern **insgesamt** (unabhängig von der Schreib-Endpunkt-Eigenschaft) |
| `node-deps` | ja | `frontend/package.json` (keine Pins/Overrides), `ci.yml` (`version: 11` `:481`, `:739`), `e2e-image.yml` (`PNPM_VERSION: "11"` `:64`), `deployment/Dockerfile.e2e` (`ARG PNPM_VERSION=11` `:117`) | keine |
| `permissions` | nein | | opencode.jsonc-Policy (Maschine) — das Projekt definiert keine; `backend/config/permissions.php` ist die Gate-Matrix des Projekts, nicht diese Policy |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` (Run-Step: die Worker-Begründung `:792-802`, die Kommandos `:866-870`), `frontend/playwright.config.ts` (`fullyParallel: true` `:24`, `workers: process.env.CI ? 4 : 8` `:27`) | keine |
| `ratchet-gates` | offen | `features/security/accepted-risks.md` (A1–A8: kanonischer Status `accepted` + kanonische Niveaus `low`/`medium`, stabile Ids — **getragen**), `backend/config/permissions.php` („Single source of truth for the authorization layer") | **Position 57** — die **Owner-/Ticket-Regel** fehlt: Upstream `ratchet-gates/references/vocabulary.md`, Anker „**A finding needs an owner and a ticket**" (unless it is `fixed`) — und Upstream `ratchet-gates/SKILL.md`, Anker „an owner and a ticket per entry" (der Anker steht über den Zeilenumbruch). **Beide sind ohne Zeilennummer zitiert** (die Zeilennummer driftet) und **beide sind unverändert**: **1** Treffer je Anker am Stand `5243791` wie am Vorgänger `820c140` (`git diff --stat 820c140..5243791 -- .agents/skills/ratchet-gates/SKILL.md .agents/skills/ratchet-gates/references/vocabulary.md` → leer). Die Regeln waren in der Range `787670a..0449b05` **neu**; das Projekt trägt sie bis heute nirgends. **Die Range `820c140..5243791` hat diesen Skill nicht berührt** (`git diff --stat 820c140..5243791 -- .agents/skills/ratchet-gates/` → **leer**) — das Verdikt `offen` und die Position **57** bleiben deshalb unverändert. **Die Range `0449b05..820c140` hat diesen Skill berührt** (`d5dc52f` → `references/cli.md`), **an der Gate-Substanz aber nichts**: die geänderte Datei ist die CLI-Karte, nicht die Gates (Niveaus, Stände, Zählregel, Owner-/Ticket-Regel, Baseline-Teil) — das Verdikt `offen` und die Position **57** bleiben deshalb sachlich richtig. Die Release-Aussage dreht sich um („**No tag was pushed yet** … build from source" → „**The releases are published**"), das Projekt hat **keinen** `ratchet`-Werkzeugbaum, **keine** Baseline und **keine** Release-Pipeline, und die **alte** Aussage stand nie in diesem Marker oder im Board (gemessen: **1** Treffer am committeten Stand, **allein** ist das Zitat des Markers selbst; im Board **0**; am Vorgänger `734d7d1` **0** — Abfrage, Umfang und Belege im Range-Abschnitt) — es ist **nichts** zu korrigieren. **Zwei Messungen, zwei Rahmen:** das **Register** ist eine Fremddatei — am Vorgänger `a4551c8` gemessen, seitdem unverändert (`git diff --stat a4551c8..<stand> -- features/security/accepted-risks.md` → leer): `git grep -ciE 'owner|ticket' a4551c8 -- features/security/accepted-risks.md` → **0** Treffer. Der **Board** ist eine der beiden geänderten Dateien — daher am **committeten Stand dieses Commits** gemessen, und die Kommandos sind auf **`<stand>` gepinnt**, damit der Leser genau den Stand setzt, den der Rahmen nennt (die Form aus dem Marker von `/projects/LuminaRust`): `git grep -inE '\bowner\b|\bticket\b' <stand> -- AGENTS.todo.md` trifft ausschließlich den **Meta-Text** (die Kopfzeile mit dem Positions-Index und die Positionstexte 55–57), in den **Befund-Zeilen der Batch-Tabelle** (`| **N** | …`) **0** (`git show <stand>:AGENTS.todo.md | grep -E '^\| \*\*[0-9]+\*\* \|' | grep -icE '\bowner\b|\bticket\b'` → 0). **Der Ratchet-Teil ist nicht anwendbar** — dafür ist **keine** Position nötig, das ist eine begründete Abweichung: im **Projektbaum** gibt es **kein** Baseline-Artefakt im Sinne des Skills (ein festgehaltenes bekannter Fehler). **Projektbaum** (die beiden geänderten Doku-Dateien ausgeschlossen; am Vorgänger `a4551c8` gemessen — der Projektbaum ist gegenüber dem Vorgänger unverändert, **45** Zeilen-Treffer in **20** Dateien an beiden Ständen): `git grep -in baseline 2da0404 -- . ':(exclude)AGENTS.skills.md' ':(exclude)AGENTS.todo.md'` → **45**; Verteilung `items-baseline` (Tailwind-CSS-Klasse) **10**, `$baseline` (PHP-Variable) **9**, `baseline-browser-mapping` (npm-Paket im Lockfile) **3**, Rest **23**; nach Bereich: 1 Dokumentationsdatei, 10 Backend-Dateien (davon 7 in `backend/tests/`), 9 Frontend-Dateien. **Repo-weit** (mit den beiden Doku-Dateien) sind es **53** Zeilen-Treffer in **22** Dateien am committeten Stand dieses Commits — **Lokale Ergänzung:** dieser Wert kann **keinen** Vorgänger-Rahmen tragen, weil die Doku Teil des Repos ist. Ebenso **kein** known-failure/xfail-Marker im Projekt: mit **Wortgrenze** (`\bxfail\b`) liegen die Treffer im Repo **ausschließlich** in der **Doku, die das Muster selbst nennt** (dieser Marker, das Board) — in `backend/`, `frontend/`, `features/`, `deployment/`, `AGENTS.md`: **0**; ohne Wortgrenze kommen die `maxFailures`-Stellen dazu: **30** Zeilen-Treffer am Stand `a4551c8` (**14** in Konfiguration und Code: `ci.yml` 8, `frontend/playwright.config.ts` 1, `frontend/playwright.regression.config.ts` 5; **16** in Prosa: `AGENTS.todo.md` 5, `features/05-e2e-test-image.md` 4, `AGENTS.md` 3, `Agents.headless.md` 3, `frontend/tests/e2e/helpers/admin-data.ts` 1 — davon **15** in Markdown-Prosa und **1** als Prosa in einer Code-Kommentarzeile), **25** Zeilen-Treffer im **Projektbaum** (die beiden geänderten Doku-Dateien ausgeschlossen; an den Ständen `a4551c8`, `2da0404`, `c353313` und `78051f0` identisch — der Vorgänger-Vergleich trägt nur das, was **gleich** bleibt: `git diff --stat 2da0404..78051f0 -- . ':(exclude)AGENTS.skills.md' ':(exclude)AGENTS.todo.md'` → leer; dieser Commit fasst nur die beiden Doku-Dateien an, der Wert gilt an seinem committeten Stand genauso) und repo-weit **32 Zeilen-Treffer, vorkommensweise 34 Vorkommen** am committeten Stand dieses Commits — **davon 7 Trefferzeilen mit 9 Vorkommen in den beiden geänderten Doku-Dateien selbst** (dieser Marker **1** Zeile mit **2** Vorkommen, der Board **6** Zeilen mit **7** Vorkommen) — deshalb die Wortgrenze, denn ohne sie zählt jede `maxFailures`-Stelle mit. `AGENTS.md:99-101` verbietet getragene Altfehler (Zero-Pre-existing-Failures-Policy), und die **7** `markTestSkipped` (gemessen am Vorgänger `a4551c8`, 5 Dateien) sind je **umgebungsbedingt** und mit Ursache benannt, also kein getragener Fehlerbestand |
| `react-i18n` | ja | `frontend/AGENTS.md` (§ „Lingui / i18n — No Module-Scope `t` (STRICT)" `:97`), `frontend/vite.config.ts` (lingui-Plugin + Babel-Preset `:32-33`), `frontend/src/main.tsx` (I18nProvider als erster Import `:1`, `:5`), `frontend/package.json` (`lingui:extract`/`lingui:compile`, `check:i18n` in `prebuild`) | keine — das Projekt weicht bewusst ab: zwei Locales (de+en) mit Vollständigkeits-Gate statt der Portal-Referenz (eine Locale + Rohstring-Pass) |
| `security-review` | ja | `AGENTS.md` §10 (Fundort der Befunde) + `features/security/accepted-risks.md` (Register A1–A8), `AGENTS.md` §3 (Regressionstest je Fix `:59`) | keine — das Register trägt die kanonischen Stände (`accepted`) und Niveaus (`low`/`medium`) mit stabilen Ids; die Klassen BELEGT/HEURISTIK/AKZEPTIERT bleiben daneben stehen und kollidieren nicht **Board-Messung am committeten Stand dieses Commits** (der Board ist eine der beiden geänderten Dateien): die Klassen-Namen in **Großschreibung** (`BELEGT`/`HEURISTIK`/`AKZEPTIERT`/`ZURÜCKGENOMMEN`) stehen **0** Mal im Board — **case-sensitiv** gemessen (`git show <stand>:AGENTS.todo.md | grep -cE 'BELEGT|HEURISTIK|AKZEPTIERT|ZURÜCKGENOMMEN'` → 0; `<stand>` = committeter Stand dieses Commits); das case-insensitive Muster greift zusätzlich Prosa wie „belegt“/„akzeptiert“ und taugt hier nicht. Als Schweregrade kommen dort die kanonischen Wörter vor — `critical`/`high`/`medium`/`low`/`info` (im **Board**, am committeten Stand dieses Commits, **case-sensitiv** gezählt: `critical` **18**, `high` **24**, `medium` **11**, `low` **10**, `info` **3** Zeilen) — und **ein** `Low` in Großschreibung, das case-sensitiv ein eigenes Token ist (**1** Zeile: „ihr Low (unterminierter Docblock)"; das **ist** ein Schweregrad, ein `low` in Prosa). Das **zweite** `Low` (**1** Zeile: „das Low-Byte des Adler-32-Trailers") ist **kein** Schweregrad, sondern die Bytestufe einer Prüfsumme (`git show <stand>:AGENTS.todo.md | grep -nE '\bLow\b'` → **2** Zeilen; `<stand>` = committeter Stand dieses Commits). `Major` (**3** Zeilen), `hoch` (**1**), `niedrig` (**1**) kommen nur in Prosa vor und sind **keine** Schweregrade |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei; Abschnitt „Fortschreiben"; die Regel wird **zitiert statt kopiert** — Upstream `skills-marker/SKILL.md`, Anker „Bezugsrahmen-Regel" (Skill-ID + Datei + Anker, **ohne Zeilennummer**), belegt im Kopf dieser Datei) | keine |
| `tailscale-serve` | nein | | kein Tailscale-Bezug im Projekt (repo-weiter Treffer: nur diese Datei) |
| `ui-review` | offen | `AGENTS.md` §7 „Visuelle Verifikation / UI-Review (Design-QA, STRICT)" (`:262-271`, Loop-Schritte 1–4 `:273-300`), `frontend/tests/screenshots/` | Position 55 — der Findings-Report in `AGENTS.md` §7 Schritt 3 (`:293-295`) führt die **alte** Vorlage (4 Spalten, 4 Niveaus, kein Statusfeld, keine Id/Eigentümer/Ticket). **Neu in dieser Range (`2e09608`):** Upstream nennt jetzt die **neun** Spalten der Vorlage und verweist für die Schweregrade auf das **kanonische Vokabular** (fünf Stufen mit `info`) statt auf eine eigene Liste — die offene Position bekommt damit erstmals ein **konkretes Ziel**; am Projekt ändert sich **nichts** (der Abschnitt in `AGENTS.md` §7 Schritt 3 ist unverändert), die Position **bleibt offen** |
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

## Offen aus dem Bereich 820c140..5243791 (2026-10-21)

> **Herkunft:** Fortschreibung des Markers `AGENTS.skills.md`
> (`agents-skills-consumed: 820c140` → `5243791`). Die Range umfasst **9 Commits**
> und berührte **genau 5 Skill-Verzeichnisse**. **Die Tabelle ist ein Zustand, kein Auftrag.**
> Die **5** von der Range berührten Skill-Verzeichnisse sind **keine** offenen Positionen und
> **keine** 5 unerledigten Arbeiten: die Tabelle sagt je Skill, ob seine Pflicht hier **trifft**
> und ob sie **erfüllt** ist. **Lokale Ergänzung:** eine Bedeutung von „offen" — und sie
> ist nicht „ungeprüft". Das **Verdikt `offen`** heißt in dieser Tabelle: *geprüft, die
> Pflicht trifft zu und ist nicht erfüllt* — die Lücke steht als **Position** im Board.
> Die **Prüfung** selbst ist für **alle fünf** Skill-Verzeichnisse aus dieser Range
> abgeschlossen; alle fünf Zeilen sind entschieden. Eine **Lücke** ist **gemessen** und
> steht als **Position** im Board (`AGENTS.todo.md`): 52 (`build-verify`), 53 (`laravel`),
> 54 (`codegraph-project-setup`), 55 (`ui-review`), 56 (`generated-docs`), 57
> (`ratchet-gates`) — alle **sechs** sind `offen`-Zeilen. Beides ist ein Log, kein
> Arbeitsauftrag.

**Je Commit der Range, in der Reihenfolge der Range** — jeder Effekt am **jeweiligen
Commit** gemessen (`git show --stat <sha>`, `git show --numstat <sha>`):

| Commit | Was |
|---|---|
| `37cefe2` | **`skills-marker`** — die **Nomen-Klausel** kommt in den Absatz der Zitierstelle: „das **Nomen** („Vorgänger" / „Stand") entscheidet den Fall **nicht**" (bisher: „nicht am Wort" — wörtlich falsch, weil die beiden Formen sich gerade im Wort unterscheiden); und die **fünfte** Regel kommt dazu: „**Eine Aufzählung trägt zwei Behauptungen:** jedes genannte Element trägt die Eigenschaft, und die Liste ist die ganze Menge — beide sind zu belegen. Eine handgepflegte Aufzählung wird durch ein Kommando ersetzt, das die Menge ableitet; eine Liste, die der Commit selbst fortschreibt, wächst nicht mit." `SKILL.md` **+17/−17**, `references/notes.md` **+65/−15**, `templates/AGENTS.skills.md` **+7/−3** (**89** Einfügungen, **35** Löschungen). **Entscheidung: `ja`** — die Regel, nach der dieser Marker gebaut ist, ändert sich; sie ist keine Pflicht, die das Projekt trägt. **Der Anker `Bezugsrahmen-Regel` steht am neuen Stand genau **1**-mal in `skills-marker/SKILL.md`** (wie am Vorgänger) — die Zitierstelle dieses Markers bleibt gültig. |
| `7328fc3` | **`github-ci-filters`** — Schritt 2 trennt jetzt **Auswahl** von **Subtraktion**: „**Selecting is the case to prevent**" — ein `paths`-Block, dessen Positivteil nur bestimmte Pfade **auswählt**, ist verboten; ein `paths`-Block mit dem Catch-all `'**'` **subtrahiert** und ist erlaubt. Schritt 4 bekommt **benannte Diff-Endpunkte**: `git diff --stat <sha-before>..<sha-after> -- .github/workflows/` (der Endpunkt ist der **benannte Commit**, dessen Aussage belegt wird, nicht `HEAD`). `SKILL.md` **+78/−102**, `references/notes.md` **+111/−21** (**189** Einfügungen, **123** Löschungen). **Entscheidung: `ja`** — die Pflicht trifft in diesem Projekt zu und ist erfüllt (abziehende Form in `ci.yml`); die Abweichung der beiden Image-Workflows bleibt eine bewusste, dokumentierte Abweichung. |
| `c056e75` | **`skills-marker`** — nur `references/notes.md` (**+21/−14**): der Abschnitts-Titel, der die gerade entfernte Behauptung mit einem anderen Nomen wiederholte, nennt nur noch die Zeitform; zwei falsche Zahlen und ein Zitat werden korrigiert. **Entscheidung: `ja`** — die Regel selbst ändert sich nicht; die Datei ist der Beleg-Teil der Zitierstelle. |
| `857070a` | **`skills-marker`** — nur `references/notes.md` (**+13/−11**): zwei im Skill nicht definierte Begriffe werden durch benannte Regeln ersetzt, sechs Zitate, die in der Mitte endeten, bekommen eine Auslassungsmarke. **Entscheidung: `ja`** — wie `c056e75`. |
| `4205142` | **`skills-marker`** — nur `references/notes.md` (**+13/−11**): sieben Zitate werden wörtlich oder mit Auslassungsmarke, der Abschnittstitel wird vollständig genannt, zwei Zahlen bekommen ihren Umfang. **Entscheidung: `ja`** — wie `c056e75`. |
| `842e055` | **`skills-marker`** — nur `references/notes.md` (**+1/−1**): eine Auslassungsmarke an einem Zitat. **Entscheidung: `ja`** — wie `c056e75`. |
| `2e09608` | **`ui-review`** — `SKILL.md` **+5/−9**: die Findings-Vorlage wird mit den **neun** Spalten zitiert (`Id | Severity | Status | Owner | Ticket | File:Line | Screenshot | Finding | Suggested fix`) statt mit den alten fünf; die eigene Niveaus-Liste (vier Stufen) weicht dem **kanonischen Vokabular** (`ratchet-gates`, `references/vocabulary.md` — fünf Stufen mit `info`). Zusätzlich schreibt der Commit das `AGENTS.todo.md` des **Skills-Repos** (**0** Einfügungen, **23** Löschungen): die dortige Board-Position „ui-review/SKILL.md still carries its own severity list" ist damit **geschlossen**. **Entscheidung: `offen`** (Position 55 bleibt) — die Pflicht trifft zu und ist im Projekt **nicht erfüllt**. |
| `80664ab` | **`build-verify`** — Regel 6 der `.agents/rules/build-verify.md` bekommt eine **Ausnahme**: der eigene, frische, gepushte Commit darf per `--force-with-lease` ersetzt werden, wenn der Remote-Head genau dieser Commit ist, keine fremde Arbeit im Baum liegt und der Vorgänger offengelegt wird (sechs prüfbare Bedingungen, eine Ersetzung je Commit, ein grüner Lauf des ersetzten Commits ist **nichtig**); `references/amend.md` **+43/−5** (Bedingungen, CI-Regel, Repo-Policy-Vorrang), `references/notes.md` **+6/−4** (zwei veraltete Aussagen), `SKILL.md` **+1/−1** (der Zeiger auf `references/amend.md` nennt die Ausnahme). Zusätzlich `agent-config/SKILL.md` **+6** (die Kurzform in §6, mit dem Zeiger auf die Referenzdatei) und die **Regeldatei** `.agents/rules/build-verify.md` **+2/−2** (Regel 6 und der Satz über die Repo-Policy). **Entscheidungen: `build-verify` → `offen`** (Position 52 bleibt) **und `agent-config` → `nein`** (Maschinen-Konfiguration; die geänderte Passage ist die Commit-Konvention des **Skills-Repos**). |
| `5243791` | **`skills-marker`** — nur `references/notes.md` (**+23/−19**): die acht Befunde der Verifikation von `842e055` geschlossen, jedes Zitat an seinem Platz. **Entscheidung: `ja`** — wie `c056e75`. |

**Neu in der Range:** **keiner** (`git log --diff-filter=A --name-only --format=
820c140..5243791 -- .agents/skills` → **leer**).

**Neu versteckt:** keiner — die Range fasst **keine** Frontmatter an (`git diff 820c140..5243791
-- .agents/skills/*/SKILL.md | grep -cE '^[+-].*autoinvoke'` → **0**); **versteckt gesamt**
unverändert **17** von **19** Skills (`git grep -l "opencode/autoinvoke: false" <rev> --
.agents/skills/*/SKILL.md` → **17**, an beiden Revs gleich; `git ls-tree -d --name-only <rev>
-- .agents/skills/` → **19** Skill-Verzeichnisse an beiden Revs).

**Entfernt in der Range:** keiner.

**Ergebnis je Skill aus der Range** — die Range hat **5** Skill-Verzeichnisse berührt
(gezählt mit `git log --name-only --format= 820c140..5243791 -- .agents/skills | cut -d/ -f3
| sort -u` → `agent-config`, `build-verify`, `github-ci-filters`, `skills-marker`,
`ui-review`). **Je Skill genau eine der drei Entscheidungen aus §3:**

- **`skills-marker` → `angewendet: ja`** (trifft zu **und** ist drin). Die Range ändert
  **die Regel, nach der dieser Marker gebaut ist**, nicht eine Pflicht, die das Projekt
  trägt. Geprüft wurde, was davon die **Anwendung hier** betrifft:
  (a) die **Zitierform** — der Marker zitiert die Regel mit Pfad + Anker und **ohne
  Zeilennummer**; der Anker `Bezugsrahmen-Regel` steht am neuen Stand **1**-mal in
  `skills-marker/SKILL.md` (`git grep -c "Bezugsrahmen-Regel" 5243791 -- .agents/skills/
  skills-marker/SKILL.md` → **1**), die Zitierstelle ist also **im Frame**;
  (b) die **Nomen-Klausel** („das Nomen entscheidet den Fall **nicht**") — sie steht
  **in** dem Absatz, den der Anker benennt, und ist damit **Teil der zitierten Regel**,
  keine eigene Zusatzregel; der Marker trägt sie als Zitat, nicht als Kopie;
  (c) die **Aufzählungs-Klausel** („eine Aufzählung trägt zwei Behauptungen") — sie ist
  die fünfte der fünf Regeln desselben Absatzes und wird hier **angewendet**;
  (d) die **`**Lokale Ergänzung:**`-Markierung** (am committeten Stand dieses Commits
  **7** Markierungen im Marker und **1** im Board — `grep -c 'Lokale Ergänzung'` zählt im
  Marker **9**, die zwei zusätzlichen Treffer sind diese Nennung und das Suchkommando
  selbst);
  (e) die **Rahmenformen** (a)/(b)/(c) und die **Cursor-Klausel**.
  **Die Klausel wirkt auf die Anwendung im Projekt, weil der Marker selbst eine Aufzählung
  von Skill-Zeilen ist:** beide Behauptungen sind belegt — *jedes Element trägt die
  Eigenschaft* (alle **19** Zeilen der Tabelle tragen ein Verdikt aus den drei Werten: **8**
  `ja`, **5** `nein`, **6** `offen`) und *die Liste ist die ganze Menge* (die Tabelle führt
  **alle 19** Skill-Verzeichnisse des Skills-Repos am Stand `5243791` — `git ls-tree -d
  --name-only 5243791 -- .agents/skills/` → **19**, die Tabelle hat **19** Datenzeilen);
  die Handliste ist damit vollständig, nicht nur plausibel.
- **`github-ci-filters` → `angewendet: ja`** (trifft zu **und** ist drin). Gemessen am
  committeten Stand dieses Commits: `git grep -nE '^\s*(paths|paths-ignore):' -- .github/
  workflows/` → **4** Treffer in **3** Dateien — `ci.yml` **2** (`paths-ignore:` unter
  `push` `:63` und unter `pull_request` `:66`, je mit `- '**/*.md'` darunter), `base-image.yml`
  **1** (`paths:` `:67-69`), `e2e-image.yml` **1** (`paths:` `:43-47`). Der Filter in
  `ci.yml` ist die **abziehende** Form (`paths-ignore`) — nach der neuen Fassung von
  Schritt 2 ausdrücklich erlaubt und weiterhin die empfohlene; er steht auf **beiden**
  dateiändernden Events, und der Kommentarblock am Filter (`.github/workflows/ci.yml:13-56`)
  trägt die Begründung **an der Stelle selbst** — die Asymmetrie-Begründung dort ist
  dieselbe wie die der neuen Upstream-Regel („eine zu schmale Include-Liste heißt 'dieser Job
  läuft für diese Datei nie' — ohne Fehler, ein gefilterter Workflow ist kein
  fehlgeschlagener Workflow"). **Schritt 1 (der Lücken-Sweep)** wird praktisch eingehalten: der
  Kommentarblock nennt **zwei** Sweeps („zitiertes `.md`-Literal; `backend/app`,
  `frontend/src`, `backend/tests`, `frontend/tests`, `scripts`, `deployment`, `.github`") mit
  dem Ergebnis „**KEINEN** Laufzeit-Leser" — die neue Fassung verlangt genau diese
  Check-Verzeichnisse; **Schritt 4 (benannte Diff-Endpunkte)** wird **nicht** praktisch
  eingehalten: der Commit, der den Filter einführte (`5df8722`), nennt **keinen**
  Endpunkt-Diff — der Marker zitierte und zitiert **keinen** Diff-Beleg, und es steht
  **kein** Filter-Wechsel an. Die Änderung an Schritt 4 wird also **erst beim nächsten
  Filter-Wechsel** bindend für dieses Projekt; für das, was der Marker behauptet, ändert
  sie **nichts**. **Die beiden Image-Workflowe** (`base-image.yml`, `e2e-image.yml`) nutzen
  die **auswählende** Include-Liste — die bleibt nach der neuen Fassung **verboten**; die
  Abweichung ist dort **dokumentiert** (Kommentarblock in `e2e-image.yml`, Verweis auf die
  ausführliche Begründung in `base-image.yml`: „Fail-safe wins"), und die neue Fassung
  **legalisiert** sie nicht. Der Wortlaut der Ausnahme wird deshalb im Board und in der
  Range-Notiz nicht als Erfüllung behauptet.
- **`ui-review` → `angewendet: offen`** (trifft zu, ist aber **nicht** drin) —
  **Position 55 bleibt**. Der Projektbefund ist unverändert: `AGENTS.md:293-295` führt
  weiterhin die alte Vorlage (`Severity | Screenshot | Finding | Suggested fix`, vier
  Niveaus, kein Statusfeld, keine Id/Eigentümer/Ticket) — am committeten Stand dieses
  Commits gemessen. **Was sich ändert, ist die Zielrichtung:** `2e09608` hat den
  Widerspruch **im Upstream selbst aufgelöst**, den die Position als Grund mitgeführt hat
  (der Skill-Body nannte noch die alten fünf Spalten und eine eigene Vierer-Niveaus-Liste,
  während die Vorlage die neun Spalten führte). Am Stand `5243791` ist der Skill-Body
  **einheitlich**; die beiden Anker, die die Position dafür nannte, sind **tot** (je **0**
  Treffer in `ui-review/SKILL.md` am Stand `5243791`, je **1** am Vorgänger `820c140`) und
  wurden im Board **korrigiert** (siehe unten) — **die Position selbst wurde weder
  geschlossen noch verändert**. Die Position bekommt damit erstmals ein **konkretes Ziel**:
  neun Spalten, die fünf kanonischen Niveaus (einschließlich `info`), die Render-Kriterien
  und die Verdikt-Regel aus `references/findings-report.md`.
- **`build-verify` → `angewendet: offen`** (trifft zu, ist aber **nicht** drin) —
  **Position 52 bleibt**. Die beiden Sätze in `AGENTS.md` §5, die der Upstream-Fassung
  widersprechen, stehen unverändert (`AGENTS.md:131` und `:123-125`); die **zitierten**
  Upstream-Sätze selbst sind in dieser Range **unverändert** — die Range ändert andere
  Stellen desselben Skills (`SKILL.md` **+1/−1**, `references/amend.md` **+43/−5**,
  `references/notes.md` **+6/−4**) und die Regeldatei `.agents/rules/build-verify.md`
  (**+2/−2**); die beiden zitierten Anker „every subagent dispatch in it is a fresh
  session" und „Fresh session per round" stehen dort unverändert, je **1** Treffer am Stand
  `5243791`). **Was die Range
  hinzufügt, ist die Amend-Ausnahme in Regel 6.** Ihre Bedeutung **für dieses Projekt**:
  das Projekt trägt **keine eigene, strengere Amend-Policy** — gemessen am committeten
  Stand dieses Commits: `grep -n -iE 'amend\|unpushed\|force-with-lease' AGENTS.md` →
  **0** Treffer (und repo-weit über die eigenen `.md`-Dateien ebenfalls **0**; die einzigen
  Treffer liegen in `frontend/node_modules/`). Die Ausnahme gilt hier also **unverändert**:
  ein eigener, frischer, gepushter Commit darf ersetzt werden, wenn der Remote-Head genau
  dieser Commit ist, keine fremde Arbeit im Baum liegt, `--force-with-lease` (nie
  `--force`) benutzt wird, der ersetzte SHA im **Body** und im **Bericht** offengelegt wird
  und der Unterschied über den Reflog auffindbar bleibt — **eine** Ersetzung je Commit. Ihr
  Nutzen ist hier begrenzt: ein Commit, der nur `.md` bewegt, löst **keinen** CI-Lauf aus
  (`.github/workflows/ci.yml:63`/`:66` filtern `**/*.md`), ein grüner Lauf des ersetzten
  Commits wäre **nichtig** und es gibt nichts nachzuattestieren. Der Punkt ist eine
  **Wortlaut-/Zustandsfrage**, keine neue Pflicht — die Position 52 bleibt offen, weil sie
  eine andere Lücke festhält.
- **`agent-config` → `angewendet: nein`** (trifft nicht zu). Grund: Maschinen-Konfiguration
  (globale `opencode.jsonc`, MCP, Skill-/Command-Registrierung) — das Projekt hat keine
  `opencode.jsonc` und keine Modell-/MCP-Datei. **Die Änderung in `80664ab` betrifft §6
  (die Commit-Konvention)**: die neue Ausnahme wird dort ausdrücklich **dem Skills-Repo
  verweigert** („This repo does not take it") — eine Aussage über ein anderes Repo, keine
  Pflicht dieses Projekts.

**Nicht berührt (14)** — von der Range nicht angefasst, das Verdikt ist der unveränderte
Bestand: `build-verify` → **offen** (Position 52) · `laravel` → **offen** (Position 53) ·
`codegraph-project-setup` → **offen** (Position 54) · `ui-review` → **offen** (Position 55) ·
`generated-docs` → **offen** (Position 56) · `ratchet-gates` → **offen** (Position 57) ·
`docker-test-image`, `ghcr-visibility`, `github-ci-filters`, `node-deps`,
`playwright-parallel`, `react-i18n`, `security-review` → **ja** · `agent-config`,
`permissions`, `tailscale-serve`, `update-models`, `web-audit` → **nein**.
**Die Liste ist die ganze Menge:** 19 Skill-Verzeichnisse am Stand `5243791`
(`git ls-tree -d --name-only 5243791 -- .agents/skills/` → **19**) − **5** von der Range
berührte = **14**; jede der **14** steht mit ihrem Verdikt in der Tabelle `## Skill-Stand`.

**Alle Anker dieses Markers und des Boards neu geprüft: 78 Anker-Zitate — 76 treffen zu,
0 gedriftet, 2 tot (beide korrigiert).** **Zähleinheit:** das **Zitat** — jedes einzelne
Vorkommen einer Zitierstelle, nicht die Zeile und nicht die Datei. Drei Gruppen, jede mit
ihrem Umfang:

| Gruppe | Umfang | Zitate | trifft zu | gedriftet | tot |
|---|---|---|---|---|---|
| (A) Zeilenanker in der Tabelle `## Skill-Stand` | die **19** Datenzeilen der Tabelle; Zähleinheit: **Vorkommen** des Anker-Tokens (Fortsetzung des unmittelbar davor genannten Pfads) oder der vollen Form „`Pfad:N`" | **40** | **40** | **0** | **0** |
| (B) Ankerform-Zitate im Marker (Pfad im Skill + Anker, **ohne** Zeilennummer) | der ganze Marker (Kopf, Tabelle, Absatz unter der Tabelle); Zähleinheit: **Zitat** | **12** | **12** | **0** | **0** |
| (C) Ankerform-Zitate im Board (Positionen **52–57**) | der Abschnitt `## 🔒 Skills-Marker-Nachtrag` des Boards; Zähleinheit: **Zitat** | **26** | **24** | **0** | **2** |

**(A) Die 40 Zeilenanker der Tabelle** — alle **40** treffen zu, keiner ist gedriftet, keiner
ist tot. Gezählt so (Umfang: die **19** Datenzeilen der Tabelle `## Skill-Stand`; der
Absatz unter der Tabelle und dieser Abschnitt zählen nicht mit;
`git show <stand>:AGENTS.skills.md | sed -n '/^| Skill | angewendet?/,/^$/p' | grep -oE
':[0-9]+(-[0-9]+)?' | wc -l` → **40**): **38** in der Form „`:N`" (Fortsetzung des
unmittelbar davor genannten Pfads) **+ 2** in der vollen Form „`Pfad:N`" (`AGENTS.md:80`,
`AGENTS.md:99-101`) = **40** Vorkommen; **39** davon sind verschiedene Ziele (`:64` —
`e2e-image.yml`, `PNPM_VERSION` — **zweimal** genannt, in zwei Tabellenzeilen). Geprüft
wurde gegen den **committeten Stand dieses Commits** — den Datei-Stand, den dieser Commit
festschreibt — mit `git show <rev>:<datei>` / `git grep <rev> -- <pfad>`, nicht gegen einen
Working Tree; die **Fremddateien** sind gegenüber dem Vorgänger `cff0f03` **unverändert**
(`git diff --stat cff0f03..<stand> -- . ':(exclude)AGENTS.skills.md'
':(exclude)AGENTS.todo.md'` → **leer**; `AGENTS.md` ebenso: `git diff --stat
cff0f03..<stand> -- AGENTS.md` → **leer**). Die geprüften Stellen, Zeile für Zeile der
Tabelle: `build-verify` — `AGENTS.md:114-117` (Build-/Verify-Flow) · `docker-test-image` —
`e2e-image.yml:67` (Job `build`), `:79` (Schritt „Extract build args"), `:91-94`
(PLAYWRIGHT_VERSION), `:64` (PNPM_VERSION im Workflow-`env:`), `:81`/`:87` (Step-`env:`),
`:93-95` (Prüfung und Outputs), `:107-147` (Schritt „Resolve base image reference"), `:147`
(Wert `base_ref`), `:164` (Verbrauch als Build-Arg), `Dockerfile.e2e:68`/`:115` (ARG-Block) ·
`generated-docs` — `features/venue-master-data.md:358-368`,
`features/auth/01-auth-and-roles.md:430-454`, in voller Form `AGENTS.md:80` (Pflicht „Diff
gegen den Code") und — **nach der Umstellung unten** — Upstream
`generated-docs/SKILL.md`, Anker „## Numbers in prose" · `ghcr-visibility` — `ci.yml:526-530`
(GHCR-Kommentar), `:550` (`image: ghcr.io/…`), `features/05-e2e-test-image.md:167-168` ·
`github-ci-filters` — `ci.yml:63`/`:66` (paths-ignore), `base-image.yml:67-69` und
`e2e-image.yml:43-47` (die beiden `paths:`-Blöcke) · `node-deps` — `ci.yml:481`/`:739`
(pnpm-Version), `e2e-image.yml:64`, `Dockerfile.e2e:117` · `playwright-parallel` —
`ci.yml:792-802` (Worker-Begründung), `:866-870` (Kommandos),
`frontend/playwright.config.ts:24`/`:27` · `react-i18n` — `frontend/AGENTS.md:97`,
`frontend/vite.config.ts:32-33`, `frontend/src/main.tsx:1`/`:5` · `security-review` —
`AGENTS.md:59` (Regressionstest) · `ui-review` — `AGENTS.md:262-271` (Loop-Einleitung),
`:273-300` (Loop-Schritte 1–4), `:293-295` (Findings-Report) · `ratchet-gates` — in voller
Form `AGENTS.md:99-101` (Zero-Pre-existing-Failures-Policy) und — **nach der Umstellung
unten** — Upstream `ratchet-gates/references/vocabulary.md`, Anker „**A finding needs an
owner and a ticket**", und Upstream `ratchet-gates/SKILL.md`, Anker „an owner and a ticket
per entry". Die dateiweisen Anker ohne Zeile (`.githooks/pre-commit`, `.codegraph/.gitignore`,
`backend/AGENTS.md`, `backend/phpunit.xml`, `backend/composer.json`,
`backend/config/permissions.php`, `frontend/package.json`, `frontend/tests/screenshots/`,
`features/security/accepted-risks.md` (A1–A8)) wurden am committeten Stand **dieses
Commits** ebenfalls geprüft. **Kein Anker ist verroten.** Die Zahlen der betroffenen Zeilen
wurden neu gemessen (Position 53: `FormRequest`-Klassen **0**, `rules()` bei **8** von **23**
Controllern **mit Schreib-Endpunkten** (store/update/destroy/create/edit), Inline-Array bei
**17** Controllern **insgesamt** — `git grep -l … <stand> -- backend/app…`, gemessen am
Vorgänger `a4551c8`, seitdem unverändert: `git diff --stat a4551c8..<stand> -- backend/` →
leer).

**(B) Die 12 Ankerform-Zitate im Marker** — alle **12** treffen zu (je **1** Treffer an der
genannten Stelle, gemessen am Stand `5243791`; wo der Anker über einen Zeilenumbruch
läuft, zählt die **Zeichenkette** in der normalisierten Datei): `skills-marker/SKILL.md` →
„Bezugsrahmen-Regel" (**2** Zitate: im Kopf und in der Zeile `skills-marker` der Tabelle —
je **1** Treffer), „**Angewendet** heißt **am Code geprüft**, nicht gelesen" (**1**), „| Trifft
zu, ist aber nicht drin |" (**1**), „im Zweifel gewinnt **ja** mit offener Position, nicht
**nein**" (**1**), „Eine Aufzählung trägt zwei Behauptungen" (**1**) ·
`skills-marker/references/notes.md` → § „Der Bezugsrahmen" (die **Zeichenkette** steht
**2**-mal in der Datei, davon ist **1** die Überschrift `## Der Bezugsrahmen` — der
Abschnitts-Anker trifft **eindeutig**) · `skills-marker/templates/AGENTS.skills.md` → „ja |
nein" (**1**), „"Angewendet" heißt: am Code dieses Projekts geprüft — nicht: die Doku
gelesen." (**1**) · `generated-docs/SKILL.md` → „## Numbers in prose" (**1**) ·
`ratchet-gates/references/vocabulary.md` → „**A finding needs an owner and a ticket**" (**1**)
· `ratchet-gates/SKILL.md` → „an owner and a ticket per entry" (**1**, der Anker steht über
dem Zeilenumbruch).

**(C) Die 26 Ankerform-Zitate im Board (Positionen 52–57)** — **24** treffen zu, **2** sind
**tot** und wurden korrigiert. **24 treffen zu** (je **1** Treffer am Stand `5243791`):
`.agents/rules/build-verify.md` → „it checks the **substance**, not the mechanical gates" ·
`build-verify/SKILL.md` → „Fresh session per round", „every subagent dispatch in it is a
fresh session" · `laravel/SKILL.md` → „one `FormRequest` per write endpoint", „Write
endpoints validate via `FormRequest`" · `codegraph-project-setup/SKILL.md` → §1 „Project
readiness == `codegraph init` + pre-commit hook" · `skills-marker/SKILL.md` → „im Zweifel
gewinnt **ja** mit offener Position, nicht **nein**" ·
`ratchet-gates/references/vocabulary.md` → „## Severity levels", „## Statuses", „The **count
is the number of findings in the counting statuses**", „**A finding needs an owner and a
ticket**", „**Severity and status are recorded separately.**", „**A finding has a stable
id.**" · `ratchet-gates/SKILL.md` → „The gap list gets the same discipline as the code" ·
`ui-review/references/findings-report.md` → „| Id | Severity | Status | Owner | Ticket |
File:Line | Screenshot | Finding | Suggested fix |", „A verdict is only APPROVED when no
`critical` or `high` findings remain unresolved", „## Severity definitions", „## Status",
„carries an **id**, an **owner** and a", „A defect with a workaround" ·
`generated-docs/SKILL.md` → „## Numbers in prose", „## Who owns the claim?", „## What is
not generated — and why", „## The drift gate". Die im Board genannten **Abschnittsverweise**
(`.agents/skills/laravel/references/backend-conventions.md` → Zeile „Validation";
`codegraph-project-setup/SKILL.md` → §2.2, §2.3, §3) lösen ebenfalls auf.

**Die zwei toten Anker — beide im Board, Position 55 — sind korrigiert.** Grund: der
Commit `2e09608` hat genau die beiden Stellen im `ui-review`-Skill-Body ersetzt, die diese
Position als Upstream-Widerspruch zitiert hat.

| Stelle | alt | neu | Grund |
|---|---|---|---|
| Board, Position 55, „Und upstream …" | Upstream `ui-review/SKILL.md`, Anker „columns `Severity \| File:Line \| Screenshot \| Finding \| Suggested fix`" | Upstream `ui-review/SKILL.md`, Anker „columns `Id \| Severity \| Status \| Owner \| Ticket \| File:Line \| Screenshot \| Finding \| Suggested fix`" | Der Anker ist am Stand `5243791` **tot** (`git grep -c -F "columns \`Severity | File:Line | Screenshot | Finding | Suggested fix\`" 5243791 -- .agents/skills/ui-review/SKILL.md` → **0**; am Vorgänger `820c140` **1**). `2e09608` hat die Spaltenliste im Skill-Body ersetzt. |
| Board, Position 55, „Und upstream …" | Upstream `ui-review/SKILL.md`, Anker „Assign each finding a severity:" | Upstream `ui-review/SKILL.md`, Anker „the **canonical vocabulary**" | Der Anker ist am Stand `5243791` **tot** (`git grep -c -F "Assign each finding a severity:" 5243791 -- .agents/skills/ui-review/SKILL.md` → **0**; am Vorgänger `820c140` **1**). `2e09608` hat die eigene Niveaus-Liste durch den Verweis auf das kanonische Vokabular ersetzt. |

**Kein Zeilenanker ist gedriftet.** Die **38** Alt-Zeilenanker der Tabelle (vor diesem
Lauf) zeigen heute noch auf das Richtige — die betroffenen Projektdateien sind gegenüber
dem Vorgänger `cff0f03` **unverändert** (`git diff --stat cff0f03..<stand> -- .
':(exclude)AGENTS.skills.md' ':(exclude)AGENTS.todo.md'` → **leer**) — aber sie **driften**
und sind damit **keine** Anker; die
**zwei** neuen Zeilenanker dieser Runde (`base-image.yml:67-69`,
`e2e-image.yml:43-47`) folgen derselben Regel und stehen als **Lesehilfe** neben der
stabilen Referenz (dem `paths:`-Block am Filter). **Upstream-Zitate tragen keine
Zeilennummer** — alle **38** Upstream-Zitate dieses Markers und des Boards (12 + 26) sind
Anker in Pfad-und-Text-Form; die früher mit Zeilennummer zitierten Stellen sind in den
früheren Runden umgestellt worden und stehen so.

**Die Zitierstelle bleibt gültig — der Nachweis, alt gegen neu:**
`Bezugsrahmen-Regel` steht im Skill-Verzeichnis am **alten** Cursor `820c140`
**7-mal** (`git grep -c "Bezugsrahmen-Regel" 820c140 -- .agents/skills/skills-marker/` →
`SKILL.md` **1**, `references/notes.md` **5**, `templates/AGENTS.skills.md` **1**) und am
**neuen** Stand `5243791` **8-mal** (`git grep -n "Bezugsrahmen-Regel" 5243791 --
.agents/skills` → `skills-marker/SKILL.md` **1**, `skills-marker/references/notes.md` **5**,
`skills-marker/templates/AGENTS.skills.md` **1**, `github-ci-filters/references/notes.md`
**1** — die **letzte** Stelle ist neu: `7328fc3` hat den Verweis auf die Zitierstelle in
die github-ci-filters-Notizen aufgenommen; das ist dort ein **Fremdzitat** — der
github-ci-filters-Skill zitiert die Regel des skills-marker-Skills — und kein Zitat dieses
Markers). Die Zitierstelle selbst — der Anker in `skills-marker/SKILL.md` — steht an
**beiden** Ständen **1**-mal; sie ist also an beiden Ständen **im Frame**. Die Range
dazwischen ist **begangen**: sie berührt **5** Skills, und für **alle fünf** steht eine der
drei Entscheidungen.

**Die offenen Positionen wurden nachgemessen, keine ist gegenstandslos geworden:**
52 — `AGENTS.md:123-125` (Restart-Regel) und `:131` (Verifikator-Rolle) stehen
unverändert im Widerspruch zur Upstream-Fassung, und die Upstream-Sätze selbst sind in
dieser Range **unverändert** (die zitierten Anker „every subagent dispatch in it is a
fresh session" und „Fresh session per round" stehen je **1**-mal am Stand `5243791`);
53 — die Zahlen oben; 54 — `.codegraph/` trägt weiterhin **nur** `.gitignore` (`git
ls-tree -r <stand> -- .codegraph/` → **1** Eintrag), `core.hooksPath` ist weiterhin
**nicht** gesetzt (`git config --list --show-origin | grep -i hookspath` → keine Ausgabe),
`.git/hooks/pre-commit` existiert weiterhin **nicht**; 55 — der Findings-Report in
`AGENTS.md:293-295` führt unverändert die alte Vorlage (am committeten Stand dieses
Commits gemessen; das Ziel ist durch `2e09608` nun eindeutig); 56 — kein Generator und
kein Drift-Gate im Baum (`scripts/` ohne Doku-Werkzeug, **0** Dateien mit
`gen_`/`drift`/`doc_fact`/`generate` im Namen); 57 — kein Owner und kein Ticket je
Registereintrag (`git grep -ciE 'owner|ticket' <stand> -- features/security/accepted-risks.md`
→ **0** Treffer; das Register trägt weiterhin **8** Einträge `A1`–`A8` mit kanonischem
Status `accepted` und den kanonischen Niveaus `low`/`medium`).

**Regel-Drift (§5 Schritt 3) auf dieser Maschine: kein Befund, aber eine geänderte
Regeldatei.** Die globale `~/.config/opencode/AGENTS.md` besteht aus **vier** Blöcken
(`## CodeGraph` L2, `## Grundsätze` L12, `## Always-on rules` L23, `## Dieser Host (code-dev)`
L29) — **eine** Regel daraus ist aus `.agents/rules/` eingebettet: die Frage-Regel aus
`.agents/rules/ask.md`. Die anderen drei Blöcke haben eigene Quellen (CodeGraph-Setup,
allgemeine Projekt-Grundsätze, Host-Beschreibung) und sind **keine**
`.agents/rules/`-Einbettung. In dieser Range ist **eine** Regeldatei geändert:
`.agents/rules/build-verify.md` (Regel 6 + der Satz über die Repo-Policy, **+2/−2**). Sie hat
**keine** eingebettete Kopie: die globale `~/.config/opencode/AGENTS.md` trägt **keinen**
build-verify-Text (`grep -n -i 'build-verify\|amend\|unpushed'` → **keine** Ausgabe), und
das Projekt-`AGENTS.md` **wiederholt** sie nicht — §5 Punkt 4 **verweist** auf den Skill
(„gilt hier unverändert") und wiederholt keine Regel. Es ist deshalb **nichts** nachzuziehen.
Die `skills-marker`-Regel selbst ist **keine** eingebettete Regel: die globale `AGENTS.md`
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
