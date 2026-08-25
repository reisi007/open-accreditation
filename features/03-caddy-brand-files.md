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
  | `logo-email-64.png`, `logo-email-128.png` | Reserviert für E-Mail-Embeds (Workflow siehe unten, SOLL) |
  | `browserconfig.xml`, `mstile-150x150.png` | Windows-Tile (opak) |

- **Homepage-Logik (P8):** `getHomepageLogo(mandant, logoFailed)`
  (`frontend/src/logic/homepageLogo.ts`): solange der Mandant ein
  hochgeladenes Logo aus der API (`logo_url`) hat und es lädt, wird es
  angezeigt; andernfalls fällt die Startseite auf das statische React-Logo
  `/logo.svg` zurück (immer sichtbar). Header-Bild verhält sich unverändert
  (nur wenn hochgeladen).

## Logo-E-Mail-Varianten (`logo-email-64/128.png`) — Workflow (SOLL)

Die beiden PNG-Raster-Varianten sind **reserviert für E-Mail-Embeds** und
noch nicht implementiert — der Workflow folgt mit dem E-Mail-Ausbau
(Mailables P5). Festgezogen:

- **Zweck:** Mail-Clients rendern kein SVG; externe Bilder werden teils gar
  nicht nachgeladen. Die kleinen Raster-Varianten (**64 px / 128 px Breite**)
  sind die Embed-Formate für Mails (Freigabe-/Ablehnungs-/Aktivierungs-Mails).
- **Ablage (Fallback-Quelle):** `frontend/public/logo-email-64.png` bzw.
  `frontend/public/logo-email-128.png` — gleiche Fallback-Kette wie alle
  Brand-Files: Vite kopiert `public/` unverändert nach `dist/`, Caddy liefert
  die Root-Pfade pro Mandant aus (Overrides siehe unten).
- **Referenzierung:** Mails werden serverseitig erzeugt (Backend,
  MandantMailerService je Mandant-SMTP). Das Logo wird daher als **CID-Embed**
  (`$message->embed()`) aus einer Server-seitig lesbaren Quelle eingebunden —
  nicht als öffentliche URL. Quelle kann die Fallback-Datei oder das
  hochgeladene Mandant-Logo sein; die Fallback-Entscheidung (analog
  `getHomepageLogo`) ist bei Implementierung festzulegen (offen).
- **Caddy-Hinweis:** Die beiden Pfade sind aktuell **nicht** im
  `(brand_overrides)`-Matcher (`@brand_files`) enthalten. Soll ein Mandant
  sie überschreiben können, müssen `/logo-email-64.png` und
  `/logo-email-128.png` dort ergänzt werden (immutable Cache-Control wie die
  übrigen Brand-Files).

## Caddy (Produktion, geplant — Plan auf Basis `~/dev/caddyfile/Caddyfile`)

Referenz-Infrastruktur: die zentrale `Caddyfile` (`~/dev/caddyfile/`, siehe deren
`README.md`) verwaltet alle Subdomains über Snippets. Für `open-accreditation`
kommen die bestehenden Snippets zur Anwendung; das Caddyfile des Repos bleibt
bis zur Go-Live-Freigabe unverändert (dieser Abschnitt ist SOLL-Doku).

### Snippets (aus der Referenz-Caddyfile)

| Snippet | Zweck | Einsatz hier |
|---|---|---|
| `(security_headers)` | HSTS, X-Content-Type-Options, Referrer-/Permissions-Policy | alle Mandant-Sites + API |
| `(compress)` | `encode zstd gzip` | alle Mandant-Sites + API |
| `(spa)` | SPA: gehashte Assets immutable (1 Jahr), Rest no-cache + `try_files {path} /index.html` | Frontend-Auslieferung pro Mandant |
| `(proxy_site)` | einfacher Reverse-Proxy | `/api*` → Backend (Fallback-Variante) |

### Multi-Domain-Muster (SOLL)

Pro Mandant eine Site-Block mit `import spa` + `/api*`-Proxy zum Backend:

```caddyfile
# Mandant A (Verband): eigene Domain
verband-a.example {
	import security_headers
	import compress

	handle /api* {
		reverse_proxy accreditation_backend:9000 {
			transport fastcgi {
				env SCRIPT_FILENAME /var/www/html/public/index.php
				resolve_root_symlink
			}
		}
	}

	# Brand-Files: mandantenspezifische Overrides vor dem SPA-Fallback
	import brand_overrides /srv/websites/accreditation.mandant-a

	handle {
		import spa /srv/websites/accreditation.mandant-a
	}
}
```

### Brand-File-Overrides pro Mandant (SOLL)

Muster analog zum Portal-Block (`portal.reisinger.pictures`, Zeilen 234–256 der
Referenz-Caddyfile), aber **ohne** `brands/<id>/`-Pfad-Umweg: die Dateien liegen
direkt im Mandanten-Dist-Ordner, Root-Pfade in `index.html`/`site.webmanifest`
bleiben unverändert. Der Fallback auf die React-Fallback-Dateien
(`frontend/public/` → `dist/`) erfolgt über die SPA-`try_files`-Kette, falls
ein Mandant eine Datei nicht überschreibt:

```caddyfile
(brand_overrides) {
	@brand_files {
		path /logo.svg
		path /logo-mono.svg
		path /favicon.ico
		path /favicon-16x16.png
		path /favicon-32x32.png
		path /apple-touch-icon.png
		path /android-chrome-192x192.png
		path /android-chrome-512x512.png
	}
	handle @brand_files {
		root * {args[0]}
		header Cache-Control "public, max-age=31536000, immutable"
		file_server
	}

	@brand_manifest {
		path /site.webmanifest
	}
	handle @brand_manifest {
		root * {args[0]}
		header Cache-Control "no-cache, no-store, must-revalidate"
		file_server
	}
}
```

- Fehlt eine mandantenspezifische Datei → SPA-Fallback liefert die Datei aus dem
  React-Dist (gleicher Root-Pfad, `try_files {path} /index.html` greift nur für
  nicht-existente Dateien; hier existiert die Datei im Fallback-Dist).
- Der Mandanten-`slug` ist über `GET /api/portal/overview` (`MandantPublicResource`)
  öffentlich verfügbar — als Keying-Basis für die Verzeichnis-Struktur
  (`/srv/websites/accreditation.<slug>`).
- Deployment-Mechanik wie in der Referenz: `sync.sh` (Config hochladen,
  `caddy reload`), Validierung vorab via `caddy validate` in Docker.

### Ist-Stand

- Keine Caddy-Änderungen im Repo; lokale Entwicklung nutzt den Vite-Dev-Server
  (serviert `frontend/public/`). Caddy kommt mit P7 (Reverse-Proxy, multi-Domain)
  — siehe `AGENTS.todo.md` (Go-Live wartet auf Benutzer-Freigabe).
