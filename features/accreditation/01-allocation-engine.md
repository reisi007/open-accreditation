# Allocation-Engine (P3c)

Kernreferenz der Freigabe-Logik (**D8**): wer erhält einen Quota-Slot — und wer
nicht. Die Engine ist **server-autoritativ** (Freigabe-Entscheidung nie im
Client). Umsetzung im Ist:
`backend/app/Services/AllocationService.php`,
`backend/app/Services/AllocationResult.php`,
`backend/app/Console/Commands/RunAllocations.php`,
`backend/app/Http/Controllers/Api/Admin/AccreditationController.php::allocate`,
Route `accreditations.allocate` (`backend/routes/api.php`),
Schedule (`backend/routes/console.php`).

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
   bei der Allokation, nicht beim Antrag.
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

## Status-Semantik

- Status-Set: **`requested | approved | denied | blacklisted`**.
- Die Engine schreibt ausschließlich **`requested → approved`** und
  **`requested → denied`** (mit `reason`). Denied ist final, `approved` ist
  final.
- **`blacklisted` wird von der Engine nie gesetzt** — der Status ist für die
  **Blacklist-Verwaltung in P3e** reserviert (Block auf Mandant-Ebene).

### Bulk-Reanimations-Limitation (BE-R8, Design-Entscheidung)

> ⚠️ **Limitation:** Bulk-Läufe können `denied`-Anträge **nicht reanimieren**.

Alle Bulk-Pfade (`approveSelection`, `approveAllEligible` — manuell wie
automatisch) kandidieren ausschließlich über `eligibleRequested()`, d. h. nur
Zeilen im Status **`requested`**. Konsequenzen:

- Ein `denied`-Antrag bleibt durch jeden weiteren Bulk-Run — auch nach
  Quota-Erhöhung oder Blacklist-Löschung — **dauerhaft `denied`**. Das gilt
  ausdrücklich auch für Anträge mit VIP-Priorität: `priority = true` schützt
  nicht vor dem Verbleib in `denied`; VIP wird nur unter den `requested`-
  Kandidaten bevorzugt sortiert, nie re-geprüft.
- Ebenso sind `approved`-/`blacklisted`-Zeilen nie Bulk-Kandidaten
  (Idempotenz, siehe Kernregel 7).

**Einziger Reanimationsweg:** die Einzelaktion
`AllocationService::approveApplication()` (P3e), die als Ausgangsstatus
explizit `requested | denied` zulässt (Blacklist-Guard und Quota-Check laufen
dort erneut). Die Massenfreigabe ist bewusst vom Reanimationsweg
ausgeschlossen: Bulk-Läufe bleiben deterministisch und idempotent, die
Reaktivierung abgelehnter Anträge ist eine bewusste Admin-Entscheidung im
Einzelfall — keine Design-Lücke, sondern gewollte Trennung.

## Service-API

### `App\Services\AllocationService`

| Methode | Verhalten |
|---|---|
| `approveSelection(Accreditation $a, int $limit): AllocationResult` | Manuell „erste X". `limit <= 0` oder kein Restplatz → No-op (`AllocationResult::none()`). Blacklist-Treffer bleiben `requested`. |
| `approveAllEligible(Accreditation $a): AllocationResult` | Alle freigeben bis zur Quota (manuell `mode=all` **und** Auto-Modus). Überschuss → `denied` „Quota erschöpft", Blacklist → `denied` „Blacklist". |
| `runAutoAllocations(?DateTimeInterface $now = null): array` | Automatischer Trigger: pro verarbeiteter Akkreditierung `[id => ['approved' => n, 'denied' => m]]`. Nur wenn Frist abgelaufen (siehe Trigger). |

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
5. Anlage mit `status = 'requested'`, `priority = false` (VIP-Setzung nur via
   Admin, siehe offene Punkte).
6. Rate-Limit **`apply` 30/min** je authentifiziertem User (Fallback pro IP).
7. Rückzug eines eigenen Antrags (`DELETE /api/applications/{id}`) nur im
   Status `requested` — `approved`/`denied` sind final (422).

## Portabilität

Alle Queries laufen über Query-Builder/Eloquent (kein PG-spezifisches SQL);
die Frist-Arithmetik (Tagesende, Sekunden-Normalisierung) passiert in PHP
(Carbon) statt in der Datenbank — Postgres (Dev/Prod) und SQLite `:memory:`
(Tests) bleiben austauschbar.

## Offene Punkte (P3e)

- **Blacklist-CRUD fehlt:** Es existieren nur Tabelle/Modell, **keine**
  mandant-scoped CRUD-Routen; ein Unique-Constraint fehlt (P3c-F2).
- **VIP-Setzung via Admin fehlt:** `priority` wird beim Apply hart auf `false`
  gesetzt; der Admin-Weg (Person/Domäne laut D8) folgt in P3e (P3c-F3).
- **Medien-Snapshot-Entscheidung:** Ob beim Antrag ein Snapshot von
  Foto/Presse-ID/Anhängen übernommen wird, ist offen (P3b-F3); die
  Freigabe-Sicht bezieht aktuell Medien des Antragstellers.
- **P3e-UI-Zähler:** modusabhängige `skipped_blacklist`-Darstellung (siehe
  `AllocationResult`).
- Test-Coverage-Nuancen (Blacklist+VIP-Kombi, case-insensitiv, bestehende
  `approved`, Exakt-Fit) optional nachziehen (P3c-F4).
