# Skills-Marker

agents-skills-consumed: 0449b05
geprüft am: 2026-10-21

> Viertermarker. Geprüfte Range `787670a..0449b05` — **12 Commits** des Skills-Repos
> (`git -C <skills-repo> rev-list --count 787670a..0449b05` → 12, gemessen am
> committeten Stand `0449b05`; der Cursor ist genau dieser HEAD).
> Die Range berührte **9 Skill-Verzeichnisse** unter `.agents/skills/`
> (`git log --name-only --format= 787670a..0449b05 -- .agents/skills | sort -u` →
> 22 Dateien in 9 Verzeichnissen): `agent-config`, `build-verify`, `generated-docs`,
> `github-ci-filters`, `ratchet-gates`, `security-review`, `skills-marker`,
> `ui-review`, `update-models`. Dazu kam die Regel `.agents/rules/build-verify.md`
> (2 Commits: `0024d7e`, `0449b05`).
> **Neu in der Range:** 2 Skills (`generated-docs`, `ratchet-gates`) — **neu versteckt:**
> 3 Skills (`agent-config`, `update-models`, `github-ci-filters`) — **entfernt: 0**.
> **Nicht-Skill-Abschnitt:** genau **1** der 12 Commits berührt überhaupt keinen
> `.agents/`-Pfad (`5a0c1b0` — nur `AGENTS.todo.md` des Skills-Repos; gemessen je Commit
> über `git log --name-only --format='COMMIT %h %s' 787670a..0449b05`).
> **Versteckt gesamt:** **12 → 17** Skills mit `metadata.opencode/autoinvoke: false`
> (Frontmatter-Messung an beiden Revs; die Differenz 5 = 3 neu versteckte + 2 neue
> Skills). Jede Ja-Zeile nennt eine **stabile Referenz** (Abschnitt, Überschrift oder
> Suchstring); Zeilennummern nur als Lesehilfe. Der Pfad zum Skills-Repo steht hier
> bewusst nicht — der Marker bleibt bei einer Verschiebung gültig.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Konfiguration (globale `opencode.jsonc`, MCP, Skill-/Command-Registrierung) — das Projekt hat keine `opencode.jsonc` und keine Modell-/MCP-Datei. **Seit `817c01e` hidden** (nur per exakter ID ladbar) — das ändert die Anwendbarkeit nicht: die Pflicht bleibt eine Pflicht der Maschine, nicht des Projekts |
| `build-verify` | ja | `AGENTS.md` §5 Punkt 4 „Build-/Verify-Flow — zentral, nicht hier" (`:114-117`) | Position 52 — zwei Sätze in `AGENTS.md` §5 widersprechen der Skill-Fassung; die zitierten Upstream-Sätze sind in dieser Range **unverändert** |
| `codegraph-project-setup` | ja | `.githooks/pre-commit` (CodeGraph-Sync-Hook, versioniert — **läuft aber nicht**: `core.hooksPath` nirgends gesetzt, `.git/hooks/pre-commit` fehlt); `.codegraph/` trägt **nur** `.gitignore`, **keinen** Index (`codegraph status`, gemessen 2026-10-21: „⚠ Not initialized") | Position 54 — der Pflichtteil des Skills (Index + verdrahteter Hook) ist **nicht erfüllt**; am committeten Stand `27739d5` neu gemessen, unverändert |
| `docker-test-image` | ja | `.github/workflows/e2e-image.yml` (Job `build` `:67`, BASE_REF-/PLAYWRIGHT_VERSION-Auflösung `:91-94`), `deployment/Dockerfile.e2e` (ARG-Block `:68`, `:115`), `features/05-e2e-test-image.md` | keine |
| `generated-docs` | ja | `features/venue-master-data.md` „Portabilität (§2)" (`:358-368`: die Gate-Zahlen nennen Lauf `37280377310`, Datum und Leser), `features/auth/01-auth-and-roles.md` (`:430-454`: die unbezifferte Zahl wurde **ersetzt**, nicht behalten); die Pflicht selbst: `AGENTS.md` §3 Punkt 3 („für ein Dokument Diff gegen den Code, nicht gegen die Absicht") und §2 („Wer eine Zahl zitiert, prüft sie gegen einen Lauf") | keine — der **Mechanismus** des Skills (Generator + Drift-Gate per Diff) ist nicht übernommen; die **Pflicht** trägt das Projekt in eigener Form, an zwei Stellen belegt |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` (Kommentar „ein public GHCR-Package" `:526-530` + `image: ghcr.io/…` `:550`), `features/05-e2e-test-image.md` (`:167-168`) | keine — beide Packages public (geprüft 2026-10-20: `gh api visibility=public` für `accriditation-e2e` und `accriditation-base`) |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` → `paths-ignore: '**/*.md'` auf `push` **und** `pull_request` (`:63`, `:66`) | keine — **seit `817c01e` hidden**: die Pflicht gilt unverändert, nur der Ladeweg ändert sich (per exakter ID) |
| `laravel` | ja | `backend/AGENTS.md` (Commands, DB-/Migrations-Policy, Parallel-Testing, Datei-Tests), `backend/phpunit.xml` (Hermetik-Pins), `backend/composer.json` (PHP ^8.5, pint, paratest, jwt-auth) | Position 53 — Validierungs-Konvention nicht umgesetzt; am committeten Stand `27739d5` neu gemessen: `FormRequest`-Klassen **0**, `rules()` bei **8** von **23** Controllern, Inline-Array bei **17** |
| `node-deps` | ja | `frontend/package.json` (keine Pins/Overrides), `ci.yml` (`version: 11` `:481`, `:739`), `e2e-image.yml` (`PNPM_VERSION: "11"` `:64`), `deployment/Dockerfile.e2e` (`ARG PNPM_VERSION=11` `:117`) | keine |
| `permissions` | nein | | opencode.jsonc-Policy (Maschine) — das Projekt definiert keine; `backend/config/permissions.php` ist die Gate-Matrix des Projekts, nicht diese Policy |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` (Run-Step: die Worker-Begründung `:792-802`, die Kommandos `:866-870`), `frontend/playwright.config.ts` (`fullyParallel: true` `:24`, `workers: process.env.CI ? 4 : 8` `:27`) | keine |
| `ratchet-gates` | ja | `features/security/accepted-risks.md` (A1–A8: kanonischer Status `accepted` + kanonische Niveaus `low`/`medium`, stabile Ids), `backend/config/permissions.php` („Single source of truth for the authorization layer") | keine im Vokabular-Teil — die eine Lücke (der UI-Report führt den Status nicht als eigenes Feld) steht als **Position 55** in der Zeile `ui-review`. **Der Ratchet-Teil trifft nicht zu**: eine getragene Baseline gibt es hier nicht (gemessen am committeten Baum `27739d5`: kein Baseline-/Known-Failure-Artefakt), und `AGENTS.md` §4 verbietet getragene Altfehler (Zero-Pre-existing-Failures-Policy) — das ist die andere Antwort auf dasselbe Problem |
| `react-i18n` | ja | `frontend/AGENTS.md` (§ „Lingui / i18n — No Module-Scope `t` (STRICT)" `:97`), `frontend/vite.config.ts` (lingui-Plugin + Babel-Preset `:32-33`), `frontend/src/main.tsx` (I18nProvider als erster Import `:1`, `:5`), `frontend/package.json` (`lingui:extract`/`lingui:compile`, `check:i18n` in `prebuild`) | keine — das Projekt weicht bewusst ab: zwei Locales (de+en) mit Vollständigkeits-Gate statt der Portal-Referenz (eine Locale + Rohstring-Pass) |
| `security-review` | ja | `AGENTS.md` §10 (Fundort der Befunde) + `features/security/accepted-risks.md` (Register A1–A8), `AGENTS.md` §3 (Regressionstest je Fix `:59`) | keine — das Register trägt die kanonischen Stände (`accepted`) und Niveaus (`low`/`medium`) mit stabilen Ids; die Klassen BELEGT/HEURISTIK/AKZEPTIERT bleiben daneben stehen und kollidieren nicht (im Board gemessen: **0** Treffer auf die Klassenliste; als Schweregrade kommen dort nur die kanonischen Wörter vor — `critical`/`high`/`medium`/`low`/`info`; `Major`/`hoch`/`niedrig` nur in Prosa) |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei; Abschnitt „Fortschreiben") | keine |
| `tailscale-serve` | nein | | kein Tailscale-Bezug im Projekt (repo-weiter Treffer: nur diese Datei) |
| `ui-review` | ja | `AGENTS.md` §7 „Visuelle Verifikation / UI-Review (Design-QA, STRICT)" (`:262-271`, Loop-Schritte 1–4 `:273-300`), `frontend/tests/screenshots/` | Position 55 — der Findings-Report in `AGENTS.md` §7 Schritt 3 (`:293-295`) führt die **alte** Vorlage (4 Spalten, 4 Niveaus, kein Statusfeld, keine Id/Eigentümer/Ticket) |
| `update-models` | nein | | Modell-Registry (Maschine) — das Projekt hat keine `opencode.jsonc` und keine Modell-Datei; **seit `817c01e` hidden**, und `219171c`/`8c6b9af` korrigieren nur Snapshot und Namespace-Regel der **Maschine** |
| `web-audit` | nein | | trifft nicht zu, weil das Projekt keine Marketing-/Content-Website hat (kein SEO-, Sitemap- oder Performance-Audit-Gegenstand) — die visuelle Prüfung der App ist der Screenshot-Design-QA-Loop (`ui-review`), ausdrücklich getrennt von funktionalen Tests |

`ja` = die Pflicht des Skills **trifft hier zu** und ist am Code geprüft · `nein` =
trifft nicht zu, Begründung in der Zeile · `offen` = noch nicht geprüft (Prüfung
folgt im nächsten Marker-Lauf). Ob das Projekt die Pflicht **schon erfüllt**,
steht getrennt davon in der Spalte „offene Position": leer (oder „keine — …") =
getragen, eine Positionsnummer = **gemessene Lücke** (Log-Eintrag im Board, kein
Auftrag). Die Tabelle ist ein **Zustand**, kein Auftrag. **Das Verdikt `offen` kommt in
dieser Tabelle nicht mehr vor** — die beiden offenen Zeilen der Vorgängerversion
(`generated-docs`, `ratchet-gates`) sind in dieser Runde geprüft und entschieden.

## Offen aus dem Bereich 787670a..0449b05 (2026-10-21)

> **Herkunft:** Fortschreibung des Markers `AGENTS.skills.md`
> (`agents-skills-consumed: 787670a` → `0449b05`). Die Range umfasst **12 Commits**
> und berührte **9 Skill-Verzeichnisse**. **Die Tabelle ist ein Zustand, kein Auftrag.**
> Die 9 Skill-Verzeichnisse oben sind **keine** offenen Positionen und **keine** 9
> unerledigten Arbeiten: die Tabelle sagt je Skill, ob seine Pflicht hier **trifft**
> und geprüft ist (`ja`) oder ob der Skill hier nichts regelt (`nein`). **Zwei
> Bedeutungen von „offen" — nicht vermischen.** Das **Verdikt `offen`** (die Prüfung
> steht noch aus) kommt in dieser Range **nicht** mehr vor: die beiden Zeilen
> `generated-docs` und `ratchet-gates` sind jetzt geprüft und entschieden. Eine
> **Lücke** dagegen ist **gemessen** und steht als **Position** im Board
> (`AGENTS.todo.md`): 52 (`build-verify`), 53 (`laravel`), 54
> (`codegraph-project-setup`), 55 (`ui-review`) — alle vier sind `ja`-Zeilen. Beides
> ist ein Log, kein Arbeitsauftrag.

**Je Commit der Range, in der Reihenfolge der Range** — jeder Effekt an
`git show --stat` am committeten Stand gemessen:

| Commit | Was |
|---|---|
| `219171c` | `update-models`: Namespace-Regel und Snapshot an die neue Config angeglichen (`references/registry.md`: Snapshot 2026-10-19, `opencode-go/minimax-m3.1-flash-free`). Reine Textänderung an einem bestehenden Skill. |
| `fa2268f` | `ui-review`: der „vision-agent"-Wortlaut aus der Findings-Vorlage entfernt (`references/findings-report.md`, 1 Zeile). Reine Textänderung. |
| `8c6b9af` | `update-models`: die `$0`-Aussage auf das konfigurierte Modell eingeschränkt (3 Zeilen ersetzt). Reine Textänderung. |
| `70d49e0` | **legt den Skill `ratchet-gates` an** (SKILL + 4 Referenzen); ändert zusätzlich `agent-config` (versteckt den neuen Skill) und `README.md`. |
| `00dbd22` | **legt den Skill `generated-docs` an** (SKILL + 2 Referenzen); ändert zusätzlich `agent-config`, `ratchet-gates` und `README.md`. |
| `817c01e` | **versteckt `agent-config`, `update-models` und `github-ci-filters`** (je `metadata.opencode/autoinvoke: false`); kürzt zusätzlich Beschreibung und Body von `build-verify` und zieht `README.md` und `.github/skill-budget.json` nach. |
| `0024d7e` | schließt die Drift um den ungenutzten `instructions`-Key — in `agent-config` (SKILL + `references/snippets.md`), `build-verify` (SKILL + `references/notes.md`), `skills-marker` (`references/notes.md` + `templates/AGENTS.skills.md`), der Regel `.agents/rules/build-verify.md` und `README.md`; an `AGENTS.md` änderte derselbe Commit nur die Kennzeichnung von `agent-config` als hidden („load it by exact ID", **keine** `instructions`-Aussage) — und zieht `.github/skill-budget.json` nach. |
| `5a0c1b0` | **berührt überhaupt keinen Skill** — nur `AGENTS.todo.md` des Skills-Repos (30 Zeilen). Das ist der **eine** Commit der Range ohne jeden `.agents/`-Pfad. |
| `b0d1b51` | **ein gemeinsames Vokabular** für Findings- und Gap-Listen: `ratchet-gates/references/vocabulary.md` (neu) wird die Quelle (5 Niveaus, 5 Stände, Zählregel, Eigentümer-/Ticket-Regel, „Severity und Status getrennt"); `security-review` (SKILL + `references/findings-and-waves.md`), `ui-review` (`references/findings-report.md`) und `build-verify` (`references/verifier-prompt.md`) zeigen darauf, statt eigene Listen zu führen. |
| `b9f140b` | die Findings der Vokabular-Verifikation geschlossen: die security-review-Reihenfolge nennt jetzt `info`, die ui-review-`medium`-Zeile sagt, was ohne Workaround passiert. |
| `d7888cd` | die ratchet-CLI: eine Karte von den Gates zu den Kommandos (`ratchet-gates/references/cli.md`, neu; +134 Zeilen). |
| `0449b05` | **die geteilte Regel: jede Zahl im `Verify:`-Footer nennt, was sie misst** (Zustand: Working Tree/committeter Stand; Umfang: gefilterte Teilmenge, Range, Stufe) — in der Regel `.agents/rules/build-verify.md` (3 Zeilen), `build-verify/SKILL.md` (1 Zeile) und `build-verify/references/notes.md` (+61 Zeilen). `verifier-prompt.md` hat **dieser** Commit nicht angefasst (das war `b0d1b51`). |

**Neu in der Range:** `ratchet-gates` (Gates als Ratchet, kanonisches Vokabular) und
`generated-docs` (Doku, die geprüft statt geglaubt wird). Beide **hidden** — ladbar
nur per exakter ID.

**Neu versteckt:** `agent-config`, `update-models`, `github-ci-filters`. Ein
versteckter Skill ist **weder automatisch „ja" noch automatisch „nein"** — er bleibt
per exakter ID ladbar, und die Zeilen sind deshalb einzeln neu begründet:
`agent-config` → **nein** (Maschinen-Konfiguration: globale Config, MCP, Registry),
`update-models` → **nein** (Modell-Registry der Maschine), `github-ci-filters` →
**ja** (das Projekt hat `paths-ignore` auf beiden Events — die Pflicht trifft hier zu
und ist getragen; nur der Ladeweg ändert sich).

**Entfernt in der Range:** keiner.

**Ergebnis je Skill aus der Range** — `build-verify` → **ja** (unverändert
angewendet; die Range fügt die Zahlen-Frame-Pflicht hinzu, die über `AGENTS.md` §5
Punkt 4 („gilt hier unverändert") mitgilt und hier keine eigene Form braucht; die
Divergenz bleibt Position 52) · `security-review` → **ja** (unverändert; das Register
trägt die kanonischen Stände und Niveaus) · `ui-review` → **ja** (der Loop trägt; die
Findings-Vorlage in `AGENTS.md` §7 Schritt 3 ist die alte — das ist die neue Lücke,
Position 55) · `skills-marker` → **ja** (diese Datei) · `github-ci-filters` → **ja**
(unverändert angewendet; die Range änderte an ihm nur die Sichtbarkeit) ·
`agent-config`, `update-models` → **nein** (Maschinen-Konfiguration) ·
`ratchet-gates` → **ja, aber nur zur Hälfte** (der Vokabular-Teil trifft zu und ist
getragen; der Ratchet-Teil — eine getragene Baseline — trifft nicht zu, weil das
Repo keine hat) · `generated-docs` → **ja** (der Fall trifft zu: Zahlen in Prosa, die
aus einem Lauf stammen; die Pflicht trägt das Projekt in eigener Form).

**Die Alt-Anker wurden vollständig neu geprüft — alle 28 `Datei:Zeile`-Anker der
Tabelle lösen auf das Bezeichnete.** Geprüft wurde gegen den **committeten** Stand des
Projekts (`27739d5`, `git show <rev>:<datei>` / `git grep <rev> -- <pfad>`), nicht gegen
einen Working Tree. Die geprüften Stellen: `AGENTS.md:114-117` (Build-/Verify-Flow),
`:59` (Regressionstest), `:262-271` und `:273-300` (UI-Review-Loop), `:293-295`
(Findings-Report), `ci.yml:63`/`:66` (paths-ignore), `:481`/`:739` (pnpm-Version),
`:526-530` und `:550` (GHCR), `:792-802` und `:866-870` (Playwright-Worker),
`e2e-image.yml:64` (PNPM_VERSION) und `:67`/`:91-94` (Job `build`,
BASE_REF/PLAYWRIGHT_VERSION), `Dockerfile.e2e:68`/`:115`/`:117` (ARG-Block),
`frontend/playwright.config.ts:24`/`:27`, `frontend/vite.config.ts:32-33`,
`frontend/src/main.tsx:1`/`:5`, `frontend/AGENTS.md:97`,
`features/05-e2e-test-image.md:167-168`, `features/venue-master-data.md:358-368`,
`features/auth/01-auth-and-roles.md:430-454`. Die dateiweisen Anker ohne Zeile
(`.githooks/pre-commit`, `.codegraph/.gitignore`, `backend/AGENTS.md`,
`backend/phpunit.xml`, `backend/composer.json`, `backend/config/permissions.php`,
`frontend/package.json`, `frontend/tests/screenshots/`,
`features/security/accepted-risks.md` (A1–A8)) wurden am committeten Stand ebenfalls
geprüft. **Kein Anker ist verroten.** Die Zahlen der betroffenen Zeilen wurden neu
gemessen (Position 53: `FormRequest`-Klassen **0**, `rules()` bei **8** von **23**
Controllern, Inline-Array bei **17** — `git grep -l … HEAD -- backend/app…`, gemessen
am committeten Stand `27739d5`; die Zahlen sind unverändert gegenüber `787670a`).
**Die drei offenen Positionen der Vorgängerrunde wurden am committeten Stand
nachgemessen, keine ist gegenstandslos geworden:** 52 — `AGENTS.md:123-125`
(Restart-Regel) und `:131` (Verifikator-Rolle) stehen unverändert im Widerspruch zur
Upstream-Fassung, und die Upstream-Sätze selbst sind in dieser Range **unverändert**
(`git diff 787670a..0449b05 -- .agents/skills/build-verify/SKILL.md
.agents/rules/build-verify.md` ändert weder „Fresh session per round" noch die
Gates-Regel); 53 — die Zahlen oben; 54 — `.codegraph/` trägt weiterhin **nur**
`.gitignore` (`git ls-tree -r HEAD -- .codegraph/` → 1 Eintrag), `core.hooksPath` ist
weiterhin **nicht** gesetzt (`git config --list --show-origin | grep -i hookspath` →
keine Ausgabe), `.git/hooks/pre-commit` existiert weiterhin **nicht**.

**Regel-Drift (§5 Schritt 3) auf dieser Maschine: kein Befund.** Die globale
`~/.config/opencode/AGENTS.md` besteht aus **vier** Blöcken (`## CodeGraph` L2,
`## Grundsätze` L12, `## Always-on rules` L23, `## Dieser Host (code-dev)` L29) —
**eine** Regel daraus ist aus `.agents/rules/` eingebettet: die Frage-Regel aus
`.agents/rules/ask.md`. Die anderen drei Blöcke haben eigene Quellen (CodeGraph-Setup,
allgemeine Projekt-Grundsätze, Host-Beschreibung) und sind **keine**
`.agents/rules/`-Einbettung. Die Quelle der Frage-Regel ist in dieser Range
**unverändert** (`git diff 787670a..0449b05 -- .agents/rules/ask.md` → leer), die
Pflicht also unverändert. `.agents/rules/build-verify.md` hat in dieser Range 4
Zeilen gewonnen (Regel 5: die Zahlen-Frame-Pflicht) — sie hat **keine** Einbettung
(das Projekt verweist auf den Skill), ist also kein Drift. `agent-config` §2a und die
Aussage der globalen Config decken sich: `~/.config/opencode/opencode.jsonc:7` sagt
„Kein `instructions`-Key" — das ist genau die Aussage, die `0024d7e` upstream trifft.

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
   - trifft zu und ist drin → `angewendet: ja` + **stabile Referenz** (Abschnitt/
     Überschrift/Suchstring; Zeilennummer nur als Lesehilfe)
   - trifft zu, ist nicht drin → offene Position: Eintrag in `AGENTS.todo.md` anlegen
   - trifft nicht zu (das Projekt hat das nicht) → `angewendet: nein` + kurzer Grund
   - **gelöschte Skills** nicht stillschweigend weg: im Bereichs-Abschnitt
     „`<skill>` — entfernt in `<sha>`" notieren, dann verschwindet die Zeile
4. **Alle bestehenden Anker neu prüfen** — Advancing ist kein Updating; eine
   Zeilennummer in einer wachsenden Datei hat ein Verfallsdatum. Gemessen wird gegen
   den **committeten** Stand (`git show <rev>:<datei>`), nicht gegen einen Working Tree.
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
