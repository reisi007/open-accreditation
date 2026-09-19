# 03 — Brand/Logo Files & Caddy Per-Mandant Override (SOLL)

## SOLL

- **Statische Brand-/Logo-Dateien werden von Caddy direkt aus `MEDIA_ROOT` der
  `media`-Disk ausgeliefert** (W1/W6/W7). `frontend/public/` bleibt die
  **Fallback-Quelle im Repo** (Vite kopiert es nach `dist/`; Lokalentwicklung
  serviert Vite die Dateien) und ist der Initialinhalt, aus dem der globale
  Root-Fallback in `MEDIA_ROOT` befüllt wird. Der Pfad-Vertrag und die
  Fallback-Kette sind in `features/media-domain-layout.md` festgehalten; dieses
  Dokument beschreibt den Datei-Satz und den Caddy-Override-Kontext.
- **Konsequenz für Media-Pfade:** `/logo.svg` & Co. laufen in Produktion **nicht**
  mehr über den SPA-Fallback, sondern über das `(media_overrides)`-Snippet:
  Domain-Override → Root-Fallback → **404** (kein Durchgriff auf `index.html`).
  Die frühere „SPA-`try_files` liefert die React-Fallback-Datei"-Kette gilt **nicht
  mehr** für diese Pfade (siehe unten, Abschnitt Cache/Overrides).
- **Zwei Auslieferungswege für das Mandant-Logo:** (a) Caddy-Direct-Serve der
  Datei im Domain-Layout (`<domain>/logo.<ext>`, schneller Pfad) und (b) die
  auth-freie API-Delivery `/api/portal/mandant/logo|header`
  (`PortalMediaController`), die den DB-Pfad streamt und damit auch
  host-neutrale `_tenants/<id>/…`-Dateien erreicht. Die DB bleibt die Autorität;
  der Direct-Serve ist ein Override/Fallback, keine zweite Wahrheit.
- **Dateisatz (RealFaviconGenerator-Muster, root-relative Pfade):**

  | Datei | Zweck |
  |---|---|
  | `logo.svg` | Master-Logo (volle Farbe, Vektor), Startseiten-Logo |
  | `logo-mono.svg` | Einfarbige Variante (Brand-Primary) für `mask-icon` |
  | `favicon.svg` | Vektor-Favicon |
  | `safari-pinned-tab.svg` | Monochromes Safari-Pinned-Tab-Icon |
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
  `(media_overrides)`-Matcher (`@media_paths`) enthalten. Soll ein Mandant
  sie überschreiben können, müssen `/logo-email-64.png` und
  `/logo-email-128.png` dort ergänzt werden (Bilder erhalten dann die
  `must-revalidate`-Semantik der festen Namen — **nicht** `immutable`, da der
  Dateiname bei einem Replace gleich bleibt).

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

Pro Mandant ein Site-Block mit `import spa` + `/api*`-Proxy zum Backend. Das
Media-Snippet wird **vor** dem SPA-Fallback-`handle` importiert, weil
`handle`-Blöcke in Reihenfolge exklusiv sind (erster Treffer gewinnt):

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

	# Brand-/Media-Overrides: Domain → Root → 404 (KEIN SPA-Fallback),
	# MUSS vor dem SPA-handle stehen.
	import media_overrides /srv/media/accreditation

	handle {
		import spa /srv/websites/accreditation.mandant-a
	}
}
```

### Brand-File-Overrides pro Mandant (SOLL) — `(media_overrides)`

Muster analog zum Portal-Block (`portal.reisinger.pictures`), aber **dynamisch
über den Host-Placeholder** statt eines hart verdrahteten `/brands/<id>`-Pfads
und **ohne** `brands/<id>/`-Umweg. Referenz (Entwurf, Go-Live offen):
`deployment/caddy-media-overrides.Caddyfile`; Layout/Fallback:
`features/media-domain-layout.md`.

Das Snippet matcht den Brand-Datei-Satz dieses Dokuments plus die Media-Bäume
`/teams/*`, `/event-types/*`, `/badges/*` und liefert aus `MEDIA_ROOT`:

```caddyfile
(media_overrides) {
	@media_paths {
		path /logo.svg
		path /logo-mono.svg
		path /favicon.svg
		# … weiterer Brand-Datei-Satz aus der Tabelle oben …
		path /site.webmanifest
		path /teams/*  /event-types/*  /badges/*
	}
	handle @media_paths {
		root * {args[0]}
		# Nur Badges (ULID, content-addressed) dürfen immutable.
		header @media_immutable Cache-Control "public, max-age=31536000, immutable"
		# Feste Namen (logo.<ext>): kurz cachen + ETag-Revalidierung.
		header @media_images Cache-Control "public, max-age=3600, must-revalidate"
		header @media_nocache Cache-Control "no-cache, no-store, must-revalidate"
		try_files /{http.request.host}{path} {path} =404
		file_server { hide .* }
	}
}
```

**Cache-/Fallback-Semantik (ETag):**

| Sektion | Cache-Control | Begründung |
|---|---|---|
| `/badges/*` (nur 2xx) | `public, max-age=31536000, immutable` | server-generierte ULID, Name wird nie überschrieben („Dateiname = Inhalt") |
| Übrige Bilder (nur 2xx) | `public, max-age=3600, must-revalidate` + ETag | **feste Namen** (`logo.<ext>`, `header.<ext>`): ein Replace schreibt dieselbe URL neu; Caddy liefert einen ETag, der nach Ablauf revalidiert → Brand-Wechsel greift spätestens nach 1 h statt erst nach 1 Jahr |
| Manifest + übrige Nicht-Bilder | `no-cache, no-store, must-revalidate` | `site.webmanifest` muss nach Brand-Wechsel sofort greifen |

- **Fällt eine mandantenspezifische Datei weg → Root-Fallback → 404.** Es gibt
  **keinen** SPA-Fallback für Media-Pfade; die Datei wird **nicht** aus dem
  React-Dist nachgeliefert. `match status 2xx` verhindert, dass ein 404
  immutable/no-cache-Regeln übernimmt.
- **Host-neutrale `_tenants/<id>/…`-Dateien sind für Caddy bewusst nicht
  erreichbar** (der Unterstrich ist kein Hostname). Mandanten ohne Domain werden
  über die auth-freie API-Delivery `/api/portal/mandant/logo|header` bedient.
- **Host-Case-Annahme (L1):** Caddy übernimmt den Request-Case, das Backend
  normalisiert lowercase. Bei Abweichung greift der Root-Fallback (maximal
  globales Brand — nie fremdes Mandanten-Media). Details:
  `features/media-domain-layout.md`.
- **Alias-Domains (Go-Live-Punkt):** Der Resolver keyt Medien auf die **erste**
  Mandant-Domain; Requests über eine Alias-Domain finden `<alias>/…` nicht und
  fallen auf den Root-Fallback zurück. Für Multi-Domain-Mandanten vor Go-Live
  klären (Alias-Verzeichnisse befüllen oder Host-Mapping).
- **Sicherheit:** `file_server { hide .* }` blendet sämtliche Dotfiles aus; kein
  `browse` → keine Directory-Listings.
- Deployment-Mechanik wie in der Referenz: `sync.sh` (Config hochladen,
  `caddy reload`), Validierung vorab via `caddy validate` in Docker; der
  Caddy-Container braucht denselben read-only `MEDIA_ROOT`-Mount.

### Ist-Stand

- Keine Caddy-Änderungen im Repo; lokale Entwicklung nutzt den Vite-Dev-Server
  (serviert `frontend/public/`). Caddy kommt mit P7 (Reverse-Proxy, multi-Domain)
  — siehe `AGENTS.todo.md` (Go-Live wartet auf Benutzer-Freigabe).
