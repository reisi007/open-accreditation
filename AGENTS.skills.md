# Skills-Marker

agents-skills-consumed: 6629ad289f6685d58f900a023a8bfa6ab3ba70c4
geprüft am: 2026-10-04

> Zweitmarker. Geprüfte Range `aad25d14..6629ad2` (`2969d5a`: zwölf Skills
> gestrafft + Englisch-Pass, Details nach `references/`; `6629ad2`: README).
> Geprüft am 2026-10-04 gegen `6629ad2` als HEAD des Skills-Repos. Der Pfad
> zum Skills-Repo steht hier bewusst nicht (Marker bleibt bei Verschiebung
> gültig). Jede Ja-Zeile nennt `Datei:Zeile` in diesem Repo; Nein-Zeilen
> nennen den Grund.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Konfiguration (opencode.jsonc, MCP, Skill-Registrierung); im Projekt kein opencode.jsonc, keine Modell-/MCP-Datei |
| `build-verify` | ja | `AGENTS.md:147-155` | |
| `codegraph-project-setup` | nein | | nur `.codegraph/.gitignore` (Stub, kein Index); keine Hook-Verdrahtung, kein Projekt-Bezug |
| `docker-test-image` | ja | `.github/workflows/e2e-image.yml:1-51`, `ci.yml:534` (`container:`) | |
| `ghcr-visibility` | ja | `ci.yml:522-524`, `features/05-e2e-test-image.md:165-167` | keine — beide Packages public (geprüft 2026-10-04: anonymer Pull-Token für beide erteilt; `gh api` meldet `visibility=public` für `accriditation-e2e` und `accriditation-base`) |
| `github-ci-filters` | angewendet: ja | `ci.yml:61-67` | |
| `model-updater` | nein | | keine Modell-Konfiguration im Projekt (Maschinen-Sache wie `agent-config`) |
| `node-deps` | angewendet | Pin + Overrides entfernt (Runden 48/49; `ci.yml:481`/:`739` `version: 11`, `e2e-image.yml:64` env, `Dockerfile.e2e:117` ARG) | |
| `permissions` | nein | | opencode.jsonc-Policy (Maschine); das Projekt definiert keine |
| `playwright-parallel` | angewendet: ja | `ci.yml:782-786` | |
| `skills-marker` | ja | `AGENTS.skills.md` | |
| `tailscale-serve` | nein | | kein Tailscale-Bezug im Projekt (repo-weiter grep ohne Treffer) |
| `ui-review` | ja | `AGENTS.md:361-433`, `:424-425` | |
| `update-opencode-models` | nein | | keine Modell-Registry im Projekt (wie `model-updater`) |
| `vision-agents` | ja | `AGENTS.md:418-421` | |

## Offen aus dem Bereich aad25d14..6629ad2 (2026-10-04)

Geänderte Skills in der Range: `agent-config`, `codegraph-project-setup`,
`github-ci-filters`, `model-updater`, `node-deps`, `skills-marker`,
`tailscale-serve`, `ui-review`, `update-opencode-models`, `vision-agents`
(dazu README, kein Skill). Ergebnis je Skill:

- `node-deps` → umgesetzt 2026-10-05 (Position 50, Board-Vermerk folgt Verifikation): Pin + Overrides entfernt, `PNPM_VERSION` ist Workflow-Major-Linie (keine `packageManager`-Extraktion mehr).
- `skills-marker` → bleibt angewendet (`AGENTS.skills.md`): neuer
  Drift-Check (§5 Schritt 3) auf dieser Maschine ohne Befund (eingebettete
  Kopien in der globalen `AGENTS.md` identisch mit `.agents/rules/*.md`,
  2026-10-04); neue Konventions-Zeilen (§4) regeln hier nichts — keine
  Skill-Datei im Projekt, also keine neue Zeile.
- `agent-config` → bleibt nicht anwendbar (Maschine, Begründung wie bisher);
  keine Referenz in Marker oder Board zeigt auf eine verschobene Stelle —
  Codeblöcke liegen jetzt in `references/snippets.md`, Abschnitte (§2a, §8)
  unverändert adressierbar.
- `github-ci-filters`, `model-updater`, `tailscale-serve`,
  `codegraph-project-setup`, `ui-review`, `update-opencode-models`,
  `vision-agents` → nur gestrafft/übersetzt, Details nach `references/`;
  keine Pflicht, Schwelle oder Ausnahme, auf die sich eine Marker-Zeile oder
  Position 48–51 stützt, ist entfallen (`wc -l` je `SKILL.md` vorher/nachher
  verglichen). Tabellenzeilen unverändert.

Dieser Abschnitt wird beim nächsten Pull im Skills-Repo durch den neuen
Range-Abschnitt (`<dieser-sha>..<neuer-sha>`) ersetzt; bleibt die Liste leer,
entfällt er ganz.

## Fortschreiben

1. `agents-skills-consumed` ablesen → `<alt>`.
2. Im Skills-Repo die Range bilden:
   `git -C <skills-repo> log --oneline <alt>..HEAD`
   `git -C <skills-repo> log --name-only --format= <alt>..HEAD -- .agents/skills | sort -u`
3. Jeden betroffenen Skill gegen **dieses** Projekt prüfen. Trifft eine Änderung zu, wenn
   sie eine Pflicht, Schwelle, Ausnahme, Konvention oder ein Kommando betrifft, das
   **hier** gilt:
   - trifft zu und ist drin → `angewendet: ja` + `Datei:Zeile`
   - trifft zu, ist nicht drin → offene Position: Eintrag in `AGENTS.todo.md` anlegen
   - trifft nicht zu (das Projekt hat das nicht) → `angewendet: nein` + kurzer Grund
4. Offene Positionen nach `AGENTS.todo.md`; angewendete Zeilen mit `Datei:Zeile` belegen.
5. Erst wenn für **jeden** Skill aus der Range eine Zeile steht:
   `agents-skills-consumed: $(git -C <skills-repo> rev-parse --short HEAD)`,
   `geprüft am:` auf heute, Abschnitte ersetzen.
6. Falls Regeln oder Skills betroffen waren:
   `touch ~/.config/opencode/opencode.jsonc`.
