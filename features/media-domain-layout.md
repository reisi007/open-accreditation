# Media Domain Layout (W1/W6) — Verzeichnis-Layout, Fallback, Backfill

**Status:** Implementiert (W1, W6, W7; 2026-09-19). Dauerhafter SOLL-Zustand der
öffentlichen Media-Ablage und ihrer Auslieferung. Single Source of Truth für den
Pfad-Vertrag ist `backend/app/Services/MediaPathService.php`; die Caddy-Seite ist
in `deployment/caddy-media-overrides.Caddyfile` festgehalten (Entwurf, Go-Live
offen). Brand-Datei-Satz und Caddy-Override-Kontext: `features/03-caddy-brand-files.md`;
Self-Service-Oberflächen: `features/04-media-self-service.md`.

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

Referenz: `deployment/caddy-media-overrides.Caddyfile`. Das Fragment
`(media_overrides)` wird **nicht** automatisch geladen und gehört in die zentrale
Proxy-Caddyfile (`~/dev/caddyfile/Caddyfile`), **vor** dem SPA-Fallback-`handle`
der Mandanten-Site-Blöcke (`handle`-Blöcke sind exklusiv, der erste Treffer
gewinnt). Argument `{args[0]}` ist `MEDIA_ROOT`; der Caddy-Container braucht denselben
read-only Bind-Mount.

Matcher (`@media_paths`): der Brand-Datei-Satz aus `features/03` plus
`/teams/*`, `/event-types/*`, `/badges/*`.

| Sektion | Cache-Control | Begründung |
|---|---|---|
| `/badges/*` (nur 2xx) | `public, max-age=31536000, immutable` | Badge-Dateien werden unter server-generierter **ULID** abgelegt und nie überschrieben → content-addressed, „Dateiname = Inhalt" |
| Übrige Bilder (nur 2xx) | `public, max-age=3600, must-revalidate` + ETag | **Feste Namen** (`logo.<ext>`, `header.<ext>`): ein Replace schreibt dieselbe URL neu. ETag-Revalidierung nach Ablauf greift spätestens nach 1 h statt erst nach 1 Jahr |
| Manifest + übrige Nicht-Bilder | `no-cache, no-store, must-revalidate` | `site.webmanifest` u. a. müssen nach Brand-Wechsel sofort greifen |

- `match status 2xx` stellt sicher, dass ein verfehltes `try_files` (= 404) nie
  immutable im Browser landet.
- `file_server { hide .* }` blendet sämtliche Dotfiles (`.env`, `.env.local`,
  `.htaccess`, `.DS_Store`, …) aus — Defense-in-Depth zusätzlich zu den
  Matchern. Kein `browse` → keine Directory-Listings.
- Validierung vor Sync: `caddy validate` in Docker; Laufzeit-Re-Check W7 grün
  (Domain-Override schlägt Root-Fallback, Badge immutable, Dotfiles/missing 404).
  Go-Live-Freigabe, Sync und Reload liegen außerhalb dieses Repos.

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
| `App\Console\Commands\MediaMigrateToDomainLayoutCommand` | Dry-Run-Backfill der Legacy-`private`-Pfade |
| `deployment/caddy-media-overrides.Caddyfile` | Öffentliche Auslieferung + Cache-Semantik (Entwurf) |

## Invarianten (nicht regredieren)

- **Mandanten-Isolation:** `<domain>/…` matcht nur den eigenen Host; kein
  Cross-Tenant-Leak bei Case-/Alias-Abweichung (dann greift globaler Root oder
  404).
- **Kein SPA-Fallback für Media:** fehlende Media-Dateien sind 404, nie
  `index.html`.
- **Personenbilder privat:** `user-media/*` verlässt die `private`-Disk nicht.
- **Primär-Domain stabil:** neue Alias-Domains verschieben keine Medien.
- **`_tenants` reserviert:** führender Unterstrich ist kein gültiger Hostname.
- **Cache:** nur Badges (ULID, content-addressed) `immutable`; feste Namen
  `must-revalidate`; Fehlversuche (404) nie immutable.
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
- `caddy validate` + W7-Laufzeit-Re-Check (Cache-/Fallback-/Dotfile-Verhalten).

## Offene Punkte

- **Go-Live Caddy:** `MEDIA_ROOT` auf dem Host anlegen/befüllen (Root-Fallback =
  Brand-Dateien), read-only Mount, Snippet-Import in alle Mandanten-Site-Blöcke,
  Sync/Reload erst nach Freigabe.
- **Alias-Domains:** Multi-Domain-Mandanten vor Go-Live klären (Alias-Verzeichnisse
  oder Host-Mapping).
- **Case-Annahme (L1):** beobachten; bei Bedarf Host-Normalisierung in Caddy
  erzwingen.
