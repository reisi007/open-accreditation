# 03 — Brand/Logo Files & Caddy Per-Mandant Override (SOLL)

## SOLL

- **Statische Brand-/Logo-Dateien leben im React-Projekt** unter
  `frontend/public/` (Wurzel, **keine** `brands/<id>/`-Unterordner-Struktur) und
  sind die **Fallback-Quelle**. Vite kopiert `frontend/public/` unverändert nach
  `dist/`; lokale Entwicklung = Vite serviert diese Dateien direkt
  (`http://localhost:5173/logo.svg`, `/favicon-32x32.png`, `/site.webmanifest`,
  …).
- **Dateisatz (RealFaviconGenerator-Muster, root-relative Pfade):**

  | Datei | Zweck |
  |---|---|
  | `logo.svg` | Master-Logo (volle Farbe, Vektor), Startseiten-Logo |
  | `logo-mono.svg` | Einfarbige Variante (Brand-Primary) für `mask-icon` |
  | `favicon.svg` | Vektor-Favicon |
  | `favicon-16x16.png`, `favicon-32x32.png`, `favicon.ico` | Klassische Favicons |
  | `apple-touch-icon.png` | iOS Home-Screen (opak, 180×180) |
  | `android-chrome-192x192.png`, `android-chrome-512x512.png` | PWA-Icons (Manifest) |
  | `site.webmanifest` | PWA-Manifest (theme_color = Brand-Primary `#863bff`) |
  | `logo-email-64.png`, `logo-email-128.png` | Reserviert für E-Mail (späterer Workflow) |
  | `browserconfig.xml`, `mstile-150x150.png` | Windows-Tile (opak) |

- **Homepage-Logik (P8):** `getHomepageLogo(mandant, logoFailed)`
  (`frontend/src/logic/homepageLogo.ts`): solange der Mandant ein
  hochgeladenes Logo aus der API (`logo_url`) hat und es lädt, wird es
  angezeigt; andernfalls fällt die Startseite auf das statische React-Logo
  `/logo.svg` zurück (immer sichtbar). Header-Bild verhält sich unverändert
  (nur wenn hochgeladen).

## Caddy (Produktion, geplant)

- **Caddy serviert pro Mandant Datei-Basis-Overrides auf Host-Ebene** — nicht
  im React-Build, sondern als Datei-Fallback auf dem Server:
  - Host-basierte Overrides für z. B. `/favicon-32x32.png`,
    `/android-chrome-192x192.png`, `/logo.svg`, `/site.webmanifest`,
    `/apple-touch-icon.png`.
  - Fehlt eine mandantenspezifische Datei → **Fallback auf die Dateien aus dem
    React-Projekt** (`dist/` / `frontend/public/`).
  - Root-relative Pfade in `index.html`/`site.webmanifest` (ohne `brands/`-
    Präfix) sind die Voraussetzung dafür, dass Caddy die Dateien auf Datei-Basis
    überschreiben kann.
- Der Mandanten-`slug` ist über `GET /api/portal/overview` (`MandantPublicResource`)
  öffentlich verfügbar — als Keying-Basis für die Caddy-Overrides gedacht.
- **Ist-Stand:** Keine Caddy-Konfiguration im Repo. Lokale Entwicklung nutzt den
  Vite-Dev-Server (serviert `frontend/public/`). Caddy kommt mit P7
  (Reverse-Proxy, multi-Domain) — siehe `AGENTS.todo.md`.
