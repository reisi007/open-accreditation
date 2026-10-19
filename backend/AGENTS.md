# AGENTS.md — Backend (Laravel PHP)

Module-scoped operating guidelines for the Laravel backend in `backend/`.

Global rules (Definition of Done, AI workflow & TODO management, E2E tag policy,
agent roles, Postgres/SQLite portability) live in the repo root `AGENTS.md` and
apply here as well.

## Commands

Backend tests (PHP 8.5+ via Homebrew — `php` auf dem PATH):

```bash
cd backend && php artisan test
```

## Database Setup Policy (STRICT)

Dev/Prod nutzen **Postgres** (`bash scripts/e2e-up.sh` = `docker compose
--env-file deployment/dev.env -f deployment/docker-compose.yml --profile mail
up -d`; `--env-file` UND das `mail`-Profil sind Pflicht — siehe Kopf von
`deployment/docker-compose.yml`), Tests laufen auf **SQLite `:memory:`** (siehe
`phpunit.xml`).

Nach `php artisan migrate:fresh` MUSS `php artisan db:seed` (oder `--seed`
Flag) ausgeführt werden. Ohne Seed existiert kein Admin-User — Login und Auth
sind tot. Der `DatabaseSeeder` legt den Admin via `firstOrCreate` mit
`ADMIN_EMAIL`/`ADMIN_PASSWORD` an.

**Migration-Regel (STRICT):** Bei JEDER Migration gilt: **immer seeden**, nie
nur migrieren. `php artisan migrate` (bzw. `migrate:fresh`) allein reicht
nicht — anschließend IMMER `php artisan db:seed` (oder `--seed` Flag)
ausführen.

**Migration Policy (CRITICAL):** Migrationen werden mit **Erstelldatum** nummeriert (Laravel-Standard
`YYYY_MM_DD_HHMMSS_*`). Schema-Änderungen **erweitern** bestehende Migrationen (Dateien dürfen frei
angepasst werden) — **nur solange, wie keine geteilte Datenbank die Datei jemals gelaufen ist** (keine
Entwickler-DB, kein Staging, kein CI-Container mit Volume, kein geteilter Postgres — ein Prod-Deploy ist
nur *eine* Auslöschung dieser Bedingung). Sobald die Datei irgendwo gelaufen ist: **jede Schema-Änderung
erhält eine eigene, neue Migration** (Erstelldatum), ohne Diskussion. Grund: Laravels Migrator überspringt
jeden Dateinamen, der bereits in der `migrations`-Tabelle steht — eine Änderung an so einer Datei erreicht
also **ausschließlich** `migrate:fresh` und **nie** eine bereits migrierte Datenbank. **`down()`-Methoden
werden nie ausgeführt und können als Regel leer gelassen werden.** Wächter für den In-Place-Fall:
`tests/Feature/FailedJobsMandantIdMigrationTest.php` — eine Spalte, die nur `migrate:fresh` sieht, ist per
Definition durch *keinen* Spaltentest findbar, deshalb spielt der Test die defekte Datenbanklage nach und
verlangt, dass `migrate` sie nachliefert.

### Prod-Schema via Compose — nicht manuell (STRICT)

Im **Prod** läuft das Schema **nicht** per `exec`, sondern automatisch bei
`docker compose … --profile prod up -d --build` (Ein-Befehl-Start, siehe
README „Deployment (Prod)"):

- Der One-Shot-Service **`migrate`** (Profil `prod`) führt über
  `deployment/entrypoint.sh` (Modus `migrate`) `php artisan migrate --force`
  → `storage:link` (nur falls der Link fehlt) → optional `db:seed` →
  `config:cache`/`config:clear` aus und beendet sich.
- **`backend`** hängt an `migrate: condition: service_completed_successfully`:
  schlägt der Deploy-Schritt fehl, startet der FPM-Service gar nicht. Es gibt
  also kein Fenster, in dem die App gegen ein veraltetes Schema ausliefert.
- **Seeding ist per Default AUS** (`RUN_SEEDER`) — die „immer seeden"-Regel
  oben gilt für **Dev/CI** (`scripts/e2e-up.sh`, `migrate --seed` lokal), nicht
  für einen Prod-Deploy ohne Zutun des Operators.
- Caching ist per Default AUS (`RUN_CACHE_WARMUP`). `config:cache` backt die
  Deploy-ENV als Schnappschuss ein; danach braucht jede ENV-Änderung ein
  Re-Deploy oder `php artisan config:clear`.

Manuelles `exec … php artisan migrate/db:seed` bleibt als **Escape-Hatch**
dokumentiert (Rollback, bestehender `migrate`-Container) — es ist aber nicht
mehr der Normalpfad.

## Parallel Testing (PHP) — SQLite `:memory:`

`paratest` ist installiert (`brianium/paratest`). Die Tests laufen via
`phpunit.xml` vollständig auf **SQLite `:memory:`** — kein DB-Container, keine
Worker-DBs:

- Canonical Test-DB: SQLite `:memory:` (aus `phpunit.xml`).
- `php artisan test --parallel` funktioniert out-of-the-box: jeder
  paratest-Worker-Prozess startet eine eigene, isolierte In-Memory-DB.

**KONKURRENZ-REGEL (STRICT, Subagenten):**

- `RefreshDatabase` migriert die In-Memory-DB bei jedem PHPUnit-Prozessstart
  frisch; SQLite `:memory:` macht parallele Läufe von Natur aus isoliert.
- Trotzdem: Die volle Suite läuft zur Reproduzierbarkeit IMMER NUR in EINEM
  Subagenten (einmal).
- Scoped-Runs (`--filter`) sind ohne Worker-DB-Setup direkt möglich:

```bash
php artisan test --filter <TestClass>
```

### STRICT-MODE: die Auth-Singleton-Messung

Zusätzlich zum normalen Lauf gibt es **einen** zweiten, selteneren:

```bash
JWT_AUTH_STATE_STRICT=1 php artisan test
```

Der Schalter (`Tests\TestCase::strictAuthStateIsEnabled()`) leert **vor jeder
Anfrage** den prozessglobalen `JWT::$token` und den Guard-Memo. Damit kann eine
Anfrage nur noch über die **Draht** authentifiziert sein — ein Test, dessen
Cookie stillschweigend nicht mehr transportiert wird, wäre sonst **grün, aber
aus dem Speicher beantwortet**. Deshalb ist `withJwtCookie()` der einzige Kanal
und `ForbiddenJwtCookieChannelTest` existiert. Genau **eine** Klasse darf per
`$answersRequestsFromTheInMemoryJwtToken` ausgenommen sein (der Premissen-Test,
der beweist, dass der Singleton überhaupt antworten KANN) — `JwtAuthStateStrictnessTest`
nagelt das fest. **Nicht** in `phpunit.xml` als Default.

### Datei-Tests: `Storage::fake` und die zwei Race-Arten (STRICT)

Die DB ist per `:memory:` prozessisoliert, der **Dateisystem-Bereich nicht** —
zwei strikt zu trennende Fehlerbilder:

1. **Cross-Run-Rest (History-Abhängigkeit).** Die Fake-Disk-Roots liegen unter
   `storage/framework/testing/disks/<disk>`: einem **geteilten, gitignorierten**
   Verzeichnis. `Tests\TestCase` faked **alle** lokalen Disks (`local`, `private`,
   `media`, `public`) in jedem `setUp()` und leert deren Roots in jedem
   `tearDown()` — ein Test kann die **produktiven** Roots nicht mehr erreichen,
   und ein Lauf hinterlässt keine Reste. Ein wiederholter Volllauf im selben
   Checkout ist damit reproduzierbar; wer Residue findet, hat einen echten Bug
   (Regressions-Guard: `tests/Feature/TestDiskIsolationTest.php`). `storage/app/**`
   wird **niemals** gelöscht: im Dev-Checkout steckt dort echtes Media.
2. **Cross-Prozess-Race.** Zwei gleichzeitig laufende Vollausführungen im selben
   Checkout teilen dieselben Disk-Roots und löschen sich gegenseitig die Dateien.
   `TestCase::isolateFakeDisksInThisProcess()` baut deshalb einen **prozesseigenen**
   Token (`TEST_TOKEN`-Präfix + PID) plus Shutdown-Hook, der nur die eigenen Roots
   entfernt. **Gegenmittel bleibt trotzdem die Disziplin:** die volle Suite läuft
   gleichzeitig immer nur in EINEM Subagenten (ein `--filter`-Subagent konkurriert
   weiterhin um CPU/DB-Verbindungen); Scoped-Runs zweier Subagenten mit disjunkten
   Klassen sind unkritisch.

## Queues & Scheduler (STRICT)

`jobs` und `failed_jobs` liegen seit Projektbeginn in der Migration — **eine eigene Outbox-Tabelle wäre
die falsche Antwort** (sie stellte eine zweite Wahrheit neben eine vorhandene). Für Mail-Zustellung mit
Wiederholung und Dead-Letter gilt:

| Bedarf | Vorhanden |
|---|---|
| Zustellauftrag in die Queue | `ShouldQueue` + `dispatch()` |
| Retries deckeln | `public int $tries` (die Suite pinnt `QUEUE_CONNECTION=sync`, sie sieht keinen Worker) |
| Backoff zwischen Versuchen | `backoff()` — oder `release()` |
| Senden erst nach Commit | `config/queue.php` auf `after_commit => true` |
| Dead Letter Queue | `failed_jobs` + `queue:failed` |
| manueller Requeue | `queue:retry` |
| Aufräumen | `queue:forget`, `queue:prune-failed` |
| Worker | `queue:work --tries=5 --timeout=…` |
| Scheduler | `schedule:run` (60-s-Takt) / `schedule:work` |

- **`--tries` ist der Boden für Jobs OHNE eigenen Deckel, nicht die Obergrenze** — ein Job mit eigenem
  `$tries`/`backoff()` trägt seinen Deckel im Payload und gewinnt gegen die CLI-Zahl.
- **`after_commit` ist die eine Zeile, die „Status und Zustellung in derselben Transaktion" ausdrückt** —
  Freigabe persistiert, Mail raus; wird die Transaktion zurückgerollt, ist die Mail nie gegangen.

### Der Betrieb ist nicht im Code, er ist im Supervisor

`php artisan queue:work` und `schedule:run` laufen nicht von selbst. `Schedule::command()` registriert nur
— der Aufrufer ist Betrieb, nicht Code.

- **Prod:** `deployment/backend-supervisor.sh` (im Image, gestartet über `deployment/entrypoint.sh serve`)
  startet Worker **und** Scheduler im 60-s-Takt; der `backend`-Healthcheck verlangt FPM + Supervisor +
  Worker + Scheduler als laufend. Ein toter Worker muss den Container **ungesund** machen.
- **Dev:** `scripts/dev-worker.sh`, host-native (README „Worker & Scheduler in Dev") — ein dokumentierter
  **manueller Schritt**, kein Autostart.
- **E2E/CI: startet absichtlich nichts** (nur `php artisan serve`, `QUEUE_CONNECTION=sync`); der
  Queue-Vertrag (tries/backoff/Dead-Letter/Idempotenz-Claim) ist PHPUnit-Sache mit echter
  `database`-Connection. Im E2E-/CI-Stack feuert damit **kein** Scheduler; „`allocation:run` läuft
  stündlich" ist dort **nicht** beobachtbar (Beobachtungslücke, kein Betriebsdefekt).

**Referenz:** `portal.reisinger.pictures/deployment/backend-supervisor.sh`, bei der Übernahme **mit
Namensanpassung** (die PID-Pfade `portal-queue-*` kollidieren sonst). **Sieben** Details, deren Weglassen
den Fehler wieder einführt — die ersten sechs aus der Portal-Referenz, der siebte unser eigener:

- `QUEUE_CONNECTION` muss `database` sein — **sonst Abbruch beim Start**.
- `DB_QUEUE_CONNECTION` muss `DB_CONNECTION` entsprechen — Queue und DB in derselben Transaktion.
- **`QUEUE_WORKER_TIMEOUT` < `DB_QUEUE_RETRY_AFTER`**, fail-closed (keine doppelte Reservierung).
- **Stale PIDs beim Start löschen** — sonst lässt ein Container-Neustart eine alte PID gesund aussehen.
- Worker in einer **Restart-Schleife** mit PID-Marker.
- `schedule:run` im **60-s-Takt**; ein fehlgeschlagener Lauf darf den nächsten nicht verhindern.
- **`CACHE_STORE` muss ein GETEILTER Store sein** (`database`/`redis`/`memcached`/`dynamodb`), fail-closed
  beim Start — sonst ist der Idempotenz-Claim in `App\Jobs\SendMandantMail` (`Cache::add(…)`) prozesslokal
  und zwei Worker senden dieselbe Mail. Guard: `tests/Feature/QueueSupervisorCacheStoreGuardTest.php`.

**Migration/Seed/Admin laufen `… || exit 1`**, bevor Worker, Scheduler und FPM starten.

**Mandanten-Isolation auf `failed_jobs`:** Die Tabelle trägt eine nullable, indizierte `mandant_id`
(ohne FK), gefüllt von `App\Queue\Failed\MandantAwareFailedJobProvider`. Ein `mandant_admin` sieht nur die
Briefe **seines** Mandanten; fremder Mandant → 404. DLQ-API: `GET /api/admin/failed-mails` und
`POST /api/admin/failed-mails/{id}/requeue`, Gate `mails.dlq.manage` (nur `mandant_admin`; `super_admin`
sieht alle), `queue:prune-failed` **nicht** eingerichtet. **Voller Vertrag: `features/mail-delivery.md`.**

## Portabilitätsregel (CRITICAL)

Schema/Queries müssen zwischen **Postgres (Dev/Prod)** und **SQLite `:memory:` (Tests)** portabel bleiben:

- **Kein PG-spezifisches SQL** in Migrationen/Queries.
- JSON-Spalten via Laravel `json`-Type (kein rohes `jsonb`).
- Datumsarithmetik über Query-Builder/Eloquent statt roher PG-Funktionen.
- Wo Postgres-Features nötig sind → Service-Abstraktion + separater Integrationstest (in `features/`
  dokumentieren).

### Das Gate laufen lassen: `DB_HOST=dind`, nicht `localhost`

```bash
DB_HOST=dind bash scripts/test-pgsql.sh
```

`deployment/docker-compose.yml` publiziert Postgres auf `127.0.0.1:5432` — und **das `127.0.0.1` dieser
Shell ist nicht das des Docker-Namespaces**: `localhost:5432` ist `Connection refused`. Der Port ist auf
dem `dind`-Container offen; `DB_HOST=dind` ist der gemessene Weg dorthin. Details: `Agents.headless.md` §2.1.
