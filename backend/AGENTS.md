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

**Migration Policy (CRITICAL, etabliert 2026-08-13, präzisiert 2026-10-02):**
Migrationen werden mit **Erstelldatum** nummeriert (Laravel-Standard
`YYYY_MM_DD_HHMMSS_*`). Bis zum **ersten Produktions-Deploy** gilt:
Schema-Änderungen **erweitern** bestehende Migrationen (Dateien dürfen frei
angepasst werden — kein Versionsnummern-Zeremoniell). **Nach dem nächsten
Produktions-Deploy** erhält jede Schema-Änderung eine **eigene, neue Migration**
(Erstelldatum). **`down()`-Methoden werden nie ausgeführt und können als Regel
leer gelassen werden.**

> **Die Bedingung, die 2026-10-02 dazukam — die Ausnahme gilt nur, solange
> KEINE geteilte Datenbank die Datei jemals gelaufen hat.**
>
> „Bestehende Migrationen erweitern" ist keine Geschmacksfrage, sondern eine
> Wette darauf, dass **keine** Datenbank außer deiner own frisch
> migrierten existiert. Laravels Migrator überspringt jeden Dateinamen, der
> bereits in der `migrations`-Tabelle steht (`Migrator::pendingMigrations()`) —
> eine Änderung an so einer Datei erreicht also **ausschließlich** `migrate:fresh`
> und **nie** eine bereits migrierte Datenbank.
>
> **Gemessen am 2026-10-02, Position 45 (Commit `70aa03d`):** `mandant_id` wurde
> per In-Place-Edit in `0001_01_01_000002_create_jobs_table.php` ergänzt. Die
> Suite blieb vollständig grün — `RefreshDatabase`/`migrate:fresh` führt **jede**
> Datei von null aus und hatte die Spalte damit immer. Auf jeder bereits
> migrierten Datenbank antwortete `php artisan migrate` mit
> `INFO Nothing to migrate.`, und `GET /api/admin/failed-mails` mit
>
> ```text
> SQLSTATE[42703]: column "mandant_id" does not exist
> (select * from "failed_jobs" where "mandant_id" is not null …)
> ```
>
> **Wann die Ausnahme noch gilt:** nur wenn die Datei **nirgends** gelaufen ist
> — keine Entwickler-DB, kein Staging, kein CI-Container **mit Volume**, kein
> geteilter Postgres. **Ab dem ersten Ort, an dem sie gelaufen ist, ist sie
> vorbei** — auch ohne Prod-Deploy. Ab dann: **neue Migration**, ohne
> Diskussion. Ein Prod-Deploy ist *eine* Auslöschung dieser Bedingung, aber
> nicht die einzige.
>
> **Und der Wächter ist nicht die Spalte, sondern die Migrationslage:**
> `tests/Feature/FailedJobsMandantIdMigrationTest.php`. Eine Spalte, die nur
> `migrate:fresh` sieht, ist per Definition durch *keinen* Spaltentest
> findbar — der Test spielt deshalb die Datenbank nach, auf der der Defekt
> lebte (Datei im Ledger, Spalte fehlt), und verlangt, dass `migrate` sie
> nachliefert. Zusätzlich benennt er die Täterdatei im Quelltext (mit
> auskommentiertem Code, sonst bestraft er die eigene Warnung).
>
> **Merksatz:** „erweitern" heißt *nur* auf einer Datenbank, die es noch nicht
> gibt. Sobald es eine gibt, ist die neue Datei billiger als die Fehlersuche.

### Prod-Schema via Compose — nicht manuell (STRICT)

Im **Prod** läuft das Schema **nicht** per `exec`, sondern automatisch bei
`docker compose … --profile prod up -d --build` (Ein-Befehl-Start, siehe
README „Deployment (Prod)"):

- Der One-Shot-Service **`migrate`** (Profil `prod`) führt über
  `deployment/entrypoint.sh` (Modus `migrate`) `php artisan migrate --force`
  → `storage:link` (nur falls der Link fehlt; das Image liefert ihn mit)
  → optional `db:seed` → `config:cache`/`config:clear` aus und beendet sich.
- **`backend`** hängt an `migrate: condition: service_completed_successfully`:
  schlägt der Deploy-Schritt fehl, startet der FPM-Service gar nicht. Es gibt
  also kein Fenster, in dem die App gegen ein veraltetes Schema ausliefert.
- **Seeding ist per Default AUS** (`RUN_SEEDER`, siehe unten) — die
  „immer seeden"-Regel oben gilt für **Dev/CI** (`scripts/e2e-up.sh`, `migrate
  --seed` lokal), nicht für einen Prod-Deploy ohne Zutun des Operators.
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
  paratest-Worker-Prozess startet eine eigene, isolierte In-Memory-DB. Es
  existiert keine geteilte Instanz, auf der sich parallele Läufe gegenseitig
  zerstören können.

**KONKURRENZ-REGEL (STRICT, Subagenten):**

- `RefreshDatabase` migriert die In-Memory-DB bei jedem PHPUnit-Prozessstart
  frisch. SQLite `:memory:` macht parallele Läufe von Natur aus isoliert.
- Trotzdem: Die volle Suite läuft zur Reproduzierbarkeit IMMER NUR in EINEM
  Subagenten (einmal).
- Scoped-Runs (`--filter`) sind ohne Worker-DB-Setup direkt möglich:

```bash
php artisan test --filter <TestClass>
```

### STRICT-MODE: die Auth-Singleton-Messung (2026-09-29)

Zusätzlich zum normalen Lauf gibt es **einen** zweiten, selteneren:

```bash
JWT_AUTH_STATE_STRICT=1 php artisan test
```

Der Schalter (`Tests\TestCase::strictAuthStateIsEnabled()`) leert **vor jeder
Anfrage** den prozessglobalen `JWT::$token` und den Guard-Memo. Damit kann eine
Anfrage nur noch über die **Draht** authentifiziert sein — und ein Test, dessen
Cookie stillschweigend nicht mehr transportiert wird, ist **grün**, aber aus dem
Speicher beantwortet. Dieser Zustand ist für jeden anderen Mechanismus unsichtbar
(der Statuscode stimmt, die Assertion greift). Genau deshalb ist die Messung
nicht optional: sie ist der Grund, warum `withJwtCookie()` der einzige Kanal ist
und warum `ForbiddenJwtCookieChannelTest` existiert.

**Gemessener Preis: null.** Auf diesem Stand liefern relaxter und strikter Lauf
**dieselbe** Fehlermenge (identische Fehlertest-Namen, nicht nur identische
Zahlen). Vorher — ohne die Ausnahme für den Premissen-Test — war es **genau
ein** roter Test, und dieser eine ist der Premissen-Test
(`JwtCookieChannelTest::test_the_in_memory_token_alone_can_authenticate_a_request`),
der per `$answersRequestsFromTheInMemoryJwtToken` ausgenommen ist und es auch
sein MUSS: er beweist, dass der Singleton überhaupt antworten KANN, und ohne ihn
beweist sein Leeren nichts. `JwtAuthStateStrictnessTest` nagelt fest, dass
**genau eine** Klasse diese Ausnahme beansprucht — zwei würden die Messung
ungültig machen, keine hieße, dass der Premissen-Test verschwunden ist.

**Kosten im Normalfall:** ein statischer Read und ein `if` pro Anfrage. Der
Vollauf liegt damit in der Rauschgrenze seiner bisherigen Dauer. **Nicht** in
`phpunit.xml` als Default: der Normalfall misst das Produkt, und die
Ausnahme-Liste soll nicht stillschweigend wachsen können.

### Datei-Tests: `Storage::fake` und die zwei Race-Arten (STRICT, 2026-09-26)

Die DB ist per `:memory:` prozessisoliert, der **Dateisystem-Bereich nicht** —
dort gibt es zwei strikt zu trennende Fehlerbilder:

1. **Cross-Run-Rest (History-Abhängigkeit, WP-11 — GEBEUTEN).** Die Fake-Disk-
   Roots liegen unter `storage/framework/testing/disks/<disk>`: einem
   **geteilten, gitignorierten** Verzeichnis. Ohne Cleanup sammeln sich dort
   die echten Dateien aller Tests über die Läufe hinweg, bis reihenfolge-/
   historienabhängige Assertions brechen. Ursache war doppelt: `Storage::fake()`
   wurde nur von ~20 Testklassen aufgerufen — jeder Test **ohne** `fake()` schrieb
   auf die **produktiven** Roots `storage/app/private` (das teilen sich `local`
   und `private`) bzw. `storage/app/media`, also in das echte Dev-Media des
   Entwicklers; und nichts leerte `storage/framework/testing/disks/` nach einem
   Lauf. `Tests\TestCase` faked jetzt **alle** lokalen Disks (`local`,
   `private`, `media`, `public`) in jedem `setUp()` und leert deren Roots in
   jedem `tearDown()`. Folge: ein Test kann die Produktiv-Roots nicht mehr
   erreichen, und ein Lauf hinterlässt keine Reste. **Ein wiederholter
   Volllauf im selben Checkout ist damit reproduzierbar** — wer Residue findet,
   hat einen echten Bug (siehe `tests/Feature/TestDiskIsolationTest.php`, der
   genau das als Regressions-Guard festnagelt). `storage/app/**` wird
   **niemals** gelöscht: im Dev-Checkout steckt dort echtes Media.
2. **Cross-Prozess-Race (Kollisionsseite geschlossen, 0f9cf57).** WP-11 hat
   nur die *Aufräum*-Seite gelöst: Zwei gleichzeitig laufende
   Vollausführungen im selben Checkout teilten dieselben Disk-Roots und
   löschten sich gegenseitig die Dateien des jeweils anderen Tests (der
   klassische 3-Flake-Fall — gemessen bis **243** Fehlschläge in einem
   Agenten-Lauf). `0f9cf57` schließt die *Kollisions*-Seite: `Storage::fake()`
   leitet den Root über `ParallelTesting::token()` ab, und unter plain
   `php artisan test` ist dieser `false` — jeder Prozess rootete also auf
   `storage/framework/testing/disks/<disk>`, das `fake()` bei JEDEM Aufruf
   leert. `TestCase::isolateFakeDisksInThisProcess()` (erster Aufruf in
   `setUp()`) baut jetzt über `ParallelTesting::resolveTokenUsing()` einen
   **prozesseigenen** Token (`TEST_TOKEN`-Präfix + PID, prozessweise statisch
   gecacht), plus Shutdown-Hook, der nur die eigenen Roots entfernt. Zwei
   parallele Vollausführungen im selben Checkout stören sich damit nicht mehr.
   **Gegenmittel bleibt trotzdem die Disziplin (siehe oben):** die volle Suite
   läuft gleichzeitig immer nur in EINEM Subagenten. Das ist kein
   geteilter-Disk-Problem mehr, sondern die übliche Floor-Regel für
   reproduzierbare Läufe (ein `--filter`-Subagent während eines Volllaufs
   konkurriert weiterhin um CPU/DB-Verbindungen). Scoped-Runs (`--filter`)
   zweier Subagenten mit disjunkten Klassen sind unkritisch.

## Queues & Scheduler — **die Tabellen und Befehle existieren bereits (2026-10-02)**

**Vor dem Bau von irgendetwas: `jobs` und `failed_jobs` liegen seit Projektbeginn in der Migration und
werden von null Code benutzt.** `database/migrations/0001_01_01_000002_create_jobs_table.php` legt
`jobs` (`:14-22`, mit `attempts`, `available_at`, `reserved_at` — **Deckelung und Backoff sind
eingebaut**) und `failed_jobs` (`:37-47`) an. `config/queue.php:123-127` zeigt `failed` bereits auf
`failed_jobs`. **Eine eigene Outbox-Tabelle ist damit die falsche Antwort** — sie stellte eine zweite
Wahrheit neben eine vorhandene.

**Für Mail-Zustellung mit Wiederholung und Dead-Letter gilt:**

| Bedarf | Bereits vorhanden |
|---|---|
| Zustellauftrag in die Queue | `ShouldQueue` + `dispatch()` |
| Retries deckeln | `public int $tries` (die Suite pinnt `QUEUE_CONNECTION=sync`, sie sieht keinen Worker) |
| Backoff zwischen Versuchen | `backoff()` — oder `release()` |
| **Senden erst nach Commit** | **`config/queue.php:44` auf `after_commit => true` setzen** |
| Dead Letter Queue | `failed_jobs` + `queue:failed` |
| **manueller Requeue** | **`queue:retry`** |
| Aufräumen | `queue:forget`, `queue:prune-failed` |
| Worker | `queue:work --tries=5 --timeout=…` |
| Scheduler | `schedule:run` (60-s-Takt) / `schedule:work` |

**`--tries` ist der Boden für Jobs OHNE eigenen Deckel, nicht die Obergrenze** — ein Job mit eigenem
`$tries`/`backoff()` trägt seinen Deckel im Payload und der Job gewinnt gegen die CLI-Zahl (gemessen:
mit `--tries=1` wurde `SendMandantMail` **nicht** nach dem ersten Versuch dead-letteret), weshalb die
Zahl hier auf 5 steht.

**`after_commit` ist die eine Zeile, die „Status und Zustellung in derselben Transaktion" ausdrückt** —
Freigabe persistiert, Mail raus, und wenn die Transaktion zurückgerollt wird, ist die Mail nie
gegangen. Das ist kein Detail, es ist die Zusage.

### Der Betrieb ist nicht im Code, er ist im Supervisor — und er ist kopierbar

**`php artisan queue:work` und `schedule:run` laufen nicht von selbst.** `Schedule::command()`
registriert nur — der Aufrufer ist Betrieb, nicht Code. **Wer ihn startet, und wo er absichtlich fehlt:**

- **Prod:** `deployment/backend-supervisor.sh` (im Image, gestartet über `deployment/entrypoint.sh serve`) startet
  Worker **und** Scheduler im 60-s-Takt; der `backend`-Healthcheck verlangt FPM + Supervisor + Worker + Scheduler
  als laufend.
- **Dev:** `scripts/dev-worker.sh`, host-native (das Dev-Backend ist kein Container, README „Worker & Scheduler
  in Dev"). Ein **dokumentierter manueller Schritt**, kein Autostart — wer ihn nicht startet, hat auch keinen.
- **E2E/CI: startet absichtlich nichts.** Der E2E-Job fährt nur `php artisan serve` und pinnt
  `QUEUE_CONNECTION=sync` (`.github/workflows/ci.yml`), die Backend-Suite pinnt `sync` ebenfalls
  (`phpunit.xml:117`). Das ist **die Entscheidung, kein Versehen**, und der Grund, warum der Job überhaupt läuft:
  ein echter Worker gäbe jedem mailabhängigen Spec eine Timing-Abhängigkeit, und `after_commit` existiert nur
  auf der `database`-Connection. Der Queue-Vertrag (tries/backoff/Dead-Letter/Idempotenz-Claim) ist PHPUnit-Sache
  mit echter `database`-Connection — dort steht der Worker.

**Die verbleibende Lücke ist damit eng und ehrlich benannt:** im E2E/CI-Stack feuert **kein** Scheduler —
`allocation:run` (stündlich), `reminders:send` (täglich) und `cache:prune-expired` (täglich)
(`routes/console.php:24,33,56`), alle mit `withoutOverlapping()`, laufen dort nie von selbst, und `sync` kann strukturell **keinen** toten Brief
erzeugen (`Job::fail()` schreibt keine `failed_jobs`-Zeile; nur ein Worker tut das). Position 46 ist damit
**umgesetzt** (`8c3301a`, Board: „überall außer im E2E-Stack"); was bleibt, ist eine **Beobachtungslücke**, kein
Betriebsdefekt: „`allocation:run` läuft stündlich" ist eine Zusage, die im CI-Stack **nicht** beobachtbar ist.

> **Korrigiert 2026-10-03:** dieser Absatz sagte „In **keiner** Umgebung dieses Repos wird ein Scheduler
> gestartet". Für Prod (`backend-supervisor.sh`) und den laufenden Dev-Stack (`scripts/dev-worker.sh`) war das
> **falsch** — und es widersprach dem nächsten Absatz derselben Datei, der `schedule:run` im 60-s-Takt beschreibt.
> Dieselbe Behauptung stand in `frontend/tests/screenshots/ui-review.config.ts` und wurde in `8950f68`
> korrigiert — dort wie hier aus derselben Ursache: eine Datei- bzw. Umgebungsliste, die man sich
> selbst zusammenstellt, findet immer nur, was man für möglich gehalten hat
> (`deployment/backend-supervisor.sh` stand auf keiner der beiden Listen).

**Die Referenz existiert:** `portal.reisinger.pictures/deployment/backend-supervisor.sh`. Bei der
Übernahme **mit Namensanpassung** (die PID-Pfade `portal-queue-*` kollidieren sonst mit einer
Portal-Installation auf demselben Host). **Sieben** Details, deren Weglassen den Fehler wieder einführt —
die ersten **sechs** stammen aus der Portal-Referenz, der **siebte** ist unser eigener und folgt demselben
Fail-closed-Grund:

- `QUEUE_CONNECTION` muss `database` sein — **sonst Abbruch beim Start**, damit der Fehler jetzt und nicht
  Stunden später laut wird.
- `DB_QUEUE_CONNECTION` muss `DB_CONNECTION` entsprechen — Queue und DB in derselben Transaktion.
- **`QUEUE_WORKER_TIMEOUT` < `DB_QUEUE_RETRY_AFTER`**, fail-closed — verhindert doppelte Reservierung
  eines hängenden Jobs; sonst fällt das erst unter Last auf.
- **Stale PIDs beim Start löschen** — sonst lässt ein Container-Neustart eine alte PID gesund aussehen,
  während der neue Supervisor noch startet.
- Worker in einer **Restart-Schleife** mit PID-Marker — ein toter Worker darf die Zustellung nicht
  stillstehen lassen.
- `schedule:run` im **60-s-Takt**; ein fehlgeschlagener Lauf darf den nächsten nicht verhindern.
- **`CACHE_STORE` muss ein GETEILTER Store sein** (`database`/`redis`/`memcached`/`dynamodb`), fail-closed beim
  Start. Zwei Locks liegen darin: der `withoutOverlapping()`-Lock des Schedulers **und** der Idempotenz-Claim in
  `App\Jobs\SendMandantMail` (`Cache::add('mail-delivery:{deliveryId}', true, …)`). Mit `array` ist der Claim
  prozesslokal — `send()` läuft in FPM, `handle()` im Worker: zwei Worker claimten denselben Auftrag beide,
  beide senden, und die zweite Zustellung hinterlässt weder eine `failed_jobs`-Zeile noch eine Logzeile. Das ist
  die stille Doppelzustellung bei gesund aussehendem Deployment. **Im Skript heißt er „Detail 2b"**; die
  vollständige Aufzählung steht im Kopf von `deployment/backend-supervisor.sh`, und der Guard ist durch
  `tests/Feature/QueueSupervisorCacheStoreGuardTest.php` gepinnt (Refusals inkl. unbekannter Stores; die
  ausgelieferte Compose-Vorgabe muss durchgelassen werden).

**Und zwei Dinge über „Skript starten" hinaus:** der **Healthcheck verlangt FPM + Supervisor + Worker +
Scheduler** als laufend — ein toter Worker muss den Container **ungesund** machen, sonst ist ein stiller
Zustellungsstillstand ein „gesundes" Deployment. Und **Migration/Seed/Admin laufen `… || exit 1`**, bevor
Worker, Scheduler und FPM starten: **Compose-Start → Gate → Supervisor → FPM.**

`portal.reisinger.pictures/features/infrastructure/29-production-operations-runbook.md:217` warnt
ausdrücklich davor, zu einem „naked background `queue:work`" zurückzufallen — **das ist die Falle, in die
ein Eigenbau tappt.** Und dieselbe Datei hält fest: dass die Binaries **im Image** liegen, ist
Build-Log-Evidenz, **kein** Live-Nachweis — ein echter Stack-Start bleibt ein Betriebsschritt.

**Mandanten-Isolation auf `failed_jobs`:** die Tabelle hat **keine `mandant_id`-Spalte**. Ein
`mandant_admin` darf ausschließlich Briefe **seines** Mandanten sehen; die Zuordnung aus dem
`payload`-Blob zu gewinnen wäre zerbrechlich.

> **Update 2026-10-02 (Position 45, umgesetzt):** `failed_jobs.mandant_id` **existiert jetzt**
> (nullable, indiziert, ohne FK), gefüllt von
> `App\Queue\Failed\MandantAwareFailedJobProvider`. `after_commit` **steht auf `true`**. Der
> Mailversand läuft über `App\Jobs\SendMandantMail`: `MandantMailerService::send()` dispatcht
> nur noch, `::deliver()` wirft bei Relay-Fehler (der alte `catch (Throwable)` ist weg — Retry
> + Dead Letter). DLQ-API: `GET /api/admin/failed-mails` und `POST
> /api/admin/failed-mails/{id}/requeue`, Gate `mails.dlq.manage` (nur `mandant_admin`;
> `super_admin` sieht alle), fremder Mandant → 404, `queue:prune-failed` **nicht** eingerichtet.
> **Voller Vertrag: `features/mail-delivery.md`** — inklusive der Teststrategie unter
> `QUEUE_CONNECTION=sync` (welche Klasse welchen Queue-Fake bzw. die echte `database`-Connection
> benutzt) und der Tatsache, dass Laravels *Testing*-TransactionsManager die umschließende
> `RefreshDatabase`-Transaktion ausblendet, After-Commit-Callbacks einer echten verschachtelten
> `DB::transaction()` aber feuert.
>
> **Nachtrag 2026-10-02 (derselbe Tag, derselbe Fund):** die Spalte existierte
> zunächst nur in einer **In-Place-Änderung** an `create_jobs_table` und damit auf
> **keiner** bereits migrierten Datenbank — `SQLSTATE 42703`, siehe
> „Migration Policy". Sie liegt jetzt in
> `2026_10_02_180000_add_mandant_id_to_failed_jobs_table.php`. **Merke für den
> nächsten Schema-Fall:** die Zeile oben („die Spalte existiert jetzt") war eine
> **Behauptung über das Schema**, und `migrate:fresh` — also die Quelle jeder
> Suite — kann sie nicht widerlegen.

## Portabilitätsregel (CRITICAL)

Schema/Queries müssen zwischen **Postgres (Dev/Prod)** und **SQLite
`:memory:` (Tests)** portabel bleiben:

- **Kein PG-spezifisches SQL** in Migrationen/Queries.
- JSON-Spalten via Laravel `json`-Type (kein rohes `jsonb`).
- Datumsarithmetik über Query-Builder/Eloquent statt roher PG-Funktionen.
- Wo Postgres-Features nötig sind → Service-Abstraktion + separater
  Integrationstest (in `features/` dokumentieren).

### Das Gate laufen lassen: `DB_HOST=dind`, nicht `localhost` (2026-09-30 gemessen)

Lokal:

```bash
DB_HOST=dind bash scripts/test-pgsql.sh
```

`deployment/docker-compose.yml:155` publiziert Postgres per CC-R3 bewusst auf
`127.0.0.1:5432` — und **das `127.0.0.1` dieser Shell ist nicht der des
Docker-Namespaces**: `localhost:5432` ist `Connection refused`. Der Port ist auf
dem `dind`-Container offen (`getent hosts dind` → `172.24.0.2`, `dind:5432` offen,
gemessen). **Das Gate ist hier also lauffähig**, und `DB_HOST=dind` ist der
gemessene Weg dorthin — „strukturell unerreichbar" war zweimal eine Vermutung mit
Bindungswirkung (Details: `Agents.headless.md` §2.1).

