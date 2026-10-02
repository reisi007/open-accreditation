# E-Mail-Zustellung — Queue, Retry, Dead-Letter (SOLL)

**Entscheidung 2026-10-02 (Nutzer):** *Mail ist Zustellung, nicht Best-Effort.*
Eine Freigabe-/Ablehnungs-/Erinnerungs-/Aktivierungs-Mail, die nicht ankommt,
darf **nicht** durch einen grünen Lauf gehen. Umgesetzt in Position 45/46
(„kompletter Durchstich": Worker · Scheduler · `after_commit` · DLQ ·
Admin-Oberfläche).

## 1. Zwei Schritte, nicht einer

| Schritt | Wer | Wirkung |
|---|---|---|
| **Status + Zustellauftrag** | `MandantMailerService::send()` im Request/Command | schreibt nur einen `SendMandantMail`-Job in `jobs` |
| **Zustellung** | `MandantMailerService::deliver()`, ausgeführt vom Queue-Worker | SMTP; wirft bei Fehler |

`send()` ist damit ein reiner Dispatch und kann an einem kaputten Relay nicht
scheitern. `deliver()` **wirft bewusst**: nur so bekommt der Worker etwas, womit
er wiederholen und am Ende in die Dead-Letter-Queue schreiben kann. Der frühere
`catch (Throwable)` im Service ist entfernt; die protokollierte Fehlermeldung
(`Log::warning('Mandant mail dispatch failed')`) lebt jetzt in
`SendMandantMail::failed()` — also genau dann, wenn der Brief wirklich tot ist.

**Wer ruft `send()`:** `AllocationService` (Freigabe/Ablehnung, single + bulk),
`SendReminders` (täglich), `AdminApplicationController::resend` (manueller
Resend) und `AuthController::register` (Aktivierungs-Mail). Der Registrierungs-
Pfad ist damit von seiner Sondersemantik abgerückt: er antwortet **201**, auch
wenn die Mail noch nicht raus ist (Nutzerentscheid 1), und er läuft wie alle
anderen über den mandant-spezifischen Relay, falls einer konfiguriert ist.

## 2. `after_commit` ist die Zusage „Status und Zustellung in einer Transaktion"

`config/queue.php` → `connections.database.after_commit => true`. Ein in einer
Transaktion dispatchter Job wird **erst nach dem Commit** in `jobs` geschrieben.
Daraus folgt:

- Eine zurückgerollte Freigabe/Registrierung sendet **nichts**.
- Eine committete Entscheidung hinterlässt **immer** einen Zustellauftrag — auch
  dann, wenn der Request danach mit 500 abbricht.
- Der Kommentar in `AllocationService::dispatchApprovedMails`, der hierfür eine
  eigene Outbox-Tabelle verlangte, ist **überholt**: `jobs` ist diese Outbox.

**Der ausgelieferte Wert ist bewacht.** Die Tests in
`QueuedMailAfterCommitTest` beweisen den **Mechanismus** in beide Richtungen
(und setzen die Flag dabei selbst), also konnten sie einen Wechsel auf `false`
in `config/queue.php` nicht bemerken. Der erste Test der Klasse liest den Wert
deshallocb, **ohne ihn zu setzen** — Mutation `true → false` macht genau diesen
einen Test rot.

## 3. Retries sind gedeckelt, der Endzustand ist terminal

`SendMandantMail` trägt `$tries = 5` und `backoff() = [60, 300, 900, 3600]`.
Beide Werte reisen im Payload mit, nicht im `queue:work`-Aufruf. Nach dem letzten
Versuch liegt der Job in **`failed_jobs`** (Laravel-Standard, `database-uuids`).

| Zustand | Wer bewegt ihn | Bedingung |
|---|---|---|
| `pending` → Versuch | Worker | automatisch |
| `pending` → `pending` | Worker | nur solange `attempts < max_attempts` |
| `pending` → **`dead`** | Worker | Versuche erschöpft — **terminal** |
| `dead` → `pending` | **Mensch, manuell** | `mandant_admin` (nur eigener Mandant), `super_admin` (alle) |

Es gibt **keinen** Code-Pfad, der einen `dead`-Eintrag wieder in eine Schleife
setzt. Das ist der Schutz gegen den Endlos-Retry: `SendReminders` ist ein
**wiederkehrender Produzent**, ein dauerhaft totes Relay erzeugt also bei jedem
Scheduler-Lauf neue Aufträge für dieselbe logische Mail.

## 4. Idempotenz-Wache, und die Polarität, die sie erzwingt

### 4.1 Das Problem, das eine Queue neu einführt

Die eine Doppelzustellung, die eine Queue **neu** einführt: der Worker sendet
die Mail, stirbt vor dem Ack, und der Job läuft nach `retry_after` erneut.

### 4.2 Die Entscheidung: begrenzter Claim, und auf Claim-Treffer wird GEWORFEN

> **Polarität (Entscheidung der Reparaturrunde 2026-10-02, Position 45).**
> Der Claim ist ein **begrenzter atomarer Claim** (`Cache::add($key, true,
> SendMandantMail::CLAIM_TTL_SECONDS)`), und ein Lauf, der auf einen fremden
> Claim trifft, **wirft** `MailDeliveryAlreadyClaimedException`, statt normal
> zurückzukehren. Damit verlässt **kein** Job die Queue, ohne entweder
> zugestellt worden zu sein oder **sichtbar** in `failed_jobs` zu liegen (mit
> `Log::warning` aus `failed()`). Das ist „at-most-once **innerhalb** des
> Claim-Fensters, at-least-once **danach**" — und die Wahl ist zwingend, weil
> die Kopfentscheidung lautet: *Mail ist Zustellung, nicht Best-Effort.*
> Best-effort ist damit ausgeschlossen.

Der alte Code kehrte **normal** zurück. Das war wörtlich die Fehlerklasse, die
Position 45 eröffnet hat — **gemessen** (Claim gesetzt, kein Versand,
`queue:work --once`):

| | vorher (`return`) | jetzt (`throw`) |
|---|---|---|
| `jobs` | 0 | 0 |
| `failed_jobs` | **0** | **1** (mit `mandant_id` + Empfänger) |
| `Mail::assertNothingSent()` | true | true |
| Log | **nichts** | `Log::warning(… 'refused_as_duplicate' => true)` |
| Worker meldet | `DONE` | `failed` |

### 4.3 Warum begrenzt, nicht unbegrenzt (gemessen am Query-Log)

`Cache::add()` **ohne** TTL geht in `Illuminate\Cache\Repository::add()` an
`$store->add()` **vorbei** und fällt auf `get()` + `put()` → `forever()`
zurück. Gemessen (Laravel 13.33.0, `database`-Store, Bindings in Klammern):

| Aufruf | Statement | Folge |
|---|---|---|
| `add($k, true)` | `select * from "cache" where "key" in (?)` + `insert into "cache" … on conflict ("key") do update set …` | **kein** Test-and-set — zwei Worker bekommen beide `true` und **senden beide** |
| `add($k, true, 3600)` | `select * from "cache" where "key" in (?)` + `insert or ignore into "cache" …` | Schreibseite atomar; Ablauf in **dem übergebenen** TTL statt `315360000 s` (**zehn Jahre**). Die `3600` hier ist der Wert der Messung, nicht der des Jobs — der steht in 4.4 |

`SendReminders:78` macht es längst richtig (`Cache::add(…, now()->addDay())`) —
der Mail-Job war die Ausnahme.

**Was die zweite Zeile der Tabelle löst — und was sie nicht löst.** Eine
abgelaufene Cache-Zeile verschwindet aus **jedem Lesezugriff**, nicht aus der
Tabelle: `DatabaseStore::many()` löscht abgelaufene Zeilen beim Lesen
(`vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php:147-157`), und
`add()` liest vorher (`:214-218`). Für den **Claim** ist das genau das Gewollte —
nach seinem TTL blockiert er nichts mehr. Für den **Speicher** ist es nichts: eine
**erfolgreich zugestellte** Mail legt eine Zeile an, die danach niemand noch
einmal liest (die `deliveryId` ist frisch, es gibt keinen Folge-Claim), und die
damit dauerhaft in `cache` liegt. Beide Hälften sind gemessen in
`SendMandantMailTest::test_an_expired_claim_is_invisible_but_its_row_survives_until_something_reads_it`
— inklusive des Gegensatzes zwischen einer **gelesenen** Probe (deren Zeile
verschwindet) und dem Claim der Zustellung (dessen Zeile bleibt).

**Und es gibt kein `cache:prune` — nicht nur keines im Scheduler.** Gemessen auf
Laravel **13.33.0**: `php artisan list` kennt **keinen** Befehl, der abgelaufene
Cache-Zeilen entfernt. Der einzige mit „prune" im Namen ist
`cache:prune-stale-tags` — der ist **Redis-only** und reaped **Tags**, nicht
abgelaufene Einträge; gegen den `database`-Store aufgerufen meldet er „Stale
cache tags pruned successfully" und löscht **nichts** (gemessen), weil
`DatabaseStore` kein `TaggableStore` ist. Der alte Satz, es sei „kein
`cache:prune` nötig, weil jeder Claim von selbst verschwindet", war damit
doppelt unzutreffend: **es gibt den Befehl nicht**, und der Claim verschwindet
nur beim Lesen. Was physikalisch wächst, steht als offener Punkt in Abschnitt 8.

### 4.4 Die beiden Größen

| Größe | Wert | Bedingung |
|---|---|---|
| `SendMandantMail::CLAIM_TTL_SECONDS` | **21600 s** (6 h) | **muss > `array_sum(backoff())` sein** (4860 s) — das ist die **tragende** Ungleichung. **Muss zusätzlich > `DB_QUEUE_RETRY_AFTER` sein** (90 s), was notwendig, aber nicht hinreichend ist. Beide sind als Test festgenagelt. |
| `$tries` | 5 | ein Lauf, der auf den Claim trifft, verbraucht ihn; nach dem 5. Versuch liegt der Job in `failed_jobs` |

**Warum die Backoff-Summe und nicht `retry_after` die tragende ist.** Der letzte
Versuch wird vom **Backoff des Jobs** terminiert, nicht von `retry_after`.
Gemessen mit dem alten Wert 3600 s und dem Zeitplan
`t = 0 / 60 / 360 / 1260 / 4860`: der Claim lief bei **t = 3600** ab, Lauf 5 bei
**t = 4860** traf ihn nicht mehr und **sendete** — `jobs = 0`,
`failed_jobs = 0`, kein Log. Die Wache hat die Doppelzustellung damit nicht
verhindert, sondern den Claim **1260 s (21 min) vor dem letzten Versuch**
auslaufen lassen und das Ergebnis unsichtbar gemacht; `retry_after = 90 s` ist
an diesem Ergebnis **nicht** beteiligt. 21600 s liegen über der Summe um das
**4,4-Fache**. Der Test
`test_the_claim_window_outlives_the_whole_retry_budget` stellt die Ungleichung
zweimal: als Arithmetik gegen das echte `backoff()` **und** als Zustand — er
fährt den Job bei exakt `array_sum(backoff())` erneut und verlangt, dass er
**verweigert** und keine zweite Mail sendet.

Bei einer **Ausnahme** (Relay-Fehler) wird der Claim **freigegeben**, bevor sie
weiterläuft: ein vorübergehend totes Relay wird also normal wiederholt. Nur der
Claim-Treffer lässt einen Claim stehen — und genau der ist jetzt **laut**.

Ablage ist der Default-Cache-Store, in Produktion `database`
(`CACHE_STORE=database`, derselbe durable Store wie die JWT-Blackliste). Der
Deploy leert ihn nicht (`deployment/entrypoint.sh`) — und **räumt** die
abgelaufenen Zeilen auch niemand ab, siehe 4.3.

### 4.5 Der Ausweg: der manuelle Requeue

Ein Requeue ist **kein** Re-Try, sondern ein **neuer Auftrag** — und genau so
ist er implementiert: `FailedMailController::prepareForRequeue()` vergibt eine
**frische `deliveryId`**. Damit ist der Claim-Key neu und die Zustellung
gelingt beim **ersten** Versuch.

Das war vorher **nicht** wahr und ist messbar gewesen: der Docblock behauptete
„frisch bei jedem manuellen Requeue", der Requeue schob aber den Payload
**wortgleich** zurück (nur `attempts` auf 0) — gleiche `deliveryId`. Ohne diese
Korrektur wäre der Recovery-Pfad „warte, bis der TTL abläuft" gewesen, also
Arithmetik zwischen zwei unabhängigen Zahlen statt einer Entscheidung.

Der Preis dieser Form, offen benannt: ein Requeue eines Briefes, der unter dem
Claim-Fenster tatsächlich **zugestellt** wurde, erzeugt eine bewusste
Doppelzustellung. Sie ist damit **gezählt** (Log-Zeile mit Akteur) und
**entschieden** (ein Mensch), nicht zufällig.

### 4.6 Warum nicht schlicht at-least-once

At-least-once (gar keine Wache) vermeidet die Doppelzustellung ebenfalls — und
wirft dafür Nutzerentscheidung 5 weg, der die Wache verlangte („mit born, nicht
nachträglich"). Eskauft wird dafür nur, dass jede Doppelzustellung *zufällig*
ist und ohne Spur bleibt. Die gewählte Form hält die Zusage, weil das
Claim-Fenster **größer als das gesamte Retry-Budget** ist: **jeder** Lauf, der
einen fremden Claim trifft, verweigert, und ein verweigernder Lauf endet
zwangsläufig in `failed_jobs` — er kann nicht mehr aus dem Rennen fallen, weil
sein Fenster abläuft, während er wartet. Damit bleibt die Zusage des Nutzerents
wahr: **jede vom Wächter verhinderte Doppelzustellung ist sichtbar.**

Was danach bleibt, ist nicht „Zufall", sondern drei benannte Formen: der
manuelle Requeue (§4.5 — gezählt und entschieden), ein **neuer** Dispatch mit
neuer `deliveryId` (der Resend-Pfad und `SendReminders` — definitionsgemäß eine
neue Zustellung, kein Duplikat desselben Auftrags) und ein Relay, das eine Mail
zweimal annimmt, ohne zu zustellen. Keine davon ist der stille Fall, den die
Wache verhindert.

## 5. Mandanten-Isolation der Dead-Letter-Queue

`failed_jobs.mandant_id` ist eine **echte Spalte** (nullable, indiziert, ohne FK):
ein toter Brief muss das Löschen seines Mandanten überleben, und die Zuordnung
aus dem `payload`-Blob zu gewinnen wäre zerbrechlich. Gefüllt wird sie einmalig
beim Dead-Lettern von `App\Queue\Failed\MandantAwareFailedJobProvider` (ein
`DatabaseUuidFailedJobProvider`-Subklassen), der `queue:failed`/`queue:retry`/
`queue:forget` unverändert lässt.

**Und sie wird von einer eigenen Migration angelegt — nicht nachträglich in
`0001_01_01_000002` editiert.** Das ist keine Formalie: eine bereits gelaufene
Migration führt Laravel **nie** erneut aus, eine solche Änderung erreicht also
**keine** bestehende Datenbank — gemessen als `SQLSTATE 42703: column
"mandant_id" does not exist`, während **CI grün** war, weil der Testlauf mit
`migrate:fresh` auf einer frischen DB arbeitet und die Behauptung von dort aus
**nicht falschfindbar** ist. Die Migration ist deshalb **konvergent** (zwei
`Schema::`-Guards): nach dem In-Place-Stand gibt es drei reale Ausgangslagen —
frisch, bereits migriert **mit** Spalte, bereits migriert **ohne** Spalte — und
ein unguarded `ALTER` bricht auf der zweiten mit `42701` ab, **ohne** die
Ledger-Zeile zu schreiben, was jeden späteren `migrate` dauerhaft scheitern
lässt. Der Wächter (`FailedJobsMandantIdMigrationTest`) prüft darum die
**Migrationslage**, nicht die Spalte: er baut den 42703-Zustand nach und führt
`artisan migrate` — plus eine Quell-Pin, weil ein reiner Verhaltenstest bei
„Spalte zusätzlich in 0001" **grün** bliebe und nur der Quellcheck die
Täterdatei benennt.

Die Mail-Skalare (`mandantId`, `mailableClass`, `recipient`) werden als
**Skalare am Job** mitgeführt und über `App\Support\QueuedMailPayload` gelesen —
ohne die Mail zu reanimieren. Deshalb ist die Mail im Job **vorab serialisiert**
(`mailablePayload`) statt als typisierte `Mailable`-Eigenschaft: eine typisierte
Eigenschaft würde beim Lesen des Payloads den ganzen Objektgraphen (inklusive
`SerializesModels`-Restore des Antragstellers) erzwingen, nur um eine Fehlerliste
zu rendern.

**Oberfläche** (`routes/api.php`, Gate `mails.dlq.manage`, nur `mandant_admin`,
`super_admin` per `Gate::before`):

| Route | Verhalten |
|---|---|
| `GET /api/admin/failed-mails` | `super_admin`: alle · `mandant_admin`: nur `mandant_id` = eigener Mandant |
| `POST /api/admin/failed-mails/{id}/requeue` | wie `queue:retry` (Payload zurück auf die Queue, `attempts` zurückgesetzt, `failed_jobs`-Zeile gelöscht, `Log::info` mit Akteur) |

Ein fremder Dead Letter ist **404**, kein 403 — dieselbe Form wie im Tenant-CRUD.
Warum ein eigenes Gate und nicht `accreditations.manage`: ein `team_admin` hält
das letzte, darf aber **keine** Verband-weite Fehlerliste lesen; sie trägt
Empfängeradressen. Die Antwort nennt kein fremdes `mandant_id`, weil die Query
bereits mandant-skaliert ist.

## 6. Aufbewahrung

Tote Briefe bleiben **unbegrenzt** (Nutzerentscheid 4). `queue:prune-failed` ist
**nicht** eingerichtet und gehört auch nicht in den Scheduler.

## 7. Wie die Suite das testet (`QUEUE_CONNECTION=sync`)

`phpunit.xml` pinnt `QUEUE_CONNECTION=sync` — die Suite sieht keinen Worker.
Das bleibt so (der Rest der Suite will synchron beobachtbares Verhalten), und
die Queue-Eigenschaften werden trotzdem echt gemessen:

| Test | Strategie |
|---|---|
| `QueuedMailTest` | `Queue::fake()` — beweist **Enqueue** (Job, `mandant_id`, Mailable-Klasse, Empfänger) und die weiterhin gültigen HTTP-Statuscodes |
| `QueuedMailAfterCommitTest` | echte `database`-Connection **innerhalb der Test-Transaktion**: in der Transaktion 0 `jobs`, nach dem Commit 1 — plus der Gegenlauf mit `after_commit => false` (dann sofort 1). Dass das unter `RefreshDatabase` überhaupt sichtbar ist, liegt an `Illuminate\Foundation\Testing\DatabaseTransactionsManager`: es blendet die umschließende Test-Transaktion aus (`skip(count($connectionsTransacting))`, `afterCommitCallbacksShouldBeExecuted() => $level === 1`), eine **echte** verschachtelte `DB::transaction()` feuert die After-Commit-Callbacks also wie in Produktion |
| `MailDeadLetterTest` | echter `database`-Queue + `Artisan::call('queue:work', ['--once' => true])` — ein toter Relay-Versuch landet real in `failed_jobs` **mit** `mandant_id`; daneben die API-Isolation über eingespielte Dead Letter |
| `SendMandantMailTest` | `handle()` direkt: der zweite Lauf **wirft** und sendet nicht; ein Claim, den ein toter Worker hinterlässt, lässt den nächsten Lauf ebenfalls **werfen** (nicht zurückkehren); der Claim ist **begrenzt** (Query-Log/`expiration`) und **atomar geschrieben** (`insert or ignore`/`on conflict do nothing`, kein `do update set`); `CLAIM_TTL_SECONDS > retry_after`; **`CLAIM_TTL_SECONDS > array_sum(backoff())`, als Arithmetik und als Zustand** (Neulauf bei exakt der Backoff-Summe ⇒ Verweigerung, keine zweite Mail); ein **abgelaufener** Claim ist unsichtbar, seine Zeile bleibt aber, bis sie gelesen wird — und weder ein Artisan-Befehl noch ein Scheduler-Eintrag räumt die `cache`-Tabelle auf; ein fehlgeschlagener Lauf gibt den Claim frei; ein gelöschter Mandant wird geloggt und claimt nichts |
| `MailDeadLetterTest` (neu) | **Ende zu Ende durch den echten Worker**: ein stehengebliebener Claim ⇒ nichts gesendet **und** eine Zeile in `failed_jobs` mit `mandant_id`, Empfänger und Begründung; ein manueller Requeue eines claimten Briefes ⇒ frische `deliveryId`, Mail im ersten Versuch zugestellt, `mailablePayload` unverändert |
| `QueuedMailAfterCommitTest` (ergänzt) | liest `config('queue.connections.database.after_commit')` **ohne es zu setzen** — die übrigen Tests dieser Klasse stellen die Flag selbst und würden einen Wechsel auf `false` in der Config nicht bemerken |
| `ScheduledTaskObservabilityTest` | Heartbeat/Fehlerpfad der geplanten Tasks: `schedule:run` mit einem real fehlschlagenden Task ⇒ `Log::error` im Scheduler-Prozess (und `schedule:run` endet trotzdem mit 0 — mitgemessen); beide Produktions-registrierungen tragen den Observer; `allocation:run` schreibt sein Ergebnis ins Anwendungslog und lässt einen Fehler **escapen** (⇒ Exit ≠ 0) |

Testklassen, die nur die **Fachlogik** fahren (Allocation, Badge, QR, …), faken
die Mail: `send()` ist jetzt ein Dispatch, der auf `sync` sofort läuft, und ein
echter Relay-Fehler würde den Test abbrechen. Klassen, die DB-Queries **zählen**,
nutzen `Queue::fake()` statt `Mail::fake()` — der serialisierte Mail-Job würde
beim Ausführen pro Zeile einen Restore auslösen und den N+1-Wächter drown.

## 8. Offen (bewusst, nicht vergessen)

- **Die `cache`-Tabelle wächst physisch** (Abschnitt 4.3). Jede **erfolgreich
  zugestellte** Mail lässt eine Claim-Zeile zurück, die niemand mehr liest, und
  die JWT-Blacklist liegt mit derselben Aufräum-Eigenschaft darin (Einträge
  ~7 Tage, `JwtBlacklistCacheFlushTest`). Ein abgelaufener Eintrag ist aus jedem
  Lesezugriff weg, aber nicht aus der Tabelle, und Laravel **13.33.0** hat
  keinen Befehl, der das räumt (gemessen, Abschnitt 4.3). Die Form wäre ein
  täglicher Task, der auf dem konfigurierten Store `expiration <= now` löscht —
  dieselbe Bedingung, die `DatabaseStore::many()` schon beim Lesen anwendet,
  also ohne semantische Änderung. **Bewusst nicht gebaut**, weil der
  Auth-Zustand in derselben Tabelle liegt und ein Fehler dort nicht „zu viel
  Speicher" bedeutet, sondern zurückgerufene Tokens; das ist eine Entscheidung,
  keine Kleinigkeit. Wer sie trifft, muss `test_an_expired_claim_is_invisible_but_its_row_survives_until_something_reads_it`
  mitnehmen — er steht gegen genau diese Entscheidung.
- **UI** für die Dead-Letter-Liste und den Requeue (Strom B). Die API ist da;
  die Oberfläche ist der zweite Ausgang der Zusage „no mail should be lost".
- **Requeue-Historie** (`requeued_count`, letzter Requeue durch wen/wann) ist
  nicht persistiert — nur `Log::info` mit Akteur. Eine eigene Spalte auf
  `failed_jobs` wäre die Form; sie war nicht Teil des Auftrags.
- **Paginierung der Dead-Letter-Liste.** `FailedMailController::index()` macht
  `->get()` über die **ganze** Tabelle und filtert danach in PHP; `failed_jobs`
  wächst per Entscheidung unbegrenzt (Abschnitt 6), die mandant-skalierten
  Abfragen sind also mit der Zeit unbrauchbar. Die Form ist eine
  `per_page`/`cursor`-Parameterisierung mit Resource-Collection-Metadaten; sie
  ist **nicht Teil dieses Auftrags**, aber sie ist der Punkt, an dem diese
  Oberfläche zuerst bricht.
- **Betrieb** (`queue:work`, `schedule:run`, Healthcheck): siehe
  `deployment/backend-supervisor.sh` (Strom C, `8c3301a`).

## 9. Der Scheduler beobachtet sich selbst (gemessen, nicht behauptet)

`Schedule::command()` registriert einen Task, der als **eigener Prozess**
läuft. Zwei Folgen, beide gemessen (Laravel 13.33.0, nicht vermutet):

| Beobachtung | Quelle | Folge |
|---|---|---|
| `Event::execute()` ruft `Process::run()` mit `fn () => true` als Ausgabe-Handler | `vendor/…/Scheduling/Event.php:213-223` | die **gesamte** Ausgabe des Tasks wird **verworfen** — auch `RunAllocations:…` Fortschrittszeilen |
| `ScheduleRunCommand::runEvent()` wirft, `handle()` fängt und kehrt normal zurück | `vendor/…/Scheduling/ScheduleRunCommand.php:215-224` | `schedule:run` endet mit **Exit 0**, der Task wird als `DONE` gemeldet |

Damit war der `if ! php artisan schedule:run`-Zweig in
`deployment/backend-supervisor.sh:169` für einen kaputten Task **tödlich** — er
konnte nie feuern. Zwei Eingriffe, beide gemessen testbar:

| Wo | Was |
|---|---|
| `routes/console.php` | `ScheduledTaskObserver::watch()` hängt `onSuccess`/`onFailure` an beide Tasks. Das sind `then()`-Callbacks, die im **Scheduler-Prozess** laufen und über den Exit-Code des Kindes entscheiden — sie brauchen weder die Kind-Ausgabe noch ein ungleich nullendes `schedule:run`. |
| `RunAllocations`, `SendReminders` | `Log::info` mit dem Ergebnis (Zähler, Dauer) bzw. `Log::error` mit Ausnahme + Rethrow. Der Observer sagt **DASS** sie liefen und ob sie erfolgreich waren; nur der Command weiß, **was** er getan hat. |

Der Erfolgs-Heartbeat ist kein Luxus: ohne ihn sind „der stündliche Lauf ist
kaputt" und „der Scheduler läuft seit drei Tagen nicht" dieselbe Stille — und
beide sind mit einem grünen Container vereinbar.

