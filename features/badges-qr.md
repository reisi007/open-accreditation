# Badges, QR & Export (P4)

**Status:** Implementiert (SOLL-Dokumentation der bestehenden `BadgeRenderService`,
`BadgeTemplateService`, `BadgeExportService`, `BadgeTemplate`-Model).

Dauerhafter SOLL-Zustand für den Ausweis-Workflow: Badge-Template (Feld-Editor),
PDF-/QR-Rendering und CSV/Excel-Export genehmigter Akkreditierungen.

## Betroffene Klassen

| Klasse | Verantwortung |
|---|---|
| `App\Models\BadgeTemplate` | Template-Modell (Mandant, `layout`-JSON, `is_default`) |
| `App\Services\BadgeTemplateService` | "Ein Default pro Mandant"-Invariante |
| `App\Services\BadgeRenderService` | A6-Karte, Feld-Positionierung, QR-Rendering (PDF) |
| `App\Services\BadgeExportService` | Streamed PDF- und CSV-Export |

## Badge-Template-Modell (`BadgeTemplate`)

Eine Ausweis-Vorlage (`Ausweis-Vorlage`) eines Mandanten (Verband). Fillable:
`mandant_id`, `name`, `layout`, `is_default`. Casts: `layout → array`,
`is_default → boolean`. Scopes: `forMandant(int)` (Templates eines Mandanten),
`default()` (Default-Templates). Relation: `mandant()` → `Mandant`.

`layout` ist ein JSON-Array positionierter Felder (siehe Feld-Editor unten).

## Feld-Editor / Layout-Schema

Der Feld-Editor (Frontend) schreibt `BadgeTemplate.layout` als Array von
Feld-Definitionen:

```json
[
  { "field": "name", "x": 10, "y": 12, "w": 80, "h": 10, "size": 18, "align": "left" }
]
```

- `field ∈ { name, category, event, date, photo, status }`
- `x, y, w, h` in **Millimetern** (absolute Position auf der A6-Karte)
- `size` in **pt** (Font-Größe)
- `align ∈ { left, center, right }` (Default `left`)

Feld-Auflösung (`BadgeRenderService::valueFor`):

| `field` | Quelle |
|---|---|
| `name` | `application.user.name` |
| `category` | `application.accreditation.category.name` |
| `event` | `application.accreditation.event.title` |
| `date` | Event-Datum (`d.m.Y`) |
| `photo` | Portrait des Bewerbers (siehe unten) |
| `status` | deutsche Status-Beschriftung |

**SOLL-Erweiterung (offen, P4-F4):** Das Layout-Schema erhält künftig ein
optionales **`qr`-Feld** (eigener Entry mit `x/y/w/h` in mm), damit die
QR-Position template-adressierbar wird. Bis dahin gilt die Fixposition unten
rechts (`right: 5mm; bottom: 5mm`, **20 × 20 mm**) — ein dort platziertes
Template-Feld kann vom QR überlappt werden (siehe Limitation unten); die
Kollisionsvermeidung liegt solange beim Template-Autor (untere rechte Ecke
freilassen).

## Rendering — `BadgeRenderService` (P4)

- **Kartenformat:** A6, **105 × 148 mm**, Hochformat. Jede Karte ist ein
  `position: relative`-Container mit `page-break-after: always` (eine Karte pro
  genehmigter Application).
- **Feld-Positionierung:** jedes Layout-Feld wird absolut positioniert
  (`left/top` mm, `width/height` mm, `font-size` pt, `text-align`). Text wird
  via `e()` escaped (XSS-Safe).
- **`photo`:** Portrait aus `user.media` (`type = 'portrait'`) auf der `private`-
  Disk, als Base64-`data:`-URI eingebettet (`object-fit: cover`). Fehlt das
  Portrait, bleibt eine leere Box an der Layout-Position.
- **`status`:** deutsche Labels — `approved → Akkreditiert`, `requested →
  Beantragt`, `denied → Abgelehnt`, `blacklisted → Gesperrt`.

### QR-Code (Verify-URL)

Jede Karte trägt **zusätzlich** einen Verifikations-QR-Code an einer
**festen Position unten rechts** (`right: 5mm; bottom: 5mm; 20 × 20 mm`),
unabhängig vom `layout` (das Schema adressiert nur die sechs Datenfelder — der
QR ist ein Standard-Bestandteil der Karte). Rendering via Endroid `QrCode\Builder`
(`size: 300`, `margin: 0`) als `data:`-URI (PNG).

Die Verify-URL ist `{scheme}://{host}/verify/{token}`:
- `host` = erste Domain des aktuellen Mandanten (`MandantContext::current()
  ->domains()->orderBy('id')->value('hostname')`) oder — ohne Domain — der Host
  aus `config('app.url')` (Fallback `localhost`).
- `scheme` aus `config('app.url')` (Fallback `https`).
- `token` = deterministisch via `QrTokenService::make(application)` (gleiche
  Application → gleicher Token).

**Bekannte Limitation (P4-F2):** Da der QR fest unten rechts liegt, kann er
ein dort platziertes Nutzer-Feld (z. B. `photo`) überlappen. Das Layout-Schema
kennt die QR-Position nicht; eine Kollisionsvermeidung ist bewusst nicht
implementiert (Templates sollten die untere rechte Ecke freilassen).
Geplante Gegenmaßnahme: das optionale `qr`-Layout-Feld (P4-F4, siehe
Layout-Schema oben) — bis dahin bleibt es bei der Fixposition.

## Export — `BadgeExportService` (P4)

Streamed-Download der genehmigten Applications einer Akkreditierung.

- **PDF (dompdf):** eine A6-Karte pro genehmigter Application, gerendert aus dem
  Template-Layout (siehe `BadgeRenderService`). Dateiname
  `badges-{accreditationId}.pdf`, `Content-Type: application/pdf`.
- **CSV:** `fputcsv` mit `;`-Separator (DE-Excel). **UTF-8-BOM** vorangestellt,
  damit Excel Umlaute korrekt dekodiert. Spalten: `Name, E-Mail, Kategorie,
  Event, Status, Verify-URL`. Dateiname `badges.csv`,
  `Content-Type: text/csv; charset=UTF-8`.

**Entscheidung (dokumentierter Frontend-Vertrag):** Eine Akkreditierung ohne
genehmigte Applications antwortet mit **200 + leerem Dokument** — eine leere
A6-Seite (PDF) bzw. nur die Header-Zeile (CSV) — **nicht** 204. Das Template muss
immer auflösbar sein (explizites `template_id` oder der Mandant-Default); sonst
antwortet der Controller **422 "No badge template"**, bevor dieser Service läuft.

### CSV-Formula-Injection-Schutz (P4-F1)

`sanitizeCsvCell()` neutralisiert CSV-Formula-Injection: eine Zelle, die mit
einem Tabellen-Formel-Marker (`=`, `+`, `-`, `@`) oder Tab/CR beginnt, wird mit
einem Apostroph präfigiert, damit Excel/Sheets sie als Text behandelt. Angewandt
auf **jede nutzer-kontrollierte Zelle** (Name, E-Mail, Kategorie, Event,
Verify-URL); die deutsche Status-Beschriftung und die Header-Zeile sind
vertrauenswürdige Server-Werte und bleiben unangetastet.

## Invarianten (nicht regredieren)

- **"Ein Default pro Mandant"** (`BadgeTemplateService::setAsDefault`): Das
  Setzen eines Templates als Default setzt den vorherigen Default desselben
  Mandanten auf `false`. Bewusst **im Service** (nicht als DB-Constraint)
  erzwungen — eine partielle Unique-Index-Lösung wäre Postgres-spezifisch und
  bräche die SQLite-Portabilität der Tests (AGENTS.md §2).
- Portrait wird ausschließlich von der `private`-Disk gelesen (auth-gated).
- Verify-URL trägt keine Secrets; der QR verifiziert die (genehmigte) Application
  über einen deterministischen Token + Mandant-Host-Chain.
