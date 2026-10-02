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

## 4. Idempotenz-Wache gegen Worker-Crash-vor-Ack

Die eine Doppelzustellung, die eine Queue **neu** einführt: der Worker sendet
die Mail, stirbt vor dem Ack, und der Job läuft nach `retry_after` erneut.

Die Wache ist ein atomarer Claim — `Cache::add('mail-delivery:{deliveryId}', true)`,
das Test-and-set-Äquivalent zum bedingten `where(...)` aus
`AllocationRules::markStatus()`. Der zweite Lauf findet den Claim und sendet
**nicht**.

- `deliveryId` ist **stabil über Retries desselben Jobs** (er steckt im
  Payload) und **frisch bei jedem neuen Dispatch** — also auch bei jedem
  manuellen Requeue. Genau diese Granularität braucht die Wache.
- Bei einer **Ausnahme** wird der Claim **freigegeben**, bevor sie weiterläuft:
  ein vorübergehend totes Relay wird also normal wiederholt, und nur ein Lauf, der
  erfolgreich **zurückkehrte**, lässt einen Claim stehen.
- Ablage ist der Default-Cache-Store, in Produktion `database`
  (`CACHE_STORE=database`, derselbe durable Store wie die JWT-Blackliste). Der
  Deploy leert ihn nicht (`deployment/entrypoint.sh`).

## 5. Mandanten-Isolation der Dead-Letter-Queue

`failed_jobs.mandant_id` ist eine **echte Spalte** (nullable, indiziert, ohne FK):
ein toter Brief muss das Löschen seines Mandanten überleben, und die Zuordnung
aus dem `payload`-Blob zu gewinnen wäre zerbrechlich. Gefüllt wird sie einmalig
beim Dead-Lettern von `App\Queue\Failed\MandantAwareFailedJobProvider` (ein
`DatabaseUuidFailedJobProvider`-Subklassen), der `queue:failed`/`queue:retry`/
`queue:forget` unverändert lässt.

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
| `SendMandantMailTest` | `handle()` direkt zweimal aufrufen: der zweite Lauf sendet nicht (Mutationsnachweis); ein fehlgeschlagener Lauf gibt den Claim frei |

Testklassen, die nur die **Fachlogik** fahren (Allocation, Badge, QR, …), faken
die Mail: `send()` ist jetzt ein Dispatch, der auf `sync` sofort läuft, und ein
echter Relay-Fehler würde den Test abbrechen. Klassen, die DB-Queries **zählen**,
nutzen `Queue::fake()` statt `Mail::fake()` — der serialisierte Mail-Job würde
beim Ausführen pro Zeile einen Restore auslösen und den N+1-Wächter drown.

## 8. Offen (bewusst, nicht vergessen)

- **UI** für die Dead-Letter-Liste und den Requeue (Strom B). Die API ist da;
  die Oberfläche ist der zweite Ausgang der Zusage „no mail should be lost".
- **Requeue-Historie** (`requeued_count`, letzter Requeue durch wen/wann) ist
  nicht persistiert — nur `Log::info` mit Akteur. Eine eigene Spalte auf
  `failed_jobs` wäre die Form; sie war nicht Teil des Auftrags.
- **Betrieb** (`queue:work`, `schedule:run`, Healthcheck): siehe
  `deployment/backend-supervisor.sh` (Strom C, `8c3301a`).