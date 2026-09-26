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

## Schreib- und Lösch-Invariante (WP-4, 2026-09-26)

Beide Disks (`media`, `private`) laufen in `config/filesystems.php` mit
`'throw' => false`. **Ein Fehlschlag ist damit ein Rückgabewert, keine
Exception** — die Konsequenz gilt für *jeden* Schreib- und Löschpfad:

| Schritt | Kontrakt |
|---|---|
| **Write** (`put`/`putFileAs`) | `false` heißt „nicht geschrieben". **Vor** dem Löschen der Vorgängerdatei und **vor** dem Umschreiben eines DB-Pfads abbrechen (`RuntimeException` → 500). Es darf nie `UserMedia::create(['path' => (string) false])` o. Ä. entstehen — das persistiert einen Pfad `''`. Zusätzlich wird die **Nachbedingung geprüft** (die Datei liegt danach wirklich auf der Disk): `false` vom Adapter *oder* eine fehlende Datei gelten beide als Fehlschlag. Ein Treiber, der Erfolg meldet, ohne die Datei zu erzeugen, würde sonst genau den Verlust erzeugen, den Write-then-delete verhindern soll. `UserMediaService` prüft dieselbe Nachbedingung direkt auf der `private`-Disk. |
| **Delete** (`MediaStorage::delete()`) | Liefert `bool` und prüft seine **Nachbedingung** selbst: Erfolg = der Pfad ist danach auf **keiner** der beiden Disks mehr vorhanden. `false` nur, wenn die Datei **vorhanden war und danach noch da ist** (read-only `MEDIA_ROOT`, Rechte-Regression). Ein Pfad, der **vor** dem Versuch auf beiden Disks fehlt, ist „nichts zu löschen" und `true` — ein wiederholter Cleanup darf nie 500 werden. `deleteAlternateExtensions()` aggregiert dieselbe Aussage über alle Alt-Endungen. |
| **Delete mit Varianten** (`MediaStorage::deleteWithVariants()`) | Der Sammel-Primitive für `purge()`/`destroy()`: **Alt-/Geschwister-Varianten zuerst, die referenzierte Datei zuletzt**. Ein `false` aus der Varianten-Runde wird damit entdeckt, **während die Referenz noch auflöst** — das Bild wird weiter ausgeliefert und ein Retry versucht dieselbe Menge erneut. Die umgekehrte Reihenfolge (aktuelle Datei zuerst) ließ bei hängender Variante (deterministisch: ein *Verzeichnis* `logo.webp`, `unlink` scheitert immer) genau die einzige Datei verschwinden, die noch referenziert war. |
| **Referenz fällt weg?** | Eine DB-Referenz (`logo_path`/`header_path`/`badge_images.path`/`user_media`-Row) wird **ausschließlich bei `true`** entfernt. Sonst `RuntimeException` (500, `Log::error`) und die Referenz bleibt. Das gilt für jeden Einzelfall-`purge()`/`destroy()`. Für den **Entity-Delete** (`MandantController::destroy`) gilt die Umkehrung als Vertragsziel: dort wird die Referenz **mit der Row gelöscht**, also **vor** dem Dateiversuch — siehe „Reihenfolge der Kaskade". |
| **Reihenfolge der Kaskade (WF-3-D3)** | `MandantController::destroy` löscht **zuerst die `mandants`-Zeile** (eine atomare Statement, dessen `ON DELETE CASCADE` `event_types`, `badge_images`, `mandant_domains` … mitnimmt) und **erst danach** die Dateien: Brand (Logo/Header), dann Event-Typ- und Badge-Dateien. Grund: `purge()` kann jederzeit raisen, und **Datei-Löschung ist nicht transaktional** — eine `DB::transaction()` kann einen `unlink` nicht zurückrollen, nur die Row-Writes. Die alte Reihenfolge (Dateien zuerst) ließ beim gemeldeten Auslöser (Kind-Dateien löschbar, Brand-Datei hängt hinter einem read-only Bind-Mount) genau das verbotene Ergebnis zu: HTTP 500, **alle Rows intakt, Kind-Dateien schon weg** — der live geschaltete Mandant lieferte kaputte Bilder und `event_types.logo_path`/`badge_images.path` zeigten auf gelöschte Dateien. In der neuen Reihenfolge kann ein Purge-Fehler **nach** dem Row-Delete nur noch eine **unreferenzierte Waise** hinterlassen, die `media:prune-orphans` einsammelt. Jeder Purge läuft mit **begrenztem Retry** (`PURGE_ATTEMPTS = 3`, Backoff 50 ms + 100 ms — `delete()` einer bereits fehlenden Datei ist ein idempotentes `true`, ein Re-Run ist also sicher), ein endgültiger Fehlschlag bricht **nicht** die übrigen Purges ab (sonst blieben alle Dateien liegen) und wird am Ende als `RuntimeException` (500) geworfen, zusammen mit einem `Log::error` über die Labels der gescheiterten Purges. **Bleibt wahr:** der Mandant ist in dem 500-Fall **weg** — ein Retry antwortet 404, und die Waisen räumt der Reaper. Restunsicherheit, bewusst dokumentiert statt wegdefiniert: ein Prozessabbruch **zwischen** Row-Delete und Dateiphase hinterlässt ebenfalls nur Waisen, nie einen dangling Pfad. |
| **Referenz wandert nur?** | Aufräumen von Dateien, die ein **neuer, bereits geschriebener und referenzierter** Upload ersetzt (Vorgänger-Pfad, Alt-Endungen, Legacy-Varianten), ist **best effort**: Fehlschlag → `Log::warning`, Ablauf läuft weiter. Ein Abbruch würde die neue Datei ohne Referenz zurücklassen und das alte Bild weiter ausliefern. Es läuft **nach** dem Rewrite der Pfad-Spalte, ist also tatsächlich eine unreferenzierte Waise. **Ausnahme Slug-Move** (`moveForSlugChange()`): dort wird die Datei *vor* dem Spalten-Rewrite kopiert, weil die Spalte nie auf eine nicht existierende Datei zeigen darf; die Alt-Datei wird danach nur best effort entfernt. |
| **Wer räumt Waisen auf?** | `media:prune-orphans` enumeriert **ausschließlich das verwaltete Layout auf der `media`-Disk**. Eine Waise, die dort liegt, wird wöchentlich aufgeräumt. Eine **pre-W6-Legacy-Waise auf der `private`-Disk** (`mandants/{slug}/logo|header.<ext>`, `badge-images/{slug}/…`) sieht der Command **per Default nie** — `isManagedPath()` lehnt sie ab, und die Disk wird nicht einmal enumeriert. Services und `media:migrate-to-domain-layout` sagen das im Log explizit („delete it manually"); **eine automatische Selbstheilung wird dort nicht versprochen**. Festgenagelt in `MediaPruneOrphansTest::test_a_pre_w6_legacy_leftover_on_the_private_disk_is_never_reaped`. **WP-10-c:** bewusst opt-in gibt es `--include-legacy`, das zusätzlich die zwei Legacy-Layouts auf der `private`-Disk durchsucht (dry-run gilt auch dort, eigener Report, Exit-Code ≠ 0 bei Fehlschlag). Default **aus** ⇒ ein geplanter `media:prune-orphans --force` behält exakt seinen Scope; `user-media/**` ist auch hinter dem Flag **nie** im Scope (Personenbilder sind live privat). Siehe „Optionaler Reaper-Scope" unten. |
| **Root-Validierung (WP-10-b)** | `isManagedPath()` prüft das **erste** Pfadsegment, nicht nur das zweite: es muss ein kanonischer, normalisierbarer Mandant-Host sein (`MediaPathService::dirForHost($segment) === $segment`, **plus** ein Punkt) oder der reservierte `_tenants/<positive id>`-Präfix. Damit kann `<anything>/badges/…` — auch `mandants/badges/…`, `user-media/badges/…` oder ein beliebiges Deployment-Verzeichnis — **nie** als DB-verwaltet klassifiziert werden, und `_tenants/0\|abc\|-1\|007/…` ebenso wenig. **Unbekannte Roots werden nie gelöscht** (fail closed: im schlimmsten Fall bleibt eine Waise manuell zu löschen; fail open hieße, Dateien zu zerstören, die der Anwendung nicht gehören). Traversal-Segmente, Backslashes und Steuerzeichen in einem gelisteten Pfad werden vor jeder Layout-Prüfung abgewiesen. Festgenagelt in `MediaPruneOrphansTest::test_an_unknown_root_directory_is_never_reaped` (14 Pfadformen) + `test_a_real_domain_badge_file_is_managed`. |
| **Speicher (WP-10-a, WP-10-D1)** | Die vier Batch-Commands (`media:prune-orphans` wöchentlich, `media:convert-to-webp`, `media:migrate-to-domain-layout`, `reminders:send` täglich) lesen ihre Kandidaten-Tabellen in `chunkById`-Batches und verarbeiten **jeden Batch, bevor der nächste gelesen wird** — kein `->get()` über die ganze Installation. `media:prune-orphans` enumeriert den Baum **lazy** (`listContents()` als Generator) statt `allFiles()`, das genau diese Iteration plus ein Array ist. **Bleibt unbegrenzt:** die Referenz-Menge (Pfad-Strings) und die Waisen-Liste (Pfad-Strings) wachsen weiterhin mit dem Datenbestand — sie sind aber **Strings**, keine Modelle, und die Waisen werden vor dem Report bewusst sortiert (deterministische Ausgabe). |
| **Commands** | `media:prune-orphans --force`: nicht löschbare Waisen → Warnung **+ Exit-Code ≠ 0** (ein geplanter Lauf darf nicht erfolgreich aussehen, während Waisen liegen bleiben). `media:migrate-to-domain-layout` / `media:convert-to-webp --prune-originals`: nicht entfernbare Quelle/Original → Warnung, **kein** Abbruch (die DB zeigt bereits auf die neue Datei; ein Re-Run würde es ohnehin nicht wiederholen). |

`UserMediaService` (Personenbilder, `private`-Disk) folgt derselben Reihenfolge:
Quota-Prüfung → **Write** (Adapter-Rückgabewert *und* Nachbedingung müssen
sagen „geschrieben") → Transaktion (Vorgänger-Row ersetzen + neue Row) →
**unlink** der Vorgänger-Datei. Ein Write-Fehler lässt Foto *und* Row des
Antragstellers unangetastet; ein fehlgeschlagener `create` rollt die
Vorgänger-Row zurück und entfernt die frisch geschriebene Datei wieder (sonst
ein Quota-verbrauchendes Phantom). `destroy()` droppt die Row nur, wenn die
Datei wirklich weg ist.

Die „genau eine Row pro User+Typ"-Invariante der singularen Typen ist **nicht**
durch die Datenbank garantiert: `user_media` hat einen normalen
`index(['user_id','type'])` (Attachment ist legitim mehrwertig → ein
`unique(['user_id','type'])` würde Anhänge brechen), `supersededRows()` liest
außerhalb der Transaktion und die Transaktion nimmt keinen Lock. Zwei
gleichzeitige Singular-Uploads können deshalb beide einfügen (Sichtbarkeits-
Folge: zwei Rows, ein unlinktes File, kaputtes Bild — kein Datenverlust).
Details + Begründung: `features/04-media-self-service.md` und der Docblock von
`UserMediaService::supersededRows()`.

Grenze der Aussage: „Erfolg“ ist immer relativ zu den Disks, wie der Prozess
sie gerade sieht — ein **komplett unmountetes** Volume ist von einem leeren
nicht unterscheidbar (dann meldet `exists()` `false` und der Delete gilt als
„nichts zu löschen“). Das ist die dokumentierte Restunsicherheit; die
Nachbedingung schützt gegen den realen Fall „gemountet, aber nicht schreibbar“.

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
  `media`. **Keine** Kandidaten sind: Host-neutrale Dateien (`_tenants/…`; sie
  liegen bereits auf `media` und bleiben über `MediaStorage` lesbar) sowie alte
  host-neutrale `teams/<slug>/…`/`event-types/<slug>/…`-Pfade ohne Domain-/
  `_tenants`-Präfix (dieses Layout haben `TeamMediaService`/
  `EventTypeMediaService` nie geschrieben; der Command fasst es nicht an).
  Team- und Event-Typ-Logos wurden erst nach W1/W6 eingeführt und daher nie im
  alten `private`-Layout geschrieben — für sie gibt es nichts zu migrieren.
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
  geschmuggelten Request-Header vor dem Upstream. `@accel_get method GET HEAD`
  begrenzt die interne Auslieferung auf GET/HEAD (HEAD erhält so
  `Content-Length` vom `file_server` statt eines bodyless 200 ohne Länge).
  `file_server { hide .* }` blendet Dotfiles aus.
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
  wenn der Client `Accept: image/webp` sendet; sonst das Original. **Kein `Vary:
  Accept`** auf den kanonischen URLs: Die URL ist der DB-Pfad, und die
  Media-API-Antworten sind per-Request (auth-/portal-gebunden, pro Host) — es
  gibt keinen geteilten, inhaltsverhandelten Cache, den `Vary` korrekt halten
  müsste. Dieselbe Begründung steht in `config/media.php` (Sender-Seite).
- **Backfill:** `php artisan media:convert-to-webp` (dry-run default,
  `--force`, `--prune-originals`) erzeugt Geschwister für Bestandsdaten,
  idempotent; `--prune-originals` löscht das Raster-Original und stellt
  Pfad/Mime der DB-Zeile auf `.webp` um (der Badge-Renderer/DomPDF verarbeitet
  WebP). Die vier Medientabellen werden `chunkById`-weise gelesen und **jeder
  Batch im Callback verarbeitet** (nicht erst gesammelt) — ein One-Shot-Lauf über
  die ganze Installation materialisiert sie nicht (WP-10-a).
- **Alpha/Exif/Format** sind durch `WebpConversionTest` abgedeckt; die
  Badge-PDF-Pipeline mit WebP durch `BadgeRenderServiceTest`.

## Selbstreinigender Derivat-Cache (W11)

Abgeleitete `.webp`-Dateien dürfen keine Waisen werden:

- **Synchron:** Jeder Delete-Pfad räumt das Geschwister mit — `destroy()`/
  `purge()` der Brand-Services, `BadgeImageService::destroy`, der Mandant-Delete
  (Logo/Header, Event-Typ-Logos und Badge-Dateien) und der Slug-Move
  (verschiebt das Geschwister mit). Ein Mandant mit Teams kann nicht gelöscht
  werden (409); dessen Team-Dateien sind über den Team-Delete bereits weg.
  Zusätzlich löscht ein Replace alle Alt-Endungen desselben Blatts
  (`deleteAlternateExtensions`), sodass `logo.png` → `logo.jpg` keine
  `logo.jpeg`/`logo.webp`-Reste hinterlässt. Der Delete läuft über
  `MediaStorage::deleteWithVariants()` und damit **Varianten zuerst, aktuelle
  Datei zuletzt** — ein hängendes Geschwister wird gemeldet, während das Bild
  noch ausgeliefert wird, statt es vorher zu löschen.
- **Reconciliation:** `php artisan media:prune-orphans` (dry-run default,
  `--force`) listet/löscht `media`-Dateien, deren Pfad durch **keine** DB-Zeile
  referenziert wird (alle `logo_path`/`path`-Spalten plus deren `.webp`-
  Geschwister). Host-neutrale `_tenants/<id>/…`-Pfade sind normale Referenzen
  und werden erst nach dem Löschen des Mandanten zu Waisen. Nur das verwaltete
  Media-Layout wird betrachtet; deployment-bereitgestellte Root-Brand-Dateien
  (`logo.svg`, Favicons, …) werden nie angefasst. Das Löschen läuft über
  `MediaStorage::delete()` und ist **geprüft**: eine Waise, die den Versuch
  überlebt, erscheint als Warnung, wird **nicht** als gelöscht gezählt und der
  Command endet mit **Exit-Code ≠ 0** (WP-4/R-D7) — ein geplanter Lauf darf
  nicht erfolgreich aussehen, während Waisen liegen bleiben. Empfohlener
  Rhythmus: **wöchentlich** über den Scheduler
  (`Schedule::command('media:prune-orphans --force')->weekly();`) — die
  Scheduler-Infrastruktur selbst wird hier nicht aufgebaut.
  **Grenze:** Der Command enumeriert **per Default** nur die `media`-Disk und
  nur das verwaltete Layout. Eine hängende **pre-W6-Legacy-Datei auf der `private`-Disk**
  (`mandants/{slug}/…`, `badge-images/{slug}/…`) ist für ihn unsichtbar und
  bleibt manuell zu löschen — genau das sagen die Services im Log. Wer sie
  automatisch einsammeln will, muss es **explizit** verlangen (siehe
  „Optionaler Reaper-Scope" unten).
- **Root-Validierung (WP-10-b):** „verwaltetes Layout" heißt jetzt auch, dass das
  **erste** Segment ein echtes Layout-Root ist: `<domain>/…` mit kanonischem,
  normalisierbarem Host **mit Punkt** (`dirForHost($segment) === $segment`, dieselbe
  Regel wie der Accel-Guard) oder `_tenants/<positive Mandant-ID>/…`. Vorher hat
  der Command `segments[0]` nur *entfernt* und `segments[1]` geprüft — jedes
  Verzeichnis, das zufällig als `<beliebig>/badges/…` auf dem Media-Root lag,
  galt als DB-verwaltet und wäre unter `--force` gelöscht worden. **Fail closed**:
  ein unbekanntes Root-Verzeichnis (z. B. `uploads/`, `not-a-host/`,
  `Verband-A.test/` mit abweichendem Case) bleibt unangetastet; der Preis ist
  höchstens eine Waise, die von Hand weg muss.
- **Speicher (WP-10-a):** Der Lauf ist ein Wochen-Batch über die wachsende
  Media-Struktur, deshalb wird nichts davon mehr am Stück materialisiert: die
  vier Medientabellen werden per `chunkById` gelesen und der Baum **lazy**
  enumeriert (`getDriver()->listContents('', true)` als Generator). `allFiles()`
  war genau diese Iteration **plus** ein Array über den gesamten Baum — die
  Datei-Menge ist identisch, der Puffer ist es nicht.

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
| `App\Services\MediaStorage` | Disk-Adapter: Schreiben auf `media` (Rückgabewert **und** Nachbedingung geprüft), Lesen `media` → `private`, Löschen beider Disks mit **geprüftem** Ergebnis (`bool`, Nachbedingung statt Rückgabewert; `deleteWithVariants()` = Varianten zuerst, aktuelle Datei zuletzt) |
| `App\Services\ImageUploadRules` | Upload-Kontrakt: MIME-Whitelist → Endung, max. 2000×2000 px |
| `App\Services\{Mandant,Team,EventType}MediaService` | Upload/Replace/Delete je Entität im Domain-/host-neutralen Layout |
| `App\Services\BadgeImageService` | Badge-Upload unter ULID + Addressing-Zeile |
| `App\Services\WebpConverter` | Synchrones GD-WebP-Geschwister (Presets, Alpha, Exif, animiert-Ablehnung) |
| `App\Console\Commands\MediaMigrateToDomainLayoutCommand` | Dry-Run-Backfill der Legacy-`private`-Pfade |
| `App\Console\Commands\MediaConvertToWebpCommand` | WebP-Backfill (`--prune-originals`; Kandidaten-Tabellen `chunkById`) |
| `App\Console\Commands\MediaPruneOrphansCommand` | Waise-Reconciliation (`media:prune-orphans`; verwaltete `media`-Disk, Root-validiert, lazy enumeriert; Legacy-`private`-Waisen nur per `--include-legacy`) |
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
  `media:prune-orphans` hält die **verwaltete `media`-Disk** konvergent. Für
  pre-W6-Legacy-Waisen auf der `private`-Disk gilt das **nicht automatisch** —
  dort bleibt der manuelle Schritt bzw. ein bewusst opt-in gesetztes
  `--include-legacy` (siehe „Wer räumt Waisen auf?" oben).
- **Nur echte Roots werden angefasst (WP-10-b):** Der Reaper löscht ausschließlich
  Pfade, deren **erstes** Segment ein kanonischer `<domain>`-Root oder
  `_tenants/<positive id>` ist. `<anything>/badges/…`, `mandants/badges/…`,
  `user-media/…` und nicht-kanonische Hosts bleiben unangetastet — auch nicht im
  Dry-Run-Report. Personenbilder sind **nie** Reaper-Scope, auch nicht mit
  `--include-legacy`.
- **Batch-Commands streamen (WP-10-a):** `media:prune-orphans`,
  `media:convert-to-webp` und `reminders:send` lesen in `chunkById`-Batches und
  enumerieren Disks lazy; ein Lauf darf nicht die Installation bzw. den kompletten
  Media-Baum materialisieren.
- **Write-then-delete:** kein Pfad löscht die Vorgängerdatei, bevor der Ersatz
  wirklich geschrieben ist; ein Schreibfehler lässt den vorherigen Zustand
  unverändert (inkl. DB-Zeile). Gilt für öffentliches Brand-/Team-/Event-Typ-/
  Badge-Media **und** für `user-media/*`. „Wirklich geschrieben" = Adapter
  meldet keinen Fehler **und** die Datei liegt danach auf der Disk.
- **Referenz nur bei verifiziertem Delete:** eine DB-Spalte/Row wird nie
  gelöscht, solange die Datei den Löschversuch überlebt hat (R-D7). Ein bereits
  fehlender Pfad ist ein Erfolg (idempotent), kein Fehler. Und: die Datei, auf
  die die Referenz zeigt, wird **zuletzt** gelöscht — Varianten zuerst, damit
  ein `false` aus der Varianten-Runde nicht erst nach dem Verlust der einzigen
  noch gültigen Datei auffällt.
- **Kaskaden fail-safe (WF-3-D3):** Der Mandant-Delete löscht die **Row zuerst**
  und räumt die Dateien danach mit begrenztem Retry; ein abbrechendes `purge()`
  kann damit keinen live geschalteten Mandanten mit bereits gelöschten Dateien
  hinterlassen, sondern nur eine unreferenzierte Waise.
- **Portabilität (§2):** reine PHP-/Query-Builder-Logik, keine PG-Funktionen.

## Tests

- `backend/tests/Feature/MediaPathServiceTest.php` — Pfad-Matrix (Root/Domain/
  Subordner), Host-Edge-Cases (Case, Port, IDN, Traversal), `_tenants`-Fallback.
- `backend/tests/Feature/MediaMigrationTest.php` — Backfill dry-run/`--force`/
  Idempotenz.
- `backend/tests/Feature/MediaWriteFailureTest.php` — Write-Failure bricht vor
  DB-Update/Delete ab; seit WP-4 auch für `user-media/*` (Write-Failure lässt
  Porträt **und** Row stehen, keine Row mit `path = ''`), plus Quota-Semantik
  der Singular-Ersetzung. **Nachbedingung:** ein Adapter, der Erfolg meldet,
  ohne die Datei zu erzeugen (`putFileAs()` liefert den Pfad, `exists()` sagt
  `false`), bricht genauso ab — `*_failure_on_a_lost_write_*`.
- `backend/tests/Feature/MediaDeleteFailureTest.php` — `delete()`/`deleteAlternateExtensions()`/`deleteWithVariants()`
  als `bool` (false bei überlebender Datei, true bei „nichts zu löschen“),
  `destroy()`/`purge()` je Service (Mandant/Team/Event-Typ/Badge/User-Media)
  behält die Referenz und wirft, Entity-Delete-Kaskade bricht mit 500 ab,
  Commands melden nicht entfernte Dateien (Prune-Exit-Code ≠ 0). Dazu:
  `*_when_a_sibling_variant_is_stuck` (Verzeichnis `logo.webp` ⇒ aktuelle Datei
  bleibt, Referenz bleibt, Bild wird weiter ausgeliefert),
  `mandant delete with a stuck brand file leaves no dangling reference`
  (WF-3-D3: Row-Delete **vor** dem ersten unlink, keine hängende Pfad-Spalte,
  löschbare Kind-Dateien sind trotz des Fehlschlags weg, die hängende Brand-Datei
  bleibt als Waise), inkl. `… removes the row before the first file is unlinked`
  (die Reihenfolge selbst, per Delete-Attempt-Beobachter) und
  `… leaves the stuck brand file to the reaper` (Konvergenz über
  `media:prune-orphans --force`, ein DELETE-Retry antwortet 404), und die
  Leftover-Meldung: Legacy → „delete it manually", verwaltet →
  „`media:prune-orphans` reaps it".
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
  referenzierte/brand-root nicht; `--force` löscht nur Waise; idempotent; und die
  **Grenze**: eine pre-W6-Legacy-Waise auf der `private`-Disk wird nie gesehen
  (`test_a_pre_w6_legacy_leftover_on_the_private_disk_is_never_reaped`) — deshalb
  der manuelle Schritt in den Log-Meldungen. **WP-10-b:** Root-Validierung —
  `<domain>/badges/…` ist verwaltet, `<not-a-host>/…`, `mandants/badges/…`,
  `user-media/badges/…`, nicht-kanonische Hosts und `_tenants/0|abc|-1|007/…`
  dagegen nie (14 Pfadformen), inkl. Dry-run-Nennung.
- `backend/tests/Feature/MediaPruneOrphansLegacyTest.php` — **WP-10-c**
  `--include-legacy`: beide Legacy-Layouts werden gesammelt (Default **aus**,
  ohne Flag wird die `private`-Disk nachweislich gar nicht enumeriert), Idempotenz,
  referenzierte Legacy-Dateien bleiben, `user-media/**` bleibt unberührt, Nonsense-
  Formen bleiben, Dry-run meldet beide Pässe getrennt und löscht nichts, ein
  gescheiterter Legacy-Delete ⇒ Exit-Code ≠ 0.
- `backend/tests/Feature/ConsoleCommandChunkingTest.php` — **WP-10-a**: für
  `media:prune-orphans` (alle vier Medientabellen), `media:convert-to-webp` (alle
  vier), `media:migrate-to-domain-layout` (**WP-10-D1**: `mandants` + `badge_images`)
  und `reminders:send` (Akkreditationen **und** Anträge je Akkreditierung)
  wird per Query-Count gepinnt, dass eine Menge größer als die Chunk-Size in
  **mehr als einem** Round Trip gelesen und der letzte Batch wirklich verarbeitet
  wird; plus lazy-Walk statt `allFiles()` und ein Kontrolltest, dass der Zähler
  einen ungechunkten Read (1 Statement) von einem gechunkten (≥ 2) unterscheidet.
- `BadgeRenderServiceTest` — Badge-PDF mit WebP-Upload-Bild (DomPDF).
- `caddy validate` + Laufzeitmatrix C1–C7 (caddy:2 + php:8.5-fpm).

## Offene Punkte

- **Go-Live Caddy:** `MEDIA_ROOT` auf dem Host anlegen/befüllen (Root-Fallback =
  Brand-Dateien), read-only Mount, `(media_overrides)` + `(media_api_accel)` in
  alle Mandanten-Site-Blöcke, danach `MEDIA_ACCEL_PREFIX` setzen; Sync/Reload
  erst nach Freigabe.
- **Scheduler-Cron:** `media:prune-orphans --force` wöchentlich einplanen
  (Infrastruktur außerhalb dieses Repos).
- **Optionaler Reaper-Scope — UMGESETZT (WP-10-c, 2026-09-26):**
  `media:prune-orphans --include-legacy` enumeriert zusätzlich die zwei
  Legacy-Layouts auf der `private`-Disk (`mandants/{slug}/{logo,header}.<ext>`,
  `badge-images/{slug}/<ulid>.<ext>`), **nur** hinter dem expliziten Opt-in-Flag,
  damit ein geplanter Lauf nie unerwartet Dateien löscht. Dry-run gilt dort
  ebenfalls, beide Pässe melden getrennt, ein gescheiterter Legacy-Delete gibt
  Exit-Code ≠ 0, und `user-media/**` bleibt auch hinter dem Flag draußen.
  **Absichtlich nicht im Default:** der wöchentliche Scheduler-Eintrag bleibt
  `media:prune-orphans --force` ohne Flag; wer die Altbestände abräumen will,
  startet den Lauf einmal manuell mit `--include-legacy --force` (bzw. einmal im
  Dry-run zur Bestandsaufnahme). Die Log-Meldung der Services („delete it
  manually") bleibt damit **korrekt** — sie beschreibt den Default-Lauf.
- **Alias-Domains:** Multi-Domain-Mandanten vor Go-Live klären (Alias-Verzeichnisse
  oder Host-Mapping).
- **Case-Annahme (L1):** beobachten; bei Bedarf Host-Normalisierung in Caddy
  erzwingen.
