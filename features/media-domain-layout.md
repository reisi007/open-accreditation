# Media Domain Layout (W1/W6) — Verzeichnis-Layout, Fallback, Backfill

**Status:** Implementiert (W1, W6, W7, W11; 2026-09-19). Dauerhafter SOLL-Zustand der
öffentlichen Media-Ablage und ihrer Auslieferung. Single Source of Truth für den
Pfad-Vertrag ist `backend/app/Services/MediaPathService.php`; die Caddy-Seite ist
in `deployment/caddy-media-overrides.Caddyfile` (Root-Brand-Dateien) und
`deployment/caddy-media-api-accel.Caddyfile` (DB-Media über
`X-Accel-Redirect`) festgehalten (Entwurf, Go-Live offen). Brand-Datei-Satz und
Caddy-Override-Kontext: `features/03-caddy-brand-files.md`;
Self-Service-Oberflächen: `features/04-media-self-service.md`.

W11-Wechsel in Kurzform: DB-gestützte Media-Dateien (Brand-Logo/Header,
Team-, Event-Typ- und Badge-Dateien) werden **nicht mehr direkt** von Caddy aus
`MEDIA_ROOT` ausgeliefert, sondern über die bestehenden auth-/portal-gebundenen
API-Show-Routen. Das Backend antwortet mit `X-Accel-Redirect`, Caddy liefert die
Datei intern aus (`(media_api_accel)`). Der frühere Direkt-Serve der Bäume
`/teams/*`, `/event-types/*`, `/badges/*` ist entfallen; `(media_overrides)`
deckt nur noch die deployment-bereitgestellten Root-Brand-Dateien ab.

Grundsatz: **Öffentlich wird nur, was explizit freigegeben ist.** Personenbilder
(Porträt, Presse-ID, Anhänge) verlassen die `private`-Disk nie.

## Zielbild

Alle öffentlich direkt von Caddy auslieferbaren Bilder liegen auf der Disk
`media`. Ihr Root ist `MEDIA_ROOT` (Produktion: `/srv/media/accreditation`,
`env MEDIA_ROOT`, siehe `config/filesystems.php` + `.env.example`). Der
Dateibaum ist nach Mandant (Domain) und Unter-Entität (Team, Event-Typ, Badges)
gegliedert; ein globaler Root-Fallback deckt Werte ab, die für alle Mandanten
gelten.

Privat bleiben auf der `private`-Disk: `user-media/*` (Personenbilder,
Presse-ID, Anhänge), QR-Verifikationsdaten und nicht freigegebene Originale.
Diese Dateien werden ausschließlich über auth-gated API-Routen gestreamt.

## Verzeichnis-Layout

Alle Pfade sind **relativ zum Media-Root**, verwenden `/` als einziges
Trennzeichen, beginnen nie mit `/` und enthalten nie ein `..`-Segment.

| Pfad (relativ zu `MEDIA_ROOT`) | Builder | Inhalt / Zweck |
|---|---|---|
| `<file>` | `rootFile()` | Globaler Root-Fallback (Brand-Dateien, die für jeden Mandanten gelten) |
| `<domain>/<file>` | `domainFile()` | Mandant-Override (Logo/Header/Favicons/Manifest) |
| `<domain>/teams/<slug>/<file>` | `teamFile()` | Vereins-Logo, Versus-Teilnehmer-Bild |
| `<domain>/event-types/<slug>/<file>` | `eventTypeFile()` | Event-Typ-Logo (`cl`, `bundesliga`, `cup`, …) |
| `<domain>/badges/<file>` | `badgeFile()` | Badge-Upload-Spiegel (öffentlich, `<ulid>.<ext>`) |
| `_tenants/<id>/<file>` | `hostNeutralFile()` | Host-neutraler Mandant-Fallback (keine Domain konfiguriert) |
| `_tenants/<id>/teams/<slug>/<file>` | `hostNeutralTeamFile()` | Vereins-Logo ohne Mandant-Domain |
| `_tenants/<id>/event-types/<slug>/<file>` | `hostNeutralEventTypeFile()` | Event-Typ-Logo ohne Mandant-Domain |
| `_tenants/<id>/badges/<file>` | `hostNeutralBadgeFile()` | Badge-Spiegel ohne Mandant-Domain |

- `<domain>` ist **nicht** der Roh-Request-Host, sondern seine normalisierte Form
  (siehe unten).
- `teams`/`event-types`/`badges` sind reservierte Segmentnamen direkt unterhalb
  eines Domain- bzw. `_tenants/<id>`-Ordners.
- `_tenants` ist der **reservierte host-neutrale Präfix**: Der führende
  Unterstrich ist kein gültiges Hostname-Zeichen (Hosts werden als
  `[a-z0-9-]`-Labels validiert), deshalb kann `_tenants/<id>/…` nie mit einem
  echten `<domain>/…`-Verzeichnis kollidieren. Die numerische Mandant-ID trennt
  gleichlautende Slugs verschiedener Mandanten.
- `sanitizeFileName()` akzeptiert nur `[a-z0-9._-]+` (lowercased, keine
  Separatoren, kein `..`, kein `\0`) — Traversal und Client-Extension werden nie
  durchgereicht. `sanitizeSlug()` erzwingt `[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?`.

## Host-Normalisierung + Case-Annahme

`dirForHost()` normalisiert den Request-Host deterministisch:

1. `trim` + `strtolower`.
2. Port-Suffix strippen (`example.com:8080` → `example.com`; IPv6-Literale
   `[::1]:8080` werden ausgepackt).
3. IDN → Punycode via `idn_to_ascii` (`münchen.de` → `xn--mnchen-3ya.de`).
   Ohne `ext-intl` werden nur bereits-ASCII-Hosts akzeptiert; non-ASCII wird
   abgelehnt (keine unsichere „Normalisierung" ohne ICU).
4. DNS-Plausibilität (`isValidHost`): alnum/Hyphen-Labels, keine führenden/
   abschließenden Hyphens, kein leerer Label (`..`), ≤ 253 Zeichen.

**Bewusste Case-Annahme (L1):** Das Backend normalisiert Hosts lowercase;
Caddy tut das **nicht** — `{http.request.host}` folgt dem Case des Requests.
Browser und HTTP/2 senden `:authority`/Host praktisch immer lowercase und
Punycode, daher matcht der Domain-Override im Regelfall. Bei abweichendem Case
findet Caddy den Domain-Override nicht und fällt auf den globalen Root-Fallback
zurück: Es wird **maximal globales Brand** ausgeliefert, **nie fremdes
Mandanten-Media** — das `<domain>`-Verzeichnis matcht schlicht nicht
(kein Cross-Tenant-Leak).

## Resolver-Konvention (erste Domain)

`MediaHostResolver::hostFor()` liefert die **erste konfigurierte** Mandant-Domain
(`domains()->orderBy('id')->value('hostname')`) — die stabile, dokumentierte
„Primary Domain"-Konvention. Eine **später hinzugefügte Alias-Domain darf nie
still verändern, wo Medien eines Mandanten bereits liegen.** Alle
Media-Services (Brand, Badge, Team, Event-Typ) nutzen diesen Helper, damit die
Host-Annahme genau einmal definiert ist. Query-only, kein Disk-Zugriff.

Ohne konfigurierte Domain liefert der Resolver `null`; Aufrufer fallen auf das
host-neutrale `_tenants/<id>/…`-Layout zurück.

## Fallback-Kette

Es gibt zwei Auslieferungsebenen mit **unterschiedlicher** Fallback-Semantik:

### API-Delivery (auth-gated oder portal-öffentlich)

1. Pfad aus der DB (`mandants.logo_path`/`header_path`, `teams.logo_path`,
   `event_types.logo_path`, `badge_images.path`) über den jeweiligen Service.
2. `MediaStorage::exists()` prüft zuerst `media`, dann transparent die Legacy-Disk
   `private` → un-migrierte Dateien bleiben lesbar.
3. Keine Datei → **404** `{message: 'Kein Bild hinterlegt.'}`.

Die DB ist die Autorität für diese Ebene; sie kennt auch host-neutrale und noch
nicht migrierte Pfade.

### Caddy Direct-Serve (`(media_overrides)`)

Pro Request über die Media-Matcher gilt strikt:

```
try_files /{http.request.host}{path} {path} =404
```

1. Domain-Override `<MEDIA_ROOT>/<domain>/<pfad>`
2. Root-Fallback `<MEDIA_ROOT>/<pfad>` (global)
3. sonst **404**

**Kein SPA-Fallback für Media:** Brand-/Media-Pfade werden **nicht** über
`index.html`/das React-Dist nachgeliefert. Eine fehlende Datei ist ein echtes
404 — die Media-Pfade sind aus dem SPA-Fallback herausgenommen.

## Alias-Domains

Der Resolver keyt Medien auf die **erste** Mandant-Domain. Ein Request über eine
Alias-Domain sucht `<alias>/…`, findet nichts und fällt auf den Root-Fallback
zurück (oder über die API-Delivery auf den DB-Pfad, der auf die Primär-Domain
zeigt). Das ist **kein Leak**, aber für Multi-Domain-Mandanten vor Go-Live zu
klären: entweder Alias-Verzeichnisse befüllen/verlinken oder ein Host-Mapping in
Caddy hinterlegen. Bleibt als Go-Live-Punkt offen (siehe
`deployment/caddy-media-overrides.Caddyfile` „GO-LIVE-OFFEN").

## Backfill-Command

`php artisan media:migrate-to-domain-layout` (W6).

- **Dry-Run ist Default**: listet nur Kandidaten (`[dry-run] label: from -> to`).
- `--force` führt aus: Datei auf den neuen Pfad kopieren → DB-Pfad aktualisieren
  → Legacy-Quelle löschen.
- `--dry-run` gewinnt auch kombiniert mit `--force` (Safe-Mode hat Vorrang).
- **Idempotent**: Zeilen, deren Pfad nicht mehr im Legacy-Layout liegt, werden
  übersprungen; fehlende Quellen werden gemeldet und übersprungen. Re-Runs
  konvergieren und fassen bereits migrierte Medien nicht mehr an.
- **Scope (bewusst begrenzt, W6-F3):** Der Command migriert ausschließlich die
  Legacy-Pfade der `private`-Disk — Mandant-Brand `mandants/{slug}/…` und
  Badge-Bilder `badge-images/{slug}/…` — in das Domain-/`_tenants`-Layout auf
  `media`. Host-neutrale Dateien (`_tenants/…`) werden **nicht** umgezogen: Sie
  liegen bereits auf `media` und bleiben über `MediaStorage` lesbar. Team- und
  Event-Typ-Logos wurden erst nach W1/W6 eingeführt und daher nie im alten
  `private`-Layout geschrieben — für sie gibt es nichts zu migrieren.
  Slug-Wechsel innerhalb des neuen Layouts behandeln die Services separat
  (`moveForSlugChange`).

## Caddy-Snippet-Verweis + Cache-Semantik

Referenzen: `deployment/caddy-media-overrides.Caddyfile` (Root-Brand-Dateien)
und `deployment/caddy-media-api-accel.Caddyfile` (Accel-Delivery). Beide
Fragmente werden **nicht** automatisch geladen und gehören in die zentrale
Proxy-Caddyfile (`~/dev/caddyfile/Caddyfile`), **vor** dem SPA-Fallback-`handle`
der Mandanten-Site-Blöcke (`handle`-Blöcke sind exklusiv, der erste Treffer
gewinnt). Der Caddy-Container braucht denselben read-only `MEDIA_ROOT`-Mount.

### (media_overrides) — Root-Brand-Dateien (kein DB-Bezug)

Matcher (`@media_paths`): der Brand-Datei-Satz aus `features/03`
(`/logo.svg`, Favicons, `site.webmanifest`, `browserconfig.xml`, …) — **ohne**
`/teams/*`, `/event-types/*`, `/badges/*` (W11).

| Sektion | Cache-Control | Begründung |
|---|---|---|
| Brand-Bilder (nur 2xx) | `public, max-age=3600, must-revalidate` + ETag | **Feste Namen** (`logo.svg`, `favicon.ico`): ein Replace schreibt dieselbe URL neu. ETag-Revalidierung nach Ablauf greift spätestens nach 1 h statt erst nach 1 Jahr |
| Manifest + übrige Nicht-Bilder | `no-cache, no-store, must-revalidate` | `site.webmanifest` u. a. müssen nach Brand-Wechsel sofort greifen |

### (media_api_accel) — DB-gestützte Media über `X-Accel-Redirect` (W11)

Das Backend antwortet auf den Show-Routen (Portal-Logo/Header, Admin-Logo/
Header, Team-/Event-Typ-/Badge-Datei) mit einem **leeren 200** und
`X-Accel-Redirect: <prefix>/<media-relativpfad>`. Caddy fängt den Header in
`handle_response @accel_header` ab und liefert die Datei direkt aus `MEDIA_ROOT`.
`copy_response_headers { include Cache-Control Content-Type Content-Disposition
Vary ETag }` überträgt die Backend-Semantik auf die interne Dateiantwort.

| Klasse | Cache-Control (Backend → Caddy-copy) | Begründung |
|---|---|---|
| `/badges/*` | `public, max-age=31536000, immutable` | Server-generierte **ULID**, Dateiname wird nie überschrieben → content-addressed |
| Feste Namen (`logo.<ext>`, `header.<ext>`, Team-/Event-Typ-Logos) | `public, max-age=3600, must-revalidate` | Ein Replace schreibt dieselbe URL neu; ETag-Revalidierung nach 1 h |

- **Autoritätswechsel:** Die DB/der Service bleibt die Autorität (Mandanten-
  Isolation, 404-Semantik, Pfad-Sanitisierung). Der Accel-Zweig ist kein
  zweiter Wahrheitspfad: Er wird ausschließlich aus einer gültigen API-Antwort
  ausgelöst und nur für Pfade, die das Backend explizit freigibt.
- **Negativ-Guards:** `_tenants/…` (host-neutral, von Caddy bewusst nicht
  erreichbar) und Legacy-`private`-Pfade erhalten **nie** einen Accel-Header —
  sie werden weiter durch PHP gestreamt bzw. 404. Personenbilder
  (`user-media/*`) bleiben vollständig privat (eigene Disk, Streaming; nie
  Accel).
- **Dual-Modus (Risiko R1):** `MEDIA_ACCEL_PREFIX` (config `media.accel_prefix`)
  ist **default AUS** (leer) → das Backend streamt wie bisher. Ist das Flag
  gesetzt, aber der Snippet fehlt, entstünde ein bodyless 200; deshalb gilt die
  Reihenfolge: **erst Snippet ausrollen (inaktiv), dann das Flag setzen.**
- **Spoof-Strip:** `header_up -X-Accel-Redirect` entfernt einen vom Client
  geschmuggelten Request-Header vor dem Upstream. `@accel_get method GET`
  begrenzt die interne Auslieferung auf GET. `file_server { hide .* }` blendet
  Dotfiles aus.
- **Validierung vor Sync:** `caddy validate` in Docker; Laufzeitmatrix C1–C7
  grün (2026-09-19, caddy:2 + php:8.5-fpm): Accel-Datei wird ausgeliefert,
  Backend-`Cache-Control`/`Content-Type`/`Content-Disposition`/`Vary`/`ETag`
  überleben den Accel-Block, `X-Accel-Redirect` erreicht den Client nie,
  Client-Spoof wird gestrippt, Nicht-GET liefert die Datei nicht aus, Dotfile-
  Pfade sind 404. Go-Live-Freigabe, Sync und Reload liegen außerhalb dieses Repos.

## WebP-Derivate (W11)

Original-Uploads bleiben autoritativ (Pfad/Endung in der DB). Zusätzlich schreibt
jeder Brand-/Badge-Service synchron (GD, **keine Queue**) ein `.webp`-Geschwister
per Extension-Swap (`<base>.webp`) auf die `media`-Disk:

- `WebpConverter` — Presets `photo` (q82, Header/Hero) und `logo` (q90, Logos/
  Embleme); PNG-Alpha via `imagepalettetotruecolor` + `imagealphablending(false)`
  + `imagesavealpha`; JPEG-Exif-Orientierung wird vor dem Encoding angewandt;
  animierte WebP werden abgelehnt (kein stiller Frame-Verlust). SVG wird nie
  konvertiert (und erreicht den Service nicht).
- Auslieferung: `MediaStorage::accelResponse` bevorzugt das `.webp`-Geschwister,
  wenn der Client `Accept: image/webp` sendet; sonst das Original. Kein `Vary`
  auf den kanonischen URLs.
- **Backfill:** `php artisan media:convert-to-webp` (dry-run default,
  `--force`, `--prune-originals`) erzeugt Geschwister für Bestandsdaten,
  idempotent; `--prune-originals` löscht das Raster-Original und stellt
  Pfad/Mime der DB-Zeile auf `.webp` um (der Badge-Renderer/DomPDF verarbeitet
  WebP).
- **Alpha/Exif/Format** sind durch `WebpConversionTest` abgedeckt; die
  Badge-PDF-Pipeline mit WebP durch `BadgeRenderServiceTest`.

## Selbstreinigender Derivat-Cache (W11)

Abgeleitete `.webp`-Dateien dürfen keine Waisen werden:

- **Synchron:** Jeder Delete-Pfad räumt das Geschwister mit — `destroy()`/
  `purge()` der Brand-Services, `BadgeImageService::destroy`, der Mandant-Delete
  (Logo/Header) und der Slug-Move (verschiebt das Geschwister mit). Zusätzlich
  löscht ein Replace alle Alt-Endungen desselben Blatts
  (`deleteAlternateExtensions`), sodass `logo.png` → `logo.jpg` keine
  `logo.jpeg`/`logo.webp`-Reste hinterlässt.
- **Reconciliation:** `php artisan media:prune-orphans` (dry-run default,
  `--force`) listet/löscht `media`-Dateien, deren Pfad durch **keine** DB-Zeile
  referenziert wird (alle `logo_path`/`path`-Spalten plus deren `.webp`-
  Geschwister). Host-neutrale `_tenants/<id>/…`-Pfade sind normale Referenzen
  und werden erst nach dem Löschen des Mandanten zu Waisen. Nur das verwaltete
  Media-Layout wird betrachtet; deployment-bereitgestellte Root-Brand-Dateien
  (`logo.svg`, Favicons, …) werden nie angefasst. Empfohlener Rhythmus:
  **wöchentlich** über den Scheduler
  (`Schedule::command('media:prune-orphans --force')->weekly();`) — die
  Scheduler-Infrastruktur selbst wird hier nicht aufgebaut.

## Badge-Public-Posture

Badge-Bilder sind **Template-Grafiken/Embleme**, keine Personenfotos. Sie werden
unter einer server-generierten ULID gespeichert und sind bewusst öffentlich und
immutable auslieferbar (Caddy-Direct-Serve unter `/badges/*`). Die
Content-Addressed-Namensgebung ist die Voraussetzung für `immutable`; der
auth-gated Admin-Pfad (`/api/admin/badge-images/{id}/file`) bleibt für Verwaltung
und Löschung bestehen. Template-`layout`-Verweise auf gelöschte Bilder werden
absichtlich nicht umgeschrieben — der Renderer fällt auf eine leere Box zurück
(`features/badge-template-editor.md`).

## Personenbilder bleiben privat

`user-media/*` (Porträt, Presse-ID, Anhänge) liegt weiterhin ausschließlich auf
der `private`-Disk und wird nur über auth-gated Routen gestreamt (Owner oder
Admin mit Mandanten-/Team-Scope). Diese Pfade tauchen **nicht** im
`media`-Layout und **nicht** im Caddy-`@media_paths`-Matcher auf und sind damit
über Caddy grundsätzlich nicht erreichbar.

## Betroffene Klassen

| Klasse | Verantwortung |
|---|---|
| `App\Services\MediaPathService` | Reiner Pfad-Vertrag: Host-Normalisierung, Pfad-Builder, Traversal-Abwehr (kein Disk-/DB-Zugriff) |
| `App\Services\MediaHostResolver` | Erste Mandant-Domain als Primär-Host, `null` ohne Domain |
| `App\Services\MediaStorage` | Disk-Adapter: Schreiben auf `media`, Lesen `media` → `private`, idempotentes Löschen beider Disks |
| `App\Services\ImageUploadRules` | Upload-Kontrakt: MIME-Whitelist → Endung, max. 2000×2000 px |
| `App\Services\{Mandant,Team,EventType}MediaService` | Upload/Replace/Delete je Entität im Domain-/host-neutralen Layout |
| `App\Services\BadgeImageService` | Badge-Upload unter ULID + Addressing-Zeile |
| `App\Services\WebpConverter` | Synchrones GD-WebP-Geschwister (Presets, Alpha, Exif, animiert-Ablehnung) |
| `App\Console\Commands\MediaMigrateToDomainLayoutCommand` | Dry-Run-Backfill der Legacy-`private`-Pfade |
| `App\Console\Commands\MediaConvertToWebpCommand` | WebP-Backfill (`--prune-originals`) |
| `App\Console\Commands\MediaPruneOrphansCommand` | Waise-Reconciliation (`media:prune-orphans`) |
| `deployment/caddy-media-overrides.Caddyfile` | Root-Brand-Dateien + Cache-Semantik (Entwurf) |
| `deployment/caddy-media-api-accel.Caddyfile` | API-Accel-Delivery der DB-Media (Entwurf) |

## Invarianten (nicht regredieren)

- **Mandanten-Isolation:** `<domain>/…` matcht nur den eigenen Host; kein
  Cross-Tenant-Leak bei Case-/Alias-Abweichung (dann greift globaler Root oder
  404).
- **Kein SPA-Fallback für Media:** fehlende Brand-/Root-Dateien sind 404, nie
  `index.html`.
- **Personenbilder privat:** `user-media/*` verlässt die `private`-Disk nicht
  und erhält nie einen Accel-Header.
- **Primär-Domain stabil:** neue Alias-Domains verschieben keine Medien.
- **`_tenants` reserviert:** führender Unterstrich ist kein gültiger Hostname;
  `_tenants/…` wird nie per Accel ausgeliefert.
- **Cache:** nur Badges (ULID, content-addressed) `immutable`; feste Namen
  `must-revalidate`; Fehlversuche (404) nie immutable.
- **Accel default AUS (R1):** `MEDIA_ACCEL_PREFIX` leer → Streaming; niemals
  Flag und Snippet gegenläufig ausrollen.
- **Derivat-Cache selbstreinigend:** kein `.webp` überlebt Original/Entität;
  `media:prune-orphans` hält die Disk konvergent.
- **Portabilität (§2):** reine PHP-/Query-Builder-Logik, keine PG-Funktionen.

## Tests

- `backend/tests/Feature/MediaPathServiceTest.php` — Pfad-Matrix (Root/Domain/
  Subordner), Host-Edge-Cases (Case, Port, IDN, Traversal), `_tenants`-Fallback.
- `backend/tests/Feature/MediaMigrationTest.php` — Backfill dry-run/`--force`/
  Idempotenz.
- `backend/tests/Feature/MediaWriteFailureTest.php` — Write-Failure bricht vor
  DB-Update/Delete ab.
- `backend/tests/Feature/AdminTeamParticipationTest.php` +
  `AdminEventTypeTest.php` — Team-/Event-Typ-Logo Upload/Replace/Delete,
  Cross-Domain-Isolation.
- `backend/tests/Feature/MediaAccelRedirectTest.php` — B1–B10: Dual-Modus,
  Header-Wert, Cache-Klassen, WebP-Variantenwahl, `_tenants`/Legacy/Traversal
  nie Header, 404-Semantik, Portal-Isolation.
- `backend/tests/Feature/WebpConversionTest.php` — Alpha, Exif-Rotation,
  Formate, animiert-Ablehnung, Geschwister je Service, kein Alt-Leak,
  Slug-Move, MIME→Endung bei `UserMedia`.
- `backend/tests/Feature/MediaDerivativeCleanupTest.php` — Delete-Kaskade je
  Service-Typ inkl. Mandant-Delete.
- `backend/tests/Feature/MediaConvertToWebpTest.php` — Backfill dry-run/force/
  prune/svg/idempotent.
- `backend/tests/Feature/MediaPruneOrphansTest.php` — dry-run listet Waise,
  referenzierte/brand-root nicht; `--force` löscht nur Waise; idempotent.
- `BadgeRenderServiceTest` — Badge-PDF mit WebP-Upload-Bild (DomPDF).
- `caddy validate` + Laufzeitmatrix C1–C7 (caddy:2 + php:8.5-fpm).

## Offene Punkte

- **Go-Live Caddy:** `MEDIA_ROOT` auf dem Host anlegen/befüllen (Root-Fallback =
  Brand-Dateien), read-only Mount, `(media_overrides)` + `(media_api_accel)` in
  alle Mandanten-Site-Blöcke, danach `MEDIA_ACCEL_PREFIX` setzen; Sync/Reload
  erst nach Freigabe.
- **Scheduler-Cron:** `media:prune-orphans --force` wöchentlich einplanen
  (Infrastruktur außerhalb dieses Repos).
- **Alias-Domains:** Multi-Domain-Mandanten vor Go-Live klären (Alias-Verzeichnisse
  oder Host-Mapping).
- **Case-Annahme (L1):** beobachten; bei Bedarf Host-Normalisierung in Caddy
  erzwingen.
