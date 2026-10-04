# Allocation-Engine (P3c)

Kernreferenz der Freigabe-Logik (**D8**): wer erhält einen Quota-Slot — und wer
nicht. Die Engine ist **server-autoritativ** (Freigabe-Entscheidung nie im
Client). Umsetzung im Ist:
`backend/app/Services/AllocationService.php`,
`backend/app/Services/AllocationResult.php`,
`backend/app/Services/AllocationRules.php` (gemeinsame Regeln),
`backend/app/Services/SubAllocationService.php` (P3d/D9),
`backend/app/Http/Controllers/Api/WalletController.php` (Sub-Pass-Guard, R-D5),
`backend/app/Console/Commands/RunAllocations.php`,
`backend/app/Http/Controllers/Api/Admin/AccreditationController.php::allocate`,
Route `accreditations.allocate` (`backend/routes/api.php`),
Schedule (`backend/routes/console.php`).

Tests: `backend/tests/Feature/AllocationTest.php` (Kernregeln),
`SubAccreditationTest.php` (P3d),
`AdminApprovalTest.php` (Einzelaktionen),
`AllocationAtomicityTest.php` (R-D4/WP-3-a+b),
`SubAccreditationRevocationTest.php` (R-D5/WP-3-c).

## SOLL — Kernregeln

1. **Deterministische Reihenfolge (keine Zufälligkeit):**
   1. **VIP zuerst** (`priority = true`) vor allen anderen Anträgen.
   2. Innerhalb derselben Priority **FCFS** (`created_at ASC`).
   3. Tie-Break **`id ASC`** (stabiles, eindeutiges Gesamt-Ordering).
   Sortierung in `eligibleRequested()`: `orderByDesc('priority')` →
   `orderBy('created_at')` → `orderBy('id')`.
2. **Blacklist nie freigeben:** Mandant-scoped Blacklist-Einträge
   (`blacklists.mandant_id` = Mandant der Akkreditierung). Ein User gilt als
   gesperrt, wenn die Blacklist seine **E-Mail exakt** (case-insensitiv,
   getrimmt) **oder seine Domäne** (alles nach `@`, lowercase, getrimmt)
   enthält. User ohne E-Mail → nie gesperrt. Blacklisted User werden **nie**
   auf `approved` gesetzt.
3. **Quota wird nie überschritten:** Es werden maximal `quota` Anträge auf
   `approved` gesetzt (bereits `approved` zählen mit). Der Check passiert erst
   bei der Allokation, nicht beim Antrag. Die Zusage gilt **nur**, weil Quota-
   Lesen und Status-Write in **einer** Transaktion unter **Row-Lock** auf der
   Akkreditierungs-Zeile laufen — siehe „Atomarität (R-D4)".
4. **Überzeichnung → `denied` „Quota erschöpft":** Im Auto-Modus
   (`approveAllEligible`) werden überzählige `requested`-Anträge nach
   Ausschöpfen der Quota mit `reason = 'Quota erschöpft'` abgelehnt.
5. **Blacklist im Auto-Modus → `denied` „Blacklist":** In `approveAllEligible`
   werden Blacklist-Treffer mit `reason = 'Blacklist'` abgelehnt.
6. **Manuell „erste X" lässt den Rest `requested`:** `approveSelection` setzt
   höchstens `min(limit, quota − approved)` Anträge auf `approved`;
   Blacklist-Treffer bleiben `requested` (der Admin kann die Sperre später
   aufheben), alle übrigen bleiben ebenfalls `requested`.
7. **Idempotent:** Nur Anträge im Status `requested` sind Kandidaten; jeder
   Statuswechsel geht über diese Engine. Ein zweiter Lauf findet keine neuen
   Kandidaten (in `approveSelection` verbleibende Blacklist-Treffer werden
   erneut übersprungen — das Ergebnis ist stabil). Automatische Re-Runs sind
   dadurch unschädlich.

## Atomarität (R-D4 — Review 2026-09-26)

Kernregel 3 („Quota wird nie überschritten") war bis zum 2026-09-26 **nicht
haltbar**: `approvedCount()` war ein `SELECT count(*)` gegen `quota`, der
Status-Write ein **eigenständig autocommit-tetes** Statement, und `app/` hatte
weder `DB::transaction` in der Engine noch `lockForUpdate()` irgendwo.

### Das Szenario, das die Regel gebrochen hat

`quota = 2`, 0 approved, `requested = [A, B, C]`:

1. T1 `POST /api/admin/accreditations/{id}/allocate {"mode":"all"}` liest
   `[A, B, C]`, `approved = 0` → plant `approve [A, B]`, `deny_quota [C]`.
2. T2 (parallel) `PUT /api/admin/applications/C {"status":"approved"}` liest
   ebenfalls `approved = 0` → genehmigt C.
3. T1s `markDenied([C])` filtert `where('status','requested')` → trifft **0
   Zeilen**.

Ergebnis: **3 `approved` bei `quota = 2`**, die Antwort meldet
`{"approved":2,"denied":1}` (Zähler, die nicht zum DB-Zustand passen), und C
wird nie benachrichtigt — `dispatchDeniedMails` filtert erneut auf
`status = 'denied'` und versendet nichts. Der `where('status','requested')`-Guard
verhindert nur die *doppelte* Genehmigung derselben Zeile; gegen ein
konkurrierendes Write zwischen Plan und Write schützt er nicht. `throttle:admin`
(300/min) serialisiert nichts.

### Die Entscheidung

`DB::transaction` + `lockForUpdate()` auf der Zeile, die die Quota besitzt —
für **alle** Status-Writes der Engine:

| Methode | gesperrte Zeile |
|---|---|
| `AllocationService::approveSelection()` | `accreditations` |
| `AllocationService::approveAllEligible()` | `accreditations` |
| `AllocationService::approveApplication()` | `accreditations` |
| `AllocationService::denyApplication()` | `accreditations` (+ Kaskade, s. R-D5) |
| `SubAllocationService::approveSelection()` | `sub_accreditations` |
| `SubAllocationService::approveAllEligible()` | `sub_accreditations` |
| `SubAllocationService::approveSubApplication()` | `sub_accreditations` |
| `SubAllocationService::denySubApplication()` | `sub_accreditations` |

Daraus folgt:

- **Ein Lock, alle Schreiber.** Der zweite Schreiber derselben Akkreditierung
  wartet und liest danach den committeten Zustand des ersten.
- **Die Quota kommt aus der DB, nicht aus dem Modell des Aufrufers.** Die
  gesperrte Zeile ist die maßgebliche Quelle; ein Modell, das vor einer
  Quota-Änderung geladen wurde (u. a. Route-Model-Binding vor einem
  konkurrierenden Admin-Update), kann die Runde nicht mehr verengen.
- **Reihenfolge invariant.** Plan → Write passiert ohne Unterbrechung; ein
  `deny`/`approve`, das zwischen Plan und Write landen würde, ist durch denselben
  Lock ausgeschlossen.
- **Ein Run = eine Transaktion.** `markApproved` → `issueQrTokens` →
  `markDenied(Quota)` → `markDenied(Blacklist)` liegen in **einer** Transaktion.
  Schlägt die Deny-Hälfte nach der bereits geschriebenen Approve-Hälfte fehl,
  wird **alles** zurückgerollt. Vorher blieb auf einer **manuellen**
  Akkreditierung (`auto_approve = false`) der Überschuss dauerhaft `requested` —
  `allocation:run` überspringt sie, der Antragsteller sah „pending" ohne Ende
  und wurde nie informiert (WP-3-b).
- **Mails nach `commit()`.** `dispatchApprovedMails` / `dispatchDeniedMails`
  laufen außerhalb der Transaktion und stellen nur einen `SendMandantMail`-Job
  in die Queue. Der Auftrag wird dadurch **im selben Commit** geschrieben wie
  die Entscheidung (`config/queue.php` → `after_commit => true`), eine
  zurückgerollte Entscheidung sendet also nichts, und ein Versandfehler kann
  niemals eine Entscheidung zurückrollen. **Der alte Satz „(`MandantMailerService`
  schluckt Versandfehler ohnehin)" ist überholt**: der Service verschluckt
  nichts mehr, der Job wiederholt mit Backoff und endet im Dead Letter —
  siehe `features/mail-delivery.md`.

### Was SQLite in der Test-Suite **nicht** beweisen kann

⚠️ **Die Testsuite läuft auf SQLite `:memory:` und `SQLiteGrammar::compileLock()`
gibt `''` zurück** — ein `SELECT … FOR UPDATE` wird dort stillschweigend zu einem
normalen `SELECT`. Zusätzlich gibt es in diesem Setup prozessweit nur *eine*
In-Memory-DB, ein echter Zwei-Connection-Race ist gar nicht stagingbar. Eine
naive funktionale Testaussage „Quota wird auch im Race nie überschritten" würde
daher **mit und ohne** Lock grün sein und nichts beweisen. Der Testplan ist
deshalb dreistufig und sagt die Lücke explizit:

1. **Shape (engine-verifiziert):** `tests/Feature/AllocationAtomicityTest.php`
   hängt einen `LockProbeGrammar` (ein `SQLiteGrammar`-Subklone, der
   `compileLock()` aufzeichnet) an die Connection und belegt, dass der
   Produktionspfad `lockForUpdate()` wirklich auf `accreditations` bzw.
   `sub_accreditations` anfragt. Derselbe Test kompiliert denselben Builder mit
   `PostgresGrammar` und belegt das echte `for update`.
2. **Transaktion (engine-verifiziert):** über `DB::listen` +
   `transactionLevel()` (relativ zum von `RefreshDatabase` gesetzten Niveau)
   wird belegt, dass Quota-Lesen und Status-Writes auf Tiefe > 0 laufen und die
   Benachrichtigungs-Reads auf Tiefe 0.
3. **Atomizität (engine-verifiziert, echte Alles-oder-nichts-Semantik):** ein
   per `DB::listen` injizierter Fehler in der Deny- bzw. Token-Phase belegt den
   Rollback der Approve-Hälfte.

**Nicht** engine-verifiziert und damit Aufgabe des
**Postgres-Portabilitäts-Gates** (Go-Live-Plan, Phase A — „Integration-/E2E-Lauf
gegen echte Postgres (nicht nur SQLite-Testsuite), §2-Regel verifiziert"):
zwei **getrennte** Verbindungen im oben beschriebenen Szenario; T2 muss auf dem
Row-Lock blockieren, danach `approved = 2` lesen und mit 422 „Quota erschöpft"
antworten, und die Response-Zähler müssen zum DB-Zustand passen.

## R-D5 — Sub-Akkreditierung folgt der Haupt-Akkreditierung (Review 2026-09-26)

**D9** („Park-/Sitzkarte nur bei Haupt-Akkreditierung") war nur an **einer** Stelle
erzwungen: `SubAccreditationController::apply` verlangt einen `approved`
Haupt-Antrag. `approveSubApplication` / `denySubApplication` sahen den Eltern-Antrag
nie an, und `denyApplication` durfte eine `approved` Zeile entziehen, ohne
`sub_applications` zu berühren. Szenario: User ist auf Akkreditierung X
genehmigt und hat eine genehmigte `park`-Sub-Akkreditierung; der Admin entzieht
X → die Sub-Zeile bleibt `approved` und
`GET /api/sub-applications/{id}/wallet` liefert weiterhin einen gültigen
`.pkpass`.

Die Regel wird jetzt an drei Stellen erzwungen:

1. **Apply** (unverändert): Haupt-Antrag muss `approved` sein.
2. **Kaskade:** `AllocationService::denyApplication()` setzt **alle
   `approved`-Sub-Zeilen, die auf dem entzogenen Haupt-Antrag hängen, auf
   `denied`** — mit **eigenem** `reason`
   (`AllocationService::REASON_PARENT_REVOKED` = `Haupt-Akkreditierung
   entzogen`), **atomar** mit dem Status-Write des Haupt-Antrags und unter
   demselben Row-Lock. Bewusst eng gefasst:
   - nur `approved` kaskadiert. Ein `requested`-Antrag ist ein wartender
     Antragsteller — ihn zu ablehnen ist eine Admin-Entscheidung, und der Admin
     kann den Haupt-Antrag vorher wieder genehmigen. Ein `denied`-Antrag ist
     final und behält seinen Grund.
   - Der Write läuft **nicht** über `AllocationRules::markDenied()` (das ist der
     `requested → denied`-Pfad des Allokations-Plans), sondern als eigener
     `approved → denied`-Übergang.
   - Für die kaskadierten Zeilen wird **keine** Mail versendet — einzige
     schweigende Sub-Status-Schreibung (Begründung im Abschnitt
     „Sub-Statuswechsel-Benachrichtigung", Ausnahmen-Aufzählung).
3. **Wallet-Guard (Defense in Depth):** `WalletController` stellt keinen
   Sub-Pass aus, solange der Haupt-Antrag nicht `approved` ist.

### Statuscode der Wallet-Antwort: **410 Gone** (bewusst gewählt)

`GET /api/sub-applications/{id}/wallet` antwortet **410** mit
`"The main accreditation was withdrawn, this wallet pass is no longer valid."`,

- wenn der Haupt-Antrag nicht `approved` ist — **vor** dem Status-Guard des
  Sub-Antrags. Dadurch ist der Widerrufs-Fall unabhängig davon, ob die Kaskade
  schon gelaufen ist oder nicht immer 410 und nie ein „422, freigeben geht
  nicht".

**Warum 410 und nicht 404:** die Sub-Zeile existiert, gehört dem Aufrufer und
wird in `GET /api/sub-applications` gelistet — ein 404 wäre eine Lüge und würde
auch nichts verbergen, was der bestehende 422-Zweig nicht schon sagt. 410 ist der
ehrliche Status für eine Ressource, die es gab und die es nicht mehr gibt. Die
Prüfung auf Eigentum/Mandant passiert weiterhin **zuerst**: eine fremde Zeile
bleibt 404 und wird nie zum Existenzorakel (410). Bei `approved` Haupt- und
`requested`/`denied` Sub-Antrag bleibt der alte 422-Zweig unverändert.

## Sub-Statuswechsel-Benachrichtigung (WP-3-d) — umgesetzt, Button offen

⚠️ **Geschlossen 2026-10-04 (Versand + `resend`-Route); offen: Frontend-Button.**

`AllocationService` versendet bei **jedem** Statuswechsel eine Mail
(`ApplicationApprovedMail` / `ApplicationDeniedMail`), und für Haupt-Anträge
existiert `AdminApplicationController::resend`
(`POST /api/admin/applications/{id}/resend`). Für Sub-Anträge galt beides
**nicht** — der erste Teil gilt nicht mehr:

- ✅ **Versand umgesetzt:** `SubApplicationApprovedMail` /
  `SubApplicationDeniedMail` samt Blade-Views (`resources/views/mail/sub-application-{approved,denied}.blade.php`),
  Basis `AbstractSubApplicationMail` (Empfänger `sub_application.user`, Kontext
  aus `subAccreditation.accreditation`, typabhängige Subjects, Pass-Anhang nur
  bei `approved` mit Fail-safe). Dispatch aus allen vier manuellen Pfaden
  (`approveSelection` / `approveAllEligible` / `approveSubApplication` /
  `denySubApplication`) **und** dem Auto-Pfad (`runAutoSubAllocations`) über
  `MandantMailerService::send()` → dieselbe Queue (Claim, Retry → DLQ,
  `failed_jobs.mandant_id`). Mandant: `subAccreditation.accreditation.mandant`
  (Entscheidungs-Mandant, nicht Antrags-Mandant). **Abweichung vom Folgetask-
  Entwurf mit Begründung:** Dispatch **innerhalb** der Transaktion (nicht nach
  dem Commit wie die Haupt-Engine) — sonst verlöre ein Prozess-Tod zwischen
  Commit und `jobs`-Insert die Mail ohne jede Spur; `after_commit` gilt
  unverändert. Tests: `SubAllocationMailTest` (24). Ausgenommen mit Begründung:
  `cascadeRevokedSubApplications()` bleibt still (Haupt-Antrag-Mail geht an
  dieselbe Person).
- ✅ **Umgesetzt 2026-10-04:** `POST /api/admin/sub-applications/{id}/resend`
  (Gates wie Haupt-Antrag — Mandant → 404, fremdes Team → 403, `requested` ohne
  `reason` → 422; kein QR-Token-Rebuild: ein Resend dupliziert die Mail, statt
  den Pass zu entwerten; Tests: `AdminSubApplicationResendTest`, 13).
  Frontend-Button weiter offen (eigener Task, inkl. i18n DE+EN).

**Verbleibende Lücke (nur noch UI):** Der Endpunkt existiert (`POST /api/admin/sub-applications/{id}/resend`),
aber kein Button ruft ihn auf — wer eine Sub-Freigabe erneut versenden will, muss die Route direkt aufrufen
(Haupt-Anträge haben den Button in `ApprovalsPage`). Der Erstversand erreicht den Antragsteller per Mail
(gegebenenfalls über die DLQ sichtbar).

**Warum 2026-09-26 nicht umgesetzt (historisch):** die Umsetzung brauchte
`SubApplicationApprovedMail` / `SubApplicationDeniedMail` samt Blade-Views
(`resources/views/mail/**`), eine `resend`-Action in
`AdminSubApplicationController` und eine Route in `routes/api.php` — sämtlich
außerhalb des Datei-Scopes dieses Work-Pakets (WP-3). Ein halb gebautes
Mail-Format ohne Resend-Weg wäre schlechter als eine dokumentierte Lücke: Es
würde in `features/` als erfüllt erscheinen, während der Antragsteller
weiterhin nichts bekommt. **Beide Teile sind seit 2026-10-04 gebaut** (siehe
oben); der offene Rest ist der Frontend-Button.

**Was ein Folgetask noch tun muss:** Button für den Sub-`resend` in der Admin-UI
(mit i18n DE **und** EN — `check:i18n` bricht sonst), der `POST
/api/admin/sub-applications/{id}/resend` aufruft und die Backend-Antwort zeigt
(statt eigenem Lingui-Text — Lehre aus Strom B, TODO 4). Der Kaskaden-Grund
`REASON_PARENT_REVOKED` ist dabei als Deny-`reason` bereits persistiert und
kann direkt im Mailable ausgegeben werden.

## Status-Semantik

- Status-Set: **`requested | approved | denied | blacklisted`**.
- Die Engine schreibt ausschließlich **`requested → approved`** und
  **`requested → denied`** (mit `reason`). Denied ist final, `approved` ist
  final.
- Einzige Ausnahme (R-D5): der Entzug eines `approved`-Haupt-Antrags schreibt
  **`approved → denied`** auf die Sub-Anträge, die darauf aufsetzen, mit
  `reason = 'Haupt-Akkreditierung entzogen'`. Das ist keine Allokation, sondern
  der Wegfall der Grundlage; der Sub-Antrag ist danach ebenfalls `denied`, also
  final.
- **`blacklisted` wird von der Engine nie gesetzt** — der Status ist für die
  **Blacklist-Verwaltung in P3e** reserviert (Block auf Mandant-Ebene).

### Bulk-Reanimations-Limitation (BE-R8, Design-Entscheidung)

> ⚠️ **Limitation:** Bulk-Läufe können `denied`-Anträge **nicht reanimieren**.

Alle Bulk-Pfade (`approveSelection`, `approveAllEligible` — manuell wie
automatisch) lesen ihre Kandidaten ausschließlich über `eligibleRequested()`.
Diese beiden Queries sind der **Beleg** — sie sind der einzige Ort, an dem ein
Status-Filter auf die Kandidatenmenge wirkt:

`AllocationService::eligibleRequested()` (`backend/app/Services/AllocationService.php:476`):

```php
return AllocationRules::orderEligible(
    Application::query()
        ->where('accreditation_id', $accreditation->id)
        ->where('status', 'requested')
        ->with('user:id,email'),
)->get();
```

`SubAllocationService::eligibleRequested()` (`backend/app/Services/SubAllocationService.php:315`):

```php
return AllocationRules::orderEligible(
    SubApplication::query()
        ->where('sub_accreditation_id', $sub->id)
        ->where('status', 'requested')
        ->whereHas('application', fn (Builder $query) => $query->where('status', 'approved'))
        ->with('user:id,email'),
)->get();
```

Die Zeile `->where('status', 'requested')` ist damit wörtlich die Bedingung,
die `denied` (und `approved`) aus der Kandidatenmenge ausschließt. Beim
Sub-Antrag kommt der D9-Filter `whereHas('application', … status = 'approved')`
dazu: ein `denied`-Sub-Antrag ist doppelt ausgeschlossen, zusätzlich darf die
Haupt-Akkreditierung nie `approved` sein.

Ein zweiter, unabhängiger Guard liegt im Write-Back: `AllocationRules::markStatus()`
(`AllocationRules.php:195`) schreibt nur über `->where('status', 'requested')`.
Ein Plan, der aus einem veralteten Read berechnet wurde, kann eine inzwischen
veränderte Zeile also selbst im Erfolgsfall nicht überschreiben (Idempotenz,
Kernregel 7) — die Bulk-Läufe sind doppelt fail-closed.

**Konsequenzen**

- Ein `denied`-Antrag bleibt durch jeden weiteren Bulk-Run — auch nach
  Quota-Erhöhung oder Blacklist-Löschung — **dauerhaft `denied`**. Das gilt
  ausdrücklich auch für Anträge mit VIP-Priorität: `priority = true` schützt
  nicht vor dem Verbleib in `denied`. VIP ist **nur eine Sortiervorgabe**
  innerhalb der `requested`-Kandidatenmenge — `AllocationRules::orderEligible()`
  ist `orderByDesc('priority')->orderBy('created_at')->orderBy('id')`, und
  `setPriority()` ist ein reines Feld-Update „no status change, no guards"
  (`AllocationService.php:422`). VIP kann nie einen Status überschreiben.
- Ebenso sind `approved`-/`blacklisted`-Zeilen nie Bulk-Kandidaten.
- **`blacklisted` ist kein Query-Filter**, sondern eine Plan-Entscheidung zur
  Laufzeit: `AllocationRules::isBlacklisted()` prüft die mandanten-skalierten
  `blacklist`-Zeilen (E-Mail/Domain, case-insensitive) gegen den geladenen
  User. In `approveAllEligible` landen Treffer in `deny_blacklist` → `denied`
  mit `REASON_BLACKLIST`; in `approveSelection` werden sie nur **übersprungen**
  und bleiben `requested` (Zähler `skipped_blacklist`, siehe `AllocationResult`).
  Die Engine **setzt den Status `blacklisted` nie** — er ist der Blacklist-
  Verwaltung vorbehalten (siehe „Status-Semantik"). Ein Bulk-Run kann eine
  Zeile also wegen der Blacklist ablehnen, aber nie wegen ihr auf `denied`
  fixieren: nach dem Löschen des Blacklist-Eintrags ist die Zeile `denied` und
  bleibt es — dieselbe Limitation wie oben.
- Für Sub-Anträge kommt der D9-Filter hinzu: `requested` auf einem
  Haupt-Antrag, der selbst `requested`/`denied` ist, ist **kein** Kandidat und
  bleibt bewusst `requested` — der Admin kann den Haupt-Antrag erst
  freigeben, dann nimmt der nächste Lauf die Sub-Zeile mit.

**Warum das eine Design-Entscheidung ist, kein Bug.** Eine bewusste
Ablehnung soll nicht durch einen Sammellauf still überschrieben werden. Ein
Bulk-Run ist ein Massenwerkzeug: er entscheidet nach einer deterministischen
Regel (Quota, Priorität, Blacklist) über *alles, was gerade bewerbar ist* —
alles andere wäre ein stilles Undo der Admin-Entscheidung von gestern, ohne
Audit-Spur. Der Status `denied` ist die dokumentierte Endschicht (siehe
„Status-Semantik": „Denied ist final, `approved` ist final"). Die einzige
Stelle, die das bewusst durchbricht, ist der **Einzelfall mit explizitem
Admin-Akt**, und das ist auch genau der einzige dokumentierte Weg zurück.

**Wie man sie bewusst umgeht** (kein Bug-Workaround, der vorgesehene Weg)

- Haupt-Antrag: `PUT /api/admin/applications/{application}` mit
  `status=approved` → `AllocationService::approveApplication()`. Der Guard
  lautet wörtlich `if (! in_array($application->status, ['requested', 'denied'], true))`
  (`AllocationService.php:239`) — `denied` ist also ausdrücklich ein gültiger
  Ausgangsstatus. Blacklist-Guard und Quota-Check laufen dort erneut, und das
  Freigeben setzt den `reason` auf `null`.
- Sub-Antrag: `PUT /api/admin/sub-applications/{subApplication}` mit
  `status=approved` → `SubAllocationService::approveSubApplication()`, gleiche
  Status-Lehre (`in_array($subApplication->status, ['requested', 'denied'], true)`,
  `SubAllocationService.php:177`) plus der D9-Guard: der Haupt-Antrag muss
  `approved` sein, sonst 422 `REASON_PARENT_NOT_APPROVED`.
- Einen Weg **zurück auf `requested` gibt es nicht** — weder über die API
  (`update()` validiert `status` nur gegen `Rule::in(['approved', 'denied'])`)
  noch in der Engine. Das ist gewollt: der Reanimationsweg ist die
  Einzelgenehmigung, nicht das Zurücksetzen auf bewerbar.
- Wer eine ganze Gruppe reaktivieren will, muss die Zeile im Admin-UI
  einzeln freigeben — die Freigabe-Tabelle (`ApprovalsPage.tsx`) hat **keine**
  Mehrfachauswahl für Reanimationen; die Checkbox je Zeile schaltet nur den
  VIP-Toggle (`setPriority`, status-neutral). Die einzigen Massenknöpfe dort
  sind `approveSelection` und `approveAllEligible` — und die sind per
  Konstruktion die Wege, die `denied` nicht sehen. Das gehört hierher, damit
  niemand die Einschränkung später als fehlendes Feature im UI meldet.

## Service-API

### `App\Services\AllocationService`

| Methode | Verhalten |
|---|---|
| `approveSelection(Accreditation $a, int $limit): AllocationResult` | Manuell „erste X". `limit <= 0` oder kein Restplatz → No-op (`AllocationResult::none()`). Blacklist-Treffer bleiben `requested`. |
| `approveAllEligible(Accreditation $a): AllocationResult` | Alle freigeben bis zur Quota (manuell `mode=all` **und** Auto-Modus). Überschuss → `denied` „Quota erschöpft", Blacklist → `denied` „Blacklist". |
| `approveApplication(Application $a): Application` | Einzelgenehmigung (P3e). Guards: Blacklist → 422, Status nicht `requested`/`denied` → 422, `approved >= quota` → 422 „Quota erschöpft". Läuft unter Row-Lock (R-D4). |
| `denyApplication(Application $a, string $reason): Application` | Einzelablehnung/Widerruf (P3e). `reason` Pflicht, Ausgangsstatus `requested`/`approved`. Bei `approved → denied` kaskadiert auf `approved`-Sub-Anträge (R-D5). Läuft unter Row-Lock (R-D4). |
| `setPriority(Application $a, bool $priority): Application` | VIP-Flag, reines Feld-Update ohne Statuswechsel und ohne Guards. |
| `runAutoAllocations(?DateTimeInterface $now = null): array` | Automatischer Trigger: pro verarbeiteter Akkreditierung `[id => ['approved' => n, 'denied' => m]]`. Nur wenn Frist abgelaufen (siehe Trigger). |
| `REASON_PARENT_REVOKED` (Konstante) | `'Haupt-Akkreditierung entzogen'` — der `reason`, den der R-D5-Kaskaden-Write auf die Sub-Anträge setzt. Bewusst **nicht** der Admin-Grund des Haupt-Antrags: der Antragsteller der Park-/Sitzkarte muss lesen können, warum *seine* Zeile gefallen ist. |

### `App\Services\SubAllocationService` (P3d, D9)

Dieselben Kernregeln über `AllocationRules`; gesperrt wird die
`sub_accreditations`-Zeile (siehe „Atomarität (R-D4)"). `approveSelection`,
`approveAllEligible`, `approveSubApplication`, `denySubApplication`,
`setPriority`, `runAutoSubAllocations`. Mailer: `MandantMailerService` (injiziert) — Versand aus allen Pfaden außer Kaskade (siehe „Sub-Statuswechsel-Benachrichtigung").

### `App\Services\AllocationResult` (JSON: `{approved, denied, skipped_blacklist}`)

| Feld | Semantik |
|---|---|
| `approved` | Anzahl neu auf `approved` gesetzter Anträge |
| `denied` | Anzahl neu auf `denied` gesetzter Anträge (Quota-Überschuss **und** Blacklist) |
| `skipped_blacklist` | Blacklist-betroffene Anträge — **modusabhängig** (P3c-F1): **Selection** → übersprungen, bleiben `requested` (zählen nicht in `denied`); **approveAll** → `denied` „Blacklist" (zählen zusätzlich in `denied`). |

Für den **P3e-UI-Zähler** gilt: `skipped_blacklist` darf nicht mit „abgelehnt"
gleichgesetzt werden — im manuellen Modus ist es „übersprungen/weiter offen".

## Trigger

### Manuell — `POST /api/admin/accreditations/{accreditation}/allocate`

- **Auth/Gate:** `can:accreditations.manage` — `super_admin` (global),
  `mandant_admin`, `team_admin` (`config/permissions.php`).
- **Scoping:** Akkreditierung muss im aktuellen Mandanten liegen
  (`assertMandantScope`); `team_admin` nur auf eigene Teams
  (`assertOwnership`/`resolveTeamId`, fremde/nicht zugehörige → 403).
- **Payload:** `mode` (`all` \| `first`, required). Bei `mode=first` zusätzlich
  `limit` (required, integer, `min:1`); bei `mode=all` wird `limit` ignoriert.
- **Dispatch:** `mode=all` → `approveAllEligible`, `mode=first` →
  `approveSelection($accreditation, $limit)`.
- **Antwort:** `{data: {approved, denied, skipped_blacklist}}`.
- Der manuelle Trigger kann **jederzeit** laufen — das Frist-Fenster wird beim
  **Antrag** geprüft (P3b), nicht hier.

### Automatisch — `allocation:run` (stündlich)

- Command `App\Console\Commands\RunAllocations`, registriert in
  `routes/console.php`: `Schedule::command('allocation:run')->hourly()->withoutOverlapping()`.
  Läuft in **jeder** Umgebung (auch dev — eine abgelaufene Akkreditierung muss
  unabhängig von der Umgebung alloziert werden).
- **Bedingungen** (alle): `active = true`, `auto_approve = true`,
  `deadline_end` gesetzt und **abgelaufen**. Das Frist-Ende ist der letzte
  Sekundentakt des Tages (**23:59:59** inklusiv); `endOfDay()`/Vergleich werden
  auf ganze Sekunden normalisiert (`setMicrosecond(0)`), damit der Lauf exakt
  am Fristtag um 23:59:59 feuert.
- Verarbeitet via `approveAllEligible` (d. h. inkl. Überzeichnung →
  „Quota erschöpft" und Blacklist → „Blacklist"). Idempotent.
- **Eine Transaktion pro Akkreditierung** (R-D4), nicht eine für den ganzen Lauf:
  `withoutOverlapping()` hält zwei Scheduler-Läufe auseinander, aber nicht
  Scheduler gegen Admin-Aktion. Pro Akkreditierung zu sperren hält die
  Transaktionen kurz und nimmt Deadlocks zwischen zwei Läufen, die
  Akkreditierungen in unterschiedlicher Reihenfolge anpacken, die
  Lock-Reihenfolge in der DB.

## Antrag (Apply) — Anwendungsregeln

`POST /api/accreditations/{accreditation}/apply` (`backend/app/Http/Controllers/Api/AccreditationController.php`):

1. **Akkreditierung muss `active` sein und im aktuellen Mandanten liegen**
   (sonst 404).
2. **Frist-Fenster:** von **00:00:00** des `deadline_start` bis **23:59:59**
   des `deadline_end` (der Tag zählt voll, Carbon-basiert, kein SQL-Datum).
   Vor dem Start → 422 („not open yet"), nach dem Ende → 422 („deadline …
   passed").
3. **Doppel-Antrag verboten:** Unique-Constraint `(accreditation_id, user_id)`
   als autoritative DB-Sperre; expliziter Check liefert sauberes **422**
   („already applied"), der `QueryException`-Catch deckt den Race-Case ab.
4. **Quota wird beim Antrag bewusst NICHT geprüft** — Überzeichnung ist
   erlaubt, die Allocation-Engine entscheidet.
5. Anlage mit `status = 'requested'`, `priority = false` (VIP wird danach nur
   noch vom Admin gesetzt — siehe „Offene Punkte").
6. Rate-Limit **`apply` 30/min** je authentifiziertem User (Fallback pro IP).
7. Rückzug eines eigenen Antrags (`DELETE /api/applications/{id}`) nur im
   Status `requested` — `approved`/`denied` sind final (422).

## Portabilität

Alle Queries laufen über Query-Builder/Eloquent (kein PG-spezifisches SQL);
die Frist-Arithmetik (Tagesende, Sekunden-Normalisierung) passiert in PHP
(Carbon) statt in der Datenbank — Postgres (Dev/Prod) und SQLite `:memory:`
(Tests) bleiben austauschbar.

Die Synchronisation nutzt bewusst **`lockForUpdate()`** und **keine** Advisory-
Locks (`pg_advisory_xact_lock`) und **keine** `SERIALIZABLE`-Isolation: nur der
Zeilen-Lock ist portables ANSI-SQL, das beide Engines verstehen. Advisory Locks
wären in SQLite gar nicht verfügbar und würden die Testsuite nicht abbilden.

⚠️ Der Preis dieser Portabilität ist die oben beschriebene Lücke: **SQLite
ignoriert den Lock** (`compileLock()` → `''`). Postgres (Dev/Prod) ist der
einzige Motor, auf dem die Zusage aus Kernregel 3 tatsächlich gilt — der
Nachweis gehört ins Postgres-Portabilitäts-Gate.

## Offene Punkte (P3e) — Stand 2026-09-26

> ⚠️ Dieser Abschnitt war veraltet: Blacklist-CRUD und VIP-Setzung wurden als
> „fehlt" geführt, obwohl beides seit P3e existiert. Korrigiert.

- ~~**Blacklist-CRUD fehlt**~~ → **erledigt.** `BlacklistController` mit
  `GET/POST /api/admin/blacklists` und `DELETE /api/admin/blacklists/{id}`
  (mandant-scoped, `throttle:admin`), Unique-Constraints auf
  `(mandant_id, email)` und `(mandant_id, domain)`
  (`2026_08_14_000007_add_unique_to_blacklists.php`).
- ~~**VIP-Setzung via Admin fehlt**~~ → **erledigt.** `priority` wird beim Apply
  auf `false` gesetzt und danach über `AllocationService::setPriority()`
  gesetzt — beim Haupt-Antrag via
  `PUT /api/admin/applications/{id} {"priority": true|false}`, beim Sub-Antrag via
  `PUT /api/admin/sub-applications/{id} {"priority": …}`. **Kein** Person-/
  Domänen-Pattern wie in D8 angedacht: VIP ist ein Direkt-Flag pro Antrag, kein
  regelwerkartiges Muster. Bewusste Vereinfachung, kein offener Task.
- **Medien-Snapshot-Entscheidung:** Ob beim Antrag ein Snapshot von
  Foto/Presse-ID/Anhängen übernommen wird, ist offen (P3b-F3); die
  Freigabe-Sicht bezieht aktuell Medien des Antragstellers.
- **P3e-UI-Zähler:** modusabhängige `skipped_blacklist`-Darstellung (siehe
  `AllocationResult`).
- **Sub-`resend`-Button fehlt (UI)** — Endpunkt und Versand existieren (siehe
  Abschnitt „Sub-Statuswechsel-Benachrichtigung" oben, WP-3-d), nur kein Button
  ruft ihn auf.
- **Row-Lock wirkt nur unter Postgres** — der Mutual-Exclusion-Nachweis für zwei
  Verbindungen gehört ins Postgres-Portabilitäts-Gate des Go-Live-Plans, siehe
  „Atomarität (R-D4)" oben.
- ~~**Test-Coverage-Nuancen** (Blacklist+VIP-Kombi, case-insensitiv, bestehende
  `approved`, Exakt-Fit)~~ → **erledigt** (P3c-F4, in `AllocationTest`
  übernommen). Die 2026-09-26 ergänzten Atomicitäts- und R-D5-Tests stehen in
  `AllocationAtomicityTest` und `SubAccreditationRevocationTest`.
