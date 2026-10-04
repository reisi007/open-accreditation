# Skills-Marker

agents-skills-consumed: aad25d145e92360b8ee7631151c1cdb6dae679eb
geprüft am: 2026-10-04

> Erstmarker. Geprüfter Stand ist genau HEAD des Skills-Repos zum Prüfzeitpunkt
> (`rev-parse --short HEAD` meldete `aad25d1`) — keine Commits nach dem
> Marker-SHA, keine Range zu bilden. Der Pfad zum Skills-Repo steht hier bewusst
> nicht (Marker bleibt bei Verschiebung gültig). Kein Skill ist als „angewendet"
> aus Lektüre markiert — jede Ja-Zeile nennt `Datei:Zeile` in diesem Repo.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Konfiguration (opencode.jsonc, MCP, Skill-Registrierung); im Projekt kein opencode.jsonc, keine Modell-/MCP-Datei |
| `build-verify` | ja | `AGENTS.md:151-155` | |
| `codegraph-project-setup` | nein | | nur `.codegraph/.gitignore` (Stub, kein Index); keine Hook-Verdrahtung, kein Projekt-Bezug |
| `docker-test-image` | ja | `.github/workflows/e2e-image.yml:1-34`, `ci.yml:480` | |
| `ghcr-visibility` | ja | `ci.yml:468-470`, `features/05-e2e-test-image.md:165-167` | keine — beide Packages public (geprüft 2026-10-04: anonymer Pull-Token für beide erteilt; `gh api` meldet `visibility=public` für `accriditation-e2e` und `accriditation-base`) |
| `github-ci-filters` | offen | `ci.yml:11-14`, `.github/workflows/base-image.yml:30`, `.github/workflows/e2e-image.yml:28` | Position 51 in `AGENTS.todo.md` |
| `model-updater` | nein | | keine Modell-Konfiguration im Projekt (Maschinen-Sache wie `agent-config`) |
| `node-deps` | offen | `frontend/package.json:6`, `frontend/pnpm-workspace.yaml:8-14` | Position 50 in `AGENTS.todo.md` |
| `permissions` | nein | | opencode.jsonc-Policy (Maschine); das Projekt definiert keine |
| `playwright-parallel` | offen | `ci.yml:728-732`, `:759/:761/:763` | Positionen 48, 49 in `AGENTS.todo.md` |
| `skills-marker` | ja | `AGENTS.skills.md` | (diese Datei = Erstmarker) |
| `tailscale-serve` | nein | | kein Tailscale-Bezug im Projekt (repo-weiter grep ohne Treffer) |
| `ui-review` | ja | `AGENTS.md:365-437`, `:428-429` | |
| `update-opencode-models` | nein | | keine Modell-Registry im Projekt (wie `model-updater`) |
| `vision-agents` | ja | `AGENTS.md:422-425` | |

## Offen aus dem Prüflauf 2026-10-04 (Erstmarker, keine Range)

Kein Vor-Stand: Für einen Erstmarker gibt es keine `<alt>..HEAD`-Range. Stattdessen
stehen hier die offenen Befunde dieses Prüflaufs — jede Zeile verweist auf
`AGENTS.todo.md`, wo sie als Position (Log, kein Auftrag) liegt:

- `playwright-parallel` → Positionen 48 (Serial-Pin vs. Named Locks, inkl.
  Widerspruch und Burst-Nachmessung), 49 (Test-Budget/Akteur-Schlüssel)
- `node-deps` → Position 50 (`packageManager`-Pin, `overrides`)
- `github-ci-filters` → Position 51 (Docs-only läuft voll, `paths:`-Inklusion)

Dieser Abschnitt wird beim ersten Pull im Skills-Repo durch den
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
