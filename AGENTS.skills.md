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
> Skills). Jede Zeile nennt eine **stabile Referenz** (Abschnitt, Überschrift oder
> Suchstring); Zeilennummern nur als Lesehilfe. Der Pfad zum Skills-Repo steht hier
> bewusst nicht — der Marker bleibt bei einer Verschiebung gültig.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Konfiguration (globale `opencode.jsonc`, MCP, Skill-/Command-Registrierung) — das Projekt hat keine `opencode.jsonc` und keine Modell-/MCP-Datei. **Seit `817c01e` hidden** (nur per exakter ID ladbar) — das ändert die Anwendbarkeit nicht: die Pflicht bleibt eine Pflicht der Maschine, nicht des Projekts |
| `build-verify` | offen | `AGENTS.md` §5 Punkt 4 „Build-/Verify-Flow — zentral, nicht hier" (`:114-117`) | Position 52 — zwei Sätze in `AGENTS.md` §5 widersprechen der Skill-Fassung; die zitierten Upstream-Sätze sind in dieser Range **unverändert** |
| `codegraph-project-setup` | offen | `.githooks/pre-commit` (CodeGraph-Sync-Hook, versioniert — **läuft aber nicht**: `core.hooksPath` nirgends gesetzt, `.git/hooks/pre-commit` fehlt); `.codegraph/` trägt **nur** `.gitignore`, **keinen** Index (`codegraph status`, gemessen 2026-10-21: „⚠ Not initialized") | Position 54 — der Pflichtteil des Skills (Index + verdrahteter Hook) ist **nicht erfüllt**; am committeten Stand `a4551c8` neu gemessen, unverändert |
| `docker-test-image` | ja | `.github/workflows/e2e-image.yml` (Job `build` `:67`; PLAYWRIGHT_VERSION-Auflösung `:91-94` im Schritt „Extract build args from repository" `:79` — der `PNPM_VERSION` kommt aus dem Job-`env` `:87` und wird `:93-95` geprüft; **BASE_REF**-Auflösung im eigenen Schritt „Resolve base image reference (digest pass-through)" `:107-147`, der Wert `base_ref` steht in `:147` und wird `:164` als Build-Arg verbraucht), `deployment/Dockerfile.e2e` (ARG-Block `:68`, `:115`), `features/05-e2e-test-image.md` | keine |
| `generated-docs` | offen | `features/venue-master-data.md` „Portabilität (§2)" (`:358-368`: die Gate-Zahlen nennen Lauf `37280377310`, Datum und Leser — die Zahl steht **in** der Prosa), `features/auth/01-auth-and-roles.md` (`:430-454`: die unbezifferte Zahl wurde durch **andere Prosa** ersetzt) | **Position 56** — die Pflicht trifft zu und ist **nicht erfüllt**: das Projekt hat **weder einen Generator noch ein Drift-Gate** (gemessen am committeten Stand `a4551c8`: `scripts/` enthält nur `assert-db-driver.php`, `dev-worker.sh`, `e2e-up.sh`, `lib/`, `pdf-to-png-vision.sh`, `render-badge-photo-placeholder.mjs`, `test-pgsql.sh`; im committeten Baum keine Datei mit `gen`/`drift`/`doc_fact` im Namen). Die Skill-Regel nennt die Zahl in Prosa ausdrücklich als Fehlerfall (`generated-docs/SKILL.md:74-80`). **Kein „nein"**, weil das Projekt die Pflicht in eigener Form trägt: `AGENTS.md:80` („für ein Dokument Diff gegen den Code, nicht gegen die Absicht") — die Lücke ist der **fehlende maschinelle Check**, nicht die fehlende Pflicht |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` (Kommentar „ein public GHCR-Package" `:526-530` + `image: ghcr.io/…` `:550`), `features/05-e2e-test-image.md` (`:167-168`) | keine — beide Packages public (geprüft 2026-10-20: `gh api visibility=public` für `accriditation-e2e` und `accriditation-base`) |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` → `paths-ignore: '**/*.md'` auf `push` **und** `pull_request` (`:63`, `:66`) | keine — **seit `817c01e` hidden**: die Pflicht gilt unverändert, nur der Ladeweg ändert sich (per exakter ID) |
| `laravel` | offen | `backend/AGENTS.md` (Commands, DB-/Migrations-Policy, Parallel-Testing, Datei-Tests), `backend/phpunit.xml` (Hermetik-Pins), `backend/composer.json` (PHP ^8.5, pint, paratest, jwt-auth) | Position 53 — Validierungs-Konvention nicht umgesetzt; am committeten Stand `a4551c8` neu gemessen: `FormRequest`-Klassen **0**, `rules()` bei **8** von **23** Controllern, Inline-Array bei **17** |
| `node-deps` | ja | `frontend/package.json` (keine Pins/Overrides), `ci.yml` (`version: 11` `:481`, `:739`), `e2e-image.yml` (`PNPM_VERSION: "11"` `:64`), `deployment/Dockerfile.e2e` (`ARG PNPM_VERSION=11` `:117`) | keine |
| `permissions` | nein | | opencode.jsonc-Policy (Maschine) — das Projekt definiert keine; `backend/config/permissions.php` ist die Gate-Matrix des Projekts, nicht diese Policy |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` (Run-Step: die Worker-Begründung `:792-802`, die Kommandos `:866-870`), `frontend/playwright.config.ts` (`fullyParallel: true` `:24`, `workers: process.env.CI ? 4 : 8` `:27`) | keine |
| `ratchet-gates` | offen | `features/security/accepted-risks.md` (A1–A8: kanonischer Status `accepted` + kanonische Niveaus `low`/`medium`, stabile Ids — **getragen**), `backend/config/permissions.php` („Single source of truth for the authorization layer") | **Position 57** — die **Owner-/Ticket-Regel** fehlt: `ratchet-gates/references/vocabulary.md:44` („A finding needs an owner and a ticket unless it is `fixed`") und `ratchet-gates/SKILL.md:119` („an owner and a ticket per entry") sind **neu in dieser Range**, und das Projekt trägt sie nirgends (gemessen am committeten Stand `a4551c8`: `git grep -ciE 'owner\|ticket' HEAD -- features/security/accepted-risks.md` → **0** Treffer; im Board 4 Treffer, **alle** im Text der Position 55 — gemessen vor den Positionen 56/57, also **kein** Board-Eintrag außerhalb der eigenen Texte). **Der Ratchet-Teil ist nicht anwendbar** — dafür ist **keine** Position nötig, das ist eine begründete Abweichung: im committeten Baum gibt es kein Baseline-Artefakt (die `baseline`-Treffer sind `maxFailures` in den Playwright-Konfigurationen und die Prosa „Gate-Baseline" im Board; `known.?failure`/`xfail`: **0** Treffer), und `AGENTS.md:99-101` verbietet getragene Altfehler (Zero-Pre-existing-Failures-Policy) — die **7** `markTestSkipped` (am committeten Stand `a4551c8`, 5 Dateien) sind je **umgebungsbedingt** und mit Ursache benannt, also kein getragener Fehlerbestand |
| `react-i18n` | ja | `frontend/AGENTS.md` (§ „Lingui / i18n — No Module-Scope `t` (STRICT)" `:97`), `frontend/vite.config.ts` (lingui-Plugin + Babel-Preset `:32-33`), `frontend/src/main.tsx` (I18nProvider als erster Import `:1`, `:5`), `frontend/package.json` (`lingui:extract`/`lingui:compile`, `check:i18n` in `prebuild`) | keine — das Projekt weicht bewusst ab: zwei Locales (de+en) mit Vollständigkeits-Gate statt der Portal-Referenz (eine Locale + Rohstring-Pass) |
| `security-review` | ja | `AGENTS.md` §10 (Fundort der Befunde) + `features/security/accepted-risks.md` (Register A1–A8), `AGENTS.md` §3 (Regressionstest je Fix `:59`) | keine — das Register trägt die kanonischen Stände (`accepted`) und Niveaus (`low`/`medium`) mit stabilen Ids; die Klassen BELEGT/HEURISTIK/AKZEPTIERT bleiben daneben stehen und kollidieren nicht (im Board gemessen: **0** Treffer auf die Klassenliste; als Schweregrade kommen dort nur die kanonischen Wörter vor — `critical`/`high`/`medium`/`low`/`info`; `Major`/`hoch`/`niedrig` nur in Prosa) |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei; Abschnitt „Fortschreiben") | keine |
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

## Offen aus dem Bereich 787670a..0449b05 (2026-10-21)

> **Herkunft:** Fortschreibung des Markers `AGENTS.skills.md`
> (`agents-skills-consumed: 787670a` → `0449b05`). Die Range umfasst **12 Commits**
> und berührte **9 Skill-Verzeichnisse**. **Die Tabelle ist ein Zustand, kein Auftrag.**
> Die 9 Skill-Verzeichnisse oben sind **keine** offenen Positionen und **keine** 9
> unerledigten Arbeiten: die Tabelle sagt je Skill, ob seine Pflicht hier **trifft**
> und ob sie **erfüllt** ist. **Eine Bedeutung von „offen" — und sie ist nicht
> „ungeprüft".** Das **Verdikt `offen`** heißt in dieser Tabelle: *geprüft, die Pflicht
> trifft zu und ist nicht erfüllt* — die Lücke steht als **Position** im Board. Die
> **Prüfung** selbst
> ist für **jeden** der 9 Skill-Verzeichnisse aus dieser Range abgeschlossen; die
> beiden Zeilen, die in der Vorgängerversion noch „ungeprüft" waren
> (`generated-docs`, `ratchet-gates`), sind entschieden. Eine **Lücke** ist
> **gemessen** und steht als **Position** im Board (`AGENTS.todo.md`): 52
> (`build-verify`), 53 (`laravel`), 54 (`codegraph-project-setup`), 55 (`ui-review`),
> 56 (`generated-docs`), 57 (`ratchet-gates`) — alle **sechs** sind `offen`-Zeilen.
> Beides ist ein Log, kein Arbeitsauftrag.

**Je Commit der Range, in der Reihenfolge der Range** — jeder Effekt an
`git show --stat` am committeten Stand gemessen:

| Commit | Was |
|---|---|
| `219171c` | `update-models`: Namespace-Regel und Snapshot an die neue Config angeglichen (`references/registry.md`: Snapshot 2026-10-19, `opencode-go/minimax-m3.1-flash-free`). Reine Textänderung an einem bestehenden Skill. |
| `fa2268f` | `ui-review`: der „vision-agent"-Wortlaut aus der Findings-Vorlage entfernt (`references/findings-report.md`, 1 Zeile). Reine Textänderung. |
| `8c6b9af` | `update-models`: die `$0`-Aussage auf das konfigurierte Modell eingeschränkt (3 Zeilen ersetzt). Reine Textänderung. |
| `70d49e0` | **legt den Skill `ratchet-gates` an** (SKILL + **3** Referenzen: `check-tiers.md`, `generated-docs.md`, `ratchet-baselines.md` — `vocabulary.md` kam mit `b0d1b51`, `cli.md` mit `d7888cd`); ändert zusätzlich `agent-config` (versteckt den neuen Skill) und `README.md`. |
| `00dbd22` | **legt den Skill `generated-docs` an** (SKILL + **2** Referenzen: `source-of-truth.md`, `drift-and-generation.md`); ändert zusätzlich `agent-config`, `ratchet-gates` und `README.md`. |
| `817c01e` | **versteckt `agent-config`, `update-models` und `github-ci-filters`** (je `metadata.opencode/autoinvoke: false`); kürzt zusätzlich Beschreibung und Body von `build-verify` und zieht `README.md` und `.github/skill-budget.json` nach. |
| `0024d7e` | schließt die Drift um den ungenutzten `instructions`-Key — in `agent-config` (SKILL + `references/snippets.md`), `build-verify` (SKILL + `references/notes.md`), `skills-marker` (`references/notes.md` + `templates/AGENTS.skills.md`), der Regel `.agents/rules/build-verify.md` und `README.md`; an `AGENTS.md` änderte derselbe Commit nur die Kennzeichnung von `agent-config` als hidden („load it by exact ID", **keine** `instructions`-Aussage) — und zieht `.github/skill-budget.json` nach. |
| `5a0c1b0` | **berührt überhaupt keinen Skill** — nur `AGENTS.todo.md` des Skills-Repos (30 Einfügungen, 0 Löschungen). Das ist der **eine** Commit der Range ohne jeden `.agents/`-Pfad. |
| `b0d1b51` | **ein gemeinsames Vokabular** für Findings- und Gap-Listen: `ratchet-gates/references/vocabulary.md` (neu) wird die Quelle (5 Niveaus, 5 Stände, Zählregel, Eigentümer-/Ticket-Regel, „Severity und Status getrennt"); `security-review` (SKILL + `references/findings-and-waves.md`), `ui-review` (`references/findings-report.md`) und `build-verify` (`references/verifier-prompt.md`) zeigen darauf, statt eigene Listen zu führen. |
| `b9f140b` | die Findings der Vokabular-Verifikation geschlossen: die security-review-Reihenfolge nennt jetzt `info`, die ui-review-`medium`-Zeile sagt, was ohne Workaround passiert. |
| `d7888cd` | die ratchet-CLI: eine Karte von den Gates zu den Kommandos (`ratchet-gates/references/cli.md`, neu; **134** Einfügungen, 0 Löschungen). |
| `0449b05` | **die geteilte Regel: jede Zahl im `Verify:`-Footer nennt, was sie misst** (Zustand: Working Tree/committeter Stand; Umfang: gefilterte Teilmenge, Range, Stufe) — in der Regel `.agents/rules/build-verify.md` (3 Einfügungen, 0 Löschungen), `build-verify/SKILL.md` (1 Einfügung) und `build-verify/references/notes.md` (**203 → 262 Zeilen**, gemessen am **direkten Vorgänger** `d7888cd`; **60 Einfügungen, 1 Löschung**). `verifier-prompt.md` hat **dieser** Commit nicht angefasst (das war `b0d1b51`). |

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

**Ergebnis je Skill aus der Range** — `build-verify` → **offen** (die Pflicht trifft zu
und ist nicht erfüllt: die Divergenz in `AGENTS.md` §5; die Range fügt die
Zahlen-Frame-Pflicht hinzu, die über `AGENTS.md` §5 Punkt 4 („gilt hier unverändert")
mitgilt und hier keine eigene Form braucht — Position 52) · `laravel` → **offen**
(unverändert: Position 53) · `codegraph-project-setup` → **offen** (unverändert:
Position 54) · `ui-review` → **offen** (der Loop trägt, die Findings-Vorlage in
`AGENTS.md` §7 Schritt 3 ist die alte — Position 55) · `generated-docs` → **offen**
(Entscheidung der Orchestrierung: die Pflicht trifft zu — Zahlen in Prosa, die aus
einem Lauf stammen —, aber das Projekt hat weder Generator noch Drift-Gate; Position
56) · `ratchet-gates` → **offen** (Entscheidung der Orchestrierung: Stände, Niveaus
und stabile Ids sind getragen, die Owner-/Ticket-Regel fehlt; der Ratchet-Teil ist
nicht anwendbar; Position 57) · `security-review` → **ja** (unverändert; das Register
trägt die kanonischen Stände und Niveaus) · `skills-marker` → **ja** (diese Datei) ·
`github-ci-filters` → **ja** (unverändert angewendet; die Range änderte an ihm nur die
Sichtbarkeit) · `agent-config`, `update-models` → **nein** (Maschinen-Konfiguration) ·
`docker-test-image`, `ghcr-visibility`, `node-deps`, `playwright-parallel`,
`react-i18n` → **ja** (unverändert angewendet; die Range berührte sie nicht).

**Die Alt-Anker wurden vollständig neu geprüft — alle 37 `Datei:Zeile`-Anker der
Tabelle lösen auf das Bezeichnete.** Geprüft wurde gegen den **committeten** Stand des
Projekts (`a4551c8`, `git show <rev>:<datei>` / `git grep <rev> -- <pfad>`), nicht gegen
einen Working Tree. Die geprüften Stellen: `AGENTS.md:114-117` (Build-/Verify-Flow),
`:59` (Regressionstest), `:262-271` und `:273-300` (UI-Review-Loop), `:293-295`
(Findings-Report), `ci.yml:63`/`:66` (paths-ignore), `:481`/`:739` (pnpm-Version),
`:526-530` und `:550` (GHCR), `:792-802` und `:866-870` (Playwright-Worker),
`e2e-image.yml:64` (PNPM_VERSION), `:67` (Job `build`), `:79`/`:87`/`:91-94`/`:93-95`
(PLAYWRIGHT_VERSION-/PNPM_VERSION-Auflösung) und `:107-147`/`:147`/`:164` (BASE_REF-
Auflösung, Wert, Verbrauch), `Dockerfile.e2e:68`/`:115`/`:117` (ARG-Block),
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
am committeten Stand `a4551c8`; die Zahlen sind unverändert gegenüber `787670a`).
**Die offenen Positionen wurden am committeten Stand nachgemessen, keine ist
gegenstandslos geworden:** 52 — `AGENTS.md:123-125`
(Restart-Regel) und `:131` (Verifikator-Rolle) stehen unverändert im Widerspruch zur
Upstream-Fassung, und die Upstream-Sätze selbst sind in dieser Range **unverändert**
(`git diff 787670a..0449b05 -- .agents/skills/build-verify/SKILL.md
.agents/rules/build-verify.md` ändert weder „Fresh session per round" noch die
Gates-Regel); 53 — die Zahlen oben; 54 — `.codegraph/` trägt weiterhin **nur**
`.gitignore` (`git ls-tree -r HEAD -- .codegraph/` → 1 Eintrag), `core.hooksPath` ist
weiterhin **nicht** gesetzt (`git config --list --show-origin | grep -i hookspath` →
keine Ausgabe), `.git/hooks/pre-commit` existiert weiterhin **nicht**; 55 — der
Findings-Report in `AGENTS.md:293-295` führt unverändert die alte Vorlage; 56 — kein
Generator und kein Drift-Gate im Baum (gemessen am committeten Stand `a4551c8`:
`scripts/` ohne Doku-Werkzeug, keine Datei mit `gen`/`drift`/`doc_fact` im Namen);
57 — kein Owner und kein Ticket je Registereintrag (gemessen am committeten Stand
`a4551c8`: `git grep -ciE 'owner|ticket' HEAD -- features/security/accepted-risks.md`
→ **0**).

**Regel-Drift (§5 Schritt 3) auf dieser Maschine: kein Befund.** Die globale
`~/.config/opencode/AGENTS.md` besteht aus **vier** Blöcken (`## CodeGraph` L2,
`## Grundsätze` L12, `## Always-on rules` L23, `## Dieser Host (code-dev)` L29) —
**eine** Regel daraus ist aus `.agents/rules/` eingebettet: die Frage-Regel aus
`.agents/rules/ask.md`. Die anderen drei Blöcke haben eigene Quellen (CodeGraph-Setup,
allgemeine Projekt-Grundsätze, Host-Beschreibung) und sind **keine**
`.agents/rules/`-Einbettung. Die Quelle der Frage-Regel ist in dieser Range
**unverändert** (`git diff 787670a..0449b05 -- .agents/rules/ask.md` → leer), die
Pflicht also unverändert. `.agents/rules/build-verify.md` hat in dieser Range **3**
Zeilen gewonnen (**39 → 42** Zeilen, gemessen an den committeten Ständen `787670a` und
`0449b05`; über die Range 4 Einfügungen und 1 Löschung, die vierte Einfügung stammt
aus `0024d7e`) — **Regel 5** selbst wächst um 3 Zeilen: die Zahlen-Frame-Pflicht. Die
Datei hat **keine** Einbettung (das Projekt verweist auf den Skill), ist also kein
Drift. `agent-config` §2a und die
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
