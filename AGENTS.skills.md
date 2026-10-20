# Skills-Marker

agents-skills-consumed: 787670a
geprüft am: 2026-10-20

> Drittmarker. Geprüfte Range `6629ad2..787670a` (37 Commits des Skills-Repos).
> Geprüft am 2026-10-19/20 gegen `787670a` als Endstand der Range. Der Skills-Repo
> stand danach bei `0024d7e`: die vier Commits `787670a..0024d7e` (neue Hidden-Skills
> `ratchet-gates` und `generated-docs`; `agent-config`, `update-models` und
> `github-ci-filters` jetzt hidden) sind **nicht** Teil dieser Range und gehören in
> den nächsten Lauf — die Tabelle führt sie trotzdem, weil sie den **aktuellen**
> Skill-Bestand abbildet.
> Der Pfad zum Skills-Repo steht hier bewusst nicht (Marker bleibt bei Verschiebung
> gültig). Jede Ja-Zeile nennt eine **stabile Referenz** (Abschnitt, Überschrift oder
> Suchstring); Zeilennummern nur als Lesehilfe.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Konfiguration (opencode.jsonc, MCP, Skill-/Command-Registrierung, Modell-Registry) — das Projekt hat keine opencode.jsonc und keine Modell-/MCP-Datei |
| `build-verify` | ja | `AGENTS.md` §5 Punkt 4 „Build-/Verify-Flow — zentral, nicht hier" (`:114-117`) | Position 52 — zwei Sätze in `AGENTS.md` §5 widersprechen der neuen Skill-Fassung |
| `codegraph-project-setup` | nein | | trifft nicht zu, weil die Änderung den Scope für **neue** Projekte klärt (Code-Projekt ja / Markdown-Repo nein) — dieses Repo wird nicht gescaffoldet und definiert keine CodeGraph-Pflicht. Stand gemessen: kein Index (`codegraph status`: „Not initialized"), und die Hook-Datei `.githooks/pre-commit` (CodeGraph-Sync) läuft nicht, weil `core.hooksPath` nirgends gesetzt ist |
| `docker-test-image` | ja | `.github/workflows/e2e-image.yml` (Job `build`, BASE_REF-/PLAYWRIGHT_VERSION-Auflösung), `deployment/Dockerfile.e2e` (ARG-Block), `features/05-e2e-test-image.md` | |
| `generated-docs` | nein | | außerhalb der geprüften Range (neu in `00dbd22`) — Prüfung folgt im nächsten Marker-Lauf. Sichtung heute: das Repo hat **keine** generierten Doku-Artefakte (README-/features-Zahlen stehen als Prosa in der Hand, genau der Fall, den der Skill adressiert) — das ist aber erst im nächsten Lauf zu entscheiden, nicht hier |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml` (Kommentar „ein public GHCR-Package" + `image: ghcr.io/…`), `features/05-e2e-test-image.md` (Abschnitt „public GHCR-Package") | keine — beide Packages public (geprüft 2026-10-20: `gh api visibility=public` für `accriditation-e2e` und `accriditation-base`) |
| `github-ci-filters` | ja | `.github/workflows/ci.yml` → `paths-ignore: '**/*.md'` auf `push` **und** `pull_request` | |
| `laravel` | ja | `backend/AGENTS.md` (Commands, DB-/Migrations-Policy, Parallel-Testing, Datei-Tests), `backend/phpunit.xml` (Hermetik-Pins), `backend/composer.json` (PHP ^8.5, pint, paratest, jwt-auth) | Position 53 — Validierungs-Konvention nicht umgesetzt: `FormRequest`-Struktur fehlt, `rules()` bei 8/23 Controllern getragen, 17 mit Inline-Array |
| `node-deps` | ja | `frontend/package.json` (keine Pins/Overrides), `ci.yml` (`version: 11`), `e2e-image.yml` (`PNPM_VERSION: "11"`), `deployment/Dockerfile.e2e` (`ARG PNPM_VERSION=11`) | |
| `permissions` | nein | | opencode.jsonc-Policy (Maschine) — das Projekt definiert keine; `backend/config/permissions.php` ist die Gate-Matrix des Projekts, nicht diese Policy |
| `playwright-parallel` | ja | `.github/workflows/ci.yml` (Run-Step: strikt `--workers=4`, verzeihend `--workers=1`), `frontend/playwright.config.ts` (`workers:`, `fullyParallel`) | |
| `ratchet-gates` | nein | | außerhalb der geprüften Range (neu in `70d49e0`) — Prüfung folgt im nächsten Marker-Lauf; das Repo kennt keine Schulden-Baseline (`AGENTS.md` §4 Zero-Pre-existing-Failures-Policy) |
| `react-i18n` | ja | `frontend/AGENTS.md` (§ „Lingui / i18n — No Module-Scope `t` (STRICT)"), `frontend/vite.config.ts` (lingui-Plugin + Babel-Preset-Reihenfolge), `frontend/src/main.tsx` (I18nProvider als erster Import), `frontend/package.json` (`lingui:extract`/`lingui:compile`, `check:i18n` in `prebuild`) | keine — das Projekt weicht bewusst ab: zwei Locales (de+en) mit Vollständigkeits-Gate statt der Portal-Referenz (eine Locale + Rohstring-Pass) |
| `security-review` | ja | `AGENTS.md` §10 (Fundort der Befunde) + `features/security/accepted-risks.md` (Register A1–A8), `AGENTS.md` §3 (Regressionstest je Fix) | keine — Register + Test-Pinning sind getragen; die Route-Enumeration ist Teil des beauftragten Review-Laufs, keine Dauer-Pflicht |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei; Abschnitt „Fortschreiben") | |
| `tailscale-serve` | nein | | kein Tailscale-Bezug im Projekt (repo-weiter Treffer: nur diese Datei) |
| `ui-review` | ja | `AGENTS.md` §7 „Visuelle Verifikation / UI-Review (Design-QA, STRICT)" (`:262-271`, Loop-Schritte 1–4 `:273-300`), `frontend/tests/screenshots/` | |
| `update-models` | nein | | Modell-Registry (Maschine) — das Projekt hat keine opencode.jsonc und keine Modell-Datei |
| `web-audit` | nein | | trifft nicht zu, weil das Projekt keine Marketing-/Content-Website hat (kein SEO-, Sitemap- oder Performance-Audit-Gegenstand) — die visuelle Prüfung der App ist der Screenshot-Design-QA-Loop (`ui-review`), ausdrücklich getrennt von funktionalen Tests |

## Offen aus dem Bereich 6629ad2..787670a (2026-10-20)

> **Herkunft:** Fortschreibung des Markers `AGENTS.skills.md`
> (`agents-skills-consumed: 6629ad2` → `787670a`). Die Range umfasst **37 Commits**
> und berührte **21 Skill-Verzeichnisse** unter `.agents/skills/`:
> `agent-config`, `build-verify`, `codegraph-project-setup`, `docker-test-image`,
> `ghcr-visibility`, `github-ci-filters`, `laravel`, `model-updater`, `models`,
> `node-deps`, `permissions`, `playwright-parallel`, `react-i18n`, `security-review`,
> `skills-marker`, `tailscale-serve`, `ui-review`, `update-models`,
> `update-opencode-models`, `vision-agents`, `web-audit`.
> **Nichts hiervon ist implementiert** — das sind Positionen, keine Arbeitsaufträge:
> `AGENTS.todo.md` ist ein Log, kein Auftrag.

**Neu in der Range:** `laravel` (Backend-Konventionen, destilliert aus
open-accreditation + portal), `react-i18n` (Lingui-Muster), `security-review`
(hidden, `autoinvoke: false`), `web-audit` (Live-Website-Audit mit
False-Positive-Gate), `models` (zusammengeführt aus `model-updater` +
`update-opencode-models`, in `fc28ea3` zu `update-models` umbenannt).

**Entfernt in der Range** (nicht stillschweigend weg — die Zeilen sind mit dieser
Fortschreibung aus der Tabelle verschwunden, der Inhalt bleibt hier nachvollziehbar):
`vision-agents` — entfernt in dccdd40 · `model-updater` — entfernt in 1c51237
(aufgegangen in `models`, später `update-models`) · `update-opencode-models` —
entfernt in 1c51237 (ebenfalls aufgegangen in `models`).

**Ergebnis je Skill aus der Range** — `laravel` → **ja** (Konventionen tragen
`backend/AGENTS.md` + `backend/phpunit.xml`; offen bleibt Position 53) ·
`react-i18n` → **ja** (Lingui-Muster getragen; Abweichung zwei Locales ist
Entscheidung D15, kein Gap) · `security-review` → **ja** (Register + Test-Pinning
getragen; Aufruf-Workflow, keine Dauer-Pflicht) · `web-audit` → **nein** (kein
Marketing-Site-Gegenstand) · `build-verify` → **ja** (Flow delegiert, offen bleibt
Position 52) · `ui-review` → **ja** (§7-Loop getragen; die Vision-Eskalation geht
seit `f58f2ae` an das Modell selbst) · `skills-marker` → **ja** (diese Datei) ·
`docker-test-image`, `ghcr-visibility`, `github-ci-filters`, `node-deps`,
`playwright-parallel` → **ja** (unverändert angewendet; die Range änderte an ihnen
nur Beschreibung und `autoinvoke`-Flag) · `agent-config`, `permissions`,
`update-models`, `tailscale-serve` → **nein** (Maschinen-Konfiguration bzw. kein
Projektbezug) · `codegraph-project-setup` → **nein** (Scope-Klarstellung für neue
Projekte; dieses Repo wird nicht gescaffoldet).

**Die Alt-Anker wurden vollständig neu geprüft — 7 von 15 Ankern der
Vorgängerversion zeigten nicht mehr auf das Bezeichnete.** Falsch waren
`build-verify` (`AGENTS.md:147-155` → heute die Peak-Regel, der Flow steht bei
`:114-117`), `docker-test-image` (`ci.yml:534` → `container:` steht heute bei
`:539`), `ghcr-visibility` (`ci.yml:522-524` → der GHCR-Hinweis steht heute bei
`:526-530`), `playwright-parallel` (`ci.yml:782-786` → die Worker-Begründung steht
heute bei `:792-802`, die Kommandos bei `:866-870`) sowie alle drei Anker der
Zeile `ui-review` und der Zeile `vision-agents` — die beiden letzteren lagen
**jenseits des Dateiendes** (`AGENTS.md` hat heute 380 Zeilen, die Anker endeten bei
433 bzw. 421; `ui-review:361-433` zeigt heute auf den Modul-Abschnitt §9). Exakt
hielten dagegen alle vier Anker der Zeile `node-deps` (`ci.yml:481`/`:739`,
`e2e-image.yml:64`, `Dockerfile.e2e:117`) und `github-ci-filters` (`ci.yml:61-67`);
`ghcr-visibility`/`docker-test-image` hielten je einen ihrer zwei Anker. Zwei
Zeilen der Vorgängerversion (`model-updater`, `update-opencode-models`) und die
Zeile `vision-agents` sind mit dieser Fortschreibung weg — der Skill existiert
upstream nicht mehr (siehe „Entfernt in der Range"). **Die Zeile `codegraph-project-setup`
trug eine unvollständige Begründung** („keine Hook-Verdrahtung" — die Datei
`.githooks/pre-commit` existiert, ist aber nirgends verdrahtet, weil
`core.hooksPath` nicht gesetzt ist); die neue Zeile nennt beides. Alle Zeilen der
neuen Tabelle nennen deshalb Abschnitt, Überschrift oder Suchstring; Zeilennummern
stehen nur als Lesehilfe dahinter.

**Regel-Drift (§5 Schritt 3) auf dieser Maschine: kein Befund.** Die einzige
eingebettete Regel der globalen `~/.config/opencode/AGENTS.md` ist die Frage-Regel
aus `.agents/rules/ask.md` — die Quelle ist in dieser Range **unverändert**, die
Pflicht also unverändert. `build-verify.md` ist nicht eingebettet (das Projekt
verweist auf den Skill), und die `agent-config`-Änderungen der Range betreffen die
maschinenweite Verdrahtung, nicht das Projekt.

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
   Zeilennummer in einer wachsenden Datei hat ein Verfallsdatum.
5. Offene Positionen nach `AGENTS.todo.md`; Belege als stabile Referenz.
6. Erst wenn für **jeden** Skill aus der Range eine Zeile steht:
   `agents-skills-consumed: $(git -C <skills-repo> rev-parse --short HEAD)`,
   `geprüft am:` auf heute, Abschnitte ersetzen.
7. Falls Regeln oder Skills betroffen waren: die eingebetteten Regeln in der
   globalen `~/.config/opencode/AGENTS.md` gegen ihre Quelle in `.agents/rules/*`
   prüfen und bei geänderter **Pflicht** sinngemäß nachziehen. Drift ist eine
   geänderte Pflicht, kein abweichender Wortlaut — die Einbettung ist die Kurzform
   der Pflicht, kein wörtliches Abbild. Dann `touch ~/.config/opencode/opencode.jsonc`
   (Reload der globalen Config; das `instructions`-Array ist leer und liefert nichts).
