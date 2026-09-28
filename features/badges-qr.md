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
| `App\Services\BadgeExportService` | Streamed PDF- und CSV-Export (gechunkt) |
| `App\Services\QrTokenService` | QR-Token Format v2 (mandant-gebunden, `APP_PREVIOUS_KEYS`) |
| `App\Services\QrTokenClaims` | Value-Object der verifizierten Token-Claims (v1/v2) |
| `App\Http\Controllers\Api\VerifyController` | public Verifikation, mandant-scoped (Defense in Depth) |

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

**Implementiert (P4-F4, Schema v2):** Das Layout-Schema unterstützt ein
optionales **`qr`-Feld** (eigener Entry mit `x/y/w/h` in mm), damit die
QR-Position template-adressierbar wird. Der Renderer positioniert den QR am
Entry (`left/top/width/height` mm); ohne Entry gilt weiterhin die historische
Fixposition unten rechts (`right: 5mm; bottom: 5mm`, **20 × 20 mm**) —
Bestandstemplates rendern unverändert. Kollisionsvermeidung: der QR-Block wird
IMMER als letztes Element gerendert (oberste Z-Ebene), sodass er über
überlappenden Feldern liegt und scannbar bleibt; ob ein `qr`-Entry mit einem
anderen Feld überlappt, meldet der Editor eine soft Warning (gleiche Logik wie
bei Datenfeldern). Maximal ein `qr`-Entry pro Template (Validierung). Die
vollständige SOLL-Spec für das Schema v2 und den frei positionierbaren Feld-
Editor (Drag & Drop) liegt in `badge-template-editor.md`.

## Rendering — `BadgeRenderService` (P4)

- **Kartenformat:** A6, **105 × 148 mm**, Hochformat. Jede Karte ist ein
  `position: relative`-Container mit `page-break-after: always` (eine Karte pro
  genehmigter Application).
- **Feld-Positionisierung:** jedes Layout-Feld wird absolut positioniert
  (`left/top` mm, `width/height` mm, `font-size` pt, `text-align`). Text wird
  via `e()` escaped (XSS-Safe). `width/height` **clippen nicht** — sie
  positionieren und reservieren Platz; nur `photo` und `image` tragen
  `overflow:hidden`. Ein mehrzeiliger Text in einem zu kleinen Kasten läuft in
  das Feld darunter (gemessen, siehe „Visuelle Verifikation“ unten).
- **`photo`:** Portrait aus `user.media` (`type = 'portrait'`) auf der `private`-
  Disk, als Base64-`data:`-URI eingebettet. Die Box wird **nicht gestreckt**:
  `fit` ist Geometrie, keine Deklaration (dompdf kennt `object-fit` nicht — die
  Eigenschaft fällt still durch und `width/height: 100 %` bedeutet Stretch).
  Default ist `cover` (das war die historisch erklärte Absicht), d. h. die Box
  wird an der begrenzenden Kante gefüllt und die andere Achse **symmetrisch
  beschnitten** — ein 60 × 80-Porträt in einer 30 × 30-mm-Box ergibt
  30.00 × 40.00 mm bei 0.00 / −5.00, also 5 mm Beschnitt oben und unten statt
  Verzerrung. Das ist die einzige Stelle, an der bereits gedruckter Bestand
  durch die `fit`-Umsetzung (User-Entscheidung 2026-09-28) sein Aussehen ändert.
  **Fehlt das
  Portrait, druckt die Box das gebündelte Personen-Silhouett** (User-Entscheidung
  2026-09-28) statt nichts — Format, Größe, Alpha-Channel und die selbst
  gerechnete Contain-Geometrie sind in
  `badge-template-editor.md` → „Platzhalter für ein fehlendes Porträt"
  **gemessen** begründet. Die leere Box bleibt der letzte Ausweg, wenn
  zusätzlich das gebündelte Asset fehlt (Deploy-Defekt); sie ist damit nicht
  mehr der Normalfall eines Ausweises ohne Bild.
- **`image` (selbst platziertes Bild, Schema v2):** `fit` steuert dieselbe
  Geometrie, Default `contain` (Logos werden nicht beschnitten). `contain` legt
  das Bild vollständig in die Box und zentriert es, `cover` füllt die Box und
  beschneidet; **beide strecken nie**. Rechenweg, Messwerte am echten Render und
  die quadratische Entartung (quadratische Quelle in quadratischer Box →
  `contain` und `cover` identisch) stehen in `badge-template-editor.md` →
  „Die `fit`-Geometrie rechnet der Renderer selbst"; die Rechnung ist **eine**
  Funktion für `photo`, Platzhalter und `image`. Ein ungültiges `fit` ist nicht
  speicherbar (422) und kippt im Renderer nicht still auf einen Default.
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
- `token` = mandant-gebundener Token aus `QrTokenService::make(application)`
  (Format v2, siehe unten). Deterministisch: gleiche Application + gleicher
  Mandant + gleicher `APP_KEY` → gleicher Token.

### QR-Token-Format v2 (R-D3) — Tenant-Binding + Key-Rotation

```
token = base64url( applicationId . '.' . hmac_sha256(secret, "v2:"+applicationId+":"+mandantId) . '.' . mandantId )
```

- **Mandant-Binding:** Die Signatur deckt **beide** Claims ab, und die
  `mandantId` ist zusätzlich Teil des Base64-Payloads. Ein Ausweis von Verband A
  validiert damit **nie** auf dem Host von Verband B — auch nicht, weil die
  Application-Id global eindeutig ist. Vor v2 trug der Token nur die Id; der
  Mandant kam allein aus der DB-Zeile, wodurch `/api/verify` auf einem fremden
  Host Name, Kategorie, Event, Datum und Portrait des Inhabers preisgab.
- **`hash_equals` über beide Claims:** `parse()` liefert ein Value-Object
  (`QrTokenClaims`: `applicationId`, `mandantId`, `version`) oder `null`; jede
  Abweichung in einem Claim, ein unbekannter Schlüssel oder ein defekter
  Token ⇒ `null` ⇒ 404.
- **Binäre Signatur:** das HMAC ist roh (32 Byte) und kann selbst `.`-Bytes
  enthalten. Der Parser liest die Segmente deshalb von außen nach innen
  (Id = erstes Segment, `mandantId` = letztes all-digit-Segment, dazwischen die
  Signatur) und probiert **beide** Lesarten: erst die mandant-gebundene
  v2-Prüfung, danach **unbedingt** die Legacy-v1-Prüfung über den gesamten
  Rest-Payload (eine v1-Signatur *ist* der Payload, Dots inklusive). Ein
  v1-Token, dessen Signatur zufällig auf `.` + reine Ziffern endet (selten,
  gemessen 4 von 40 000 ausgestellten Tokens), wird so nicht mehr als v2 mit
  kaputtem Mandant-Claim fehlinterpretiert und **abgewiesen**, sondern über die
  v1-Prüfung akzeptiert — die ist an den vollen HMAC der Application-Id
  gebunden und kann daher nicht missbraucht werden. Ein v2-Token mit
  manipuliertem oder korruptem Mandant-Segment scheitert an beiden Lesarten und
  wird abgewiesen.
- **Key-Rotation:** Minting nutzt **immer** den aktuellen Key; die Prüfung
  durchläuft zuerst `config('app.key')` und danach `config('app.previous_keys')`
  (`APP_PREVIOUS_KEYS`, kommasepariert). `php artisan key:generate` invalidiert
  damit **keine** bereits gedruckten Ausweise, solange der alte Key in
  `APP_PREVIOUS_KEYS` steht.
- **Self-healing `make()`:** Ein gespeicherter `qr_token` wird neu gemintet,
  wenn er fehlt, mit **keinem** bekannten Key verifizierbar ist (Rotation ohne
  `APP_PREVIOUS_KEYS`) oder kein gültiges v2-Token dieser Application ist. Der
  Write-Pfad (Approve, Resend, Export, Wallet-Pass) repariert die Zeile damit
  beim nächsten Berühren — früher lieferte `make()` den gespeicherten Wert
  blind zurück und die Zeile war für immer tot.

**Legacy-Format v1 (Kompatibilität):** `base64url(applicationId . '.'
+ hmac_sha256(secret, applicationId))` — zwei Segmente, **kein** Mandant-Claim.
Ein v1-Token wird weiterhin akzeptiert, **sofern** seine Signatur gegen einen
bekannten Key prüft, gilt aber als *nicht* mandant-gebunden
(`QrTokenClaims::isTenantBound() === false`): er authentifiziert nur die
Application-Id. Für diese Bestands-Ausweise ist die **DB-Scope** die
Isolationsgrenze, nicht der Token — `VerifyController` löst die Application
deshalb **immer** mandant-scoped auf (`Application::scopeForMandant`, also
`whereHas('accreditation')`). Jeder Write-Pfad und
`accreditation:backfill-qr-tokens` migrieren v1 → v2.

**Verify-Endpunkt (Defense in Depth):** `GET /api/verify/{token}` ist public
und host-geroutet; `MandantContextMiddleware` hat den Mandanten dann bereits
aufgelöst (in Produktion 404 bei unbekanntem Host). Zusätzlich gilt:

1. Der signierte `mandantId`-Claim muss zum aktuellen Mandanten passen
   (`QrTokenClaims::matchesMandant()`) — v1-Tokens passen hier immer und fallen
   auf Punkt 2 zurück,
2. die Application-Suche ist **unbedingt** mandant-scoped,
3. ohne aufgelösten Mandanten wird **nichts** aufgelöst (fail closed, 404).

`VerifyResource` ist selbst keine Isolationsgrenze, sondern serialisiert nur,
was der Controller mandant-scoped geladen hat; `photo_url` wiederholt den
Token des Aufrufers, und die Portrait-Route nutzt dieselbe Auflösung.

### Backfill: `accreditation:backfill-qr-tokens`

Ein idempotenter, `chunkById`-gechunkter Einmal-Lauf über **alle** genehmigten
Applications; `make()` erledigt die Arbeit, der Command zählt nur:

- `NULL` → wird gefüllt (Legacy aus der Zeit vor der Token-Issued-Bei-Approve),
- v1-Token → wird auf v2 migriert (nicht mandant-gebunden → gebunden),
- Token, der mit keinem bekannten Key mehr prüft → wird neu gemintet
  (Key-Rotation ohne `APP_PREVIOUS_KEYS`),
- aktuelles v2-Token → bleibt unangetastet (kein Write-on-Read).

Nur `approved`-Zeilen sind im Scope: ein Token wird bei der Genehmigung
ausgestellt. Eine entzogene (`denied`/`blacklisted`) Zeile **behält** ihr Token —
aber nur solange dieser Token noch verifiziert: `/api/verify` löst ihn über
`parse()` auf, nicht über die Spalte `qr_token`. Nach einer `APP_KEY`-Rotation
**ohne** `APP_PREVIOUS_KEYS` ist er tot, der Backfill nimmt die Zeile nicht auf
(er scannt nur `approved`), also antwortet `/api/verify` **404** statt
`status: denied`. Einen Write-Pfad, der das repariert, gibt es für eine
entzogene Zeile nicht: der alte Key muss in `APP_PREVIOUS_KEYS` stehen (dann
verifiziert der Token weiter) oder die Zeile wird kurz auf `approved` gesetzt
(`make()` migriert sie dann auf v2). Beim erneuten Genehmigen (oder bei einem
manuellen Lauf nach einer Statuskorrektur) wird die Zeile ohnehin mitgezogen.
Das `accreditation` wird pro Chunk eager geladen (eine Query pro Chunk, nicht
eine pro Application).

**Historische Fixposition (Fallback):** Templates ohne `qr`-Entry rendern
den QR an der festen Position unten rechts. Ein dort platziertes Nutzer-Feld
kann den QR überlappen — der QR wird aber als oberste Z-Ebene gerendert
(scannbar bleibt). Die Kollisionsvermeidung geschieht bewusst über das
optionale `qr`-Layout-Feld (P4-F4, implementiert): der Template-Autor verschiebt
den QR an eine freie Position. Die Fixposition bleibt bestehen, solange
Bestandstemplates ohne `qr`-Entry existieren (Rückwärtskompatibilität).

### Visuelle Verifikation des gerenderten PDF (`PDF-VISION`)

Ein Badge-PDF ist nur dann visuell prüfbar, wenn daraus **das richtige PNG**
wird. Der Renderer malt **keinen** weissen Seitenhintergrund — dompdf lässt die
Seite transparent. Damit hängt das Aussehen der Rasterung vom *Konsumenten* ab
und nicht vom Rasterer, und die drei üblichen Konsumenten liefern drei
verschiedene Bilder. Das ist der Grund, warum die Verifikation zweistufig läuft
und warum sie als Skript existiert statt als Einmal-Anweisung:

```bash
bash scripts/pdf-to-png-vision.sh <file.pdf> [-o OUTDIR] [-d DENSITY] [--keep-step1]
```

Das Skript nimmt einen PDF-Pfad, schreibt die PNGs in ein Ausgabeverzeichnis
(Default `${TMPDIR}/pdf-vision-<name>`), prüft seine Werkzeuge **namentlich**,
verifiziert das Ergebnis und beendet sich ungleich 0, wenn etwas fehlt oder das
Ergebnis nicht vertrauenswürdig ist (kein `|| true`, kein stilles Überspringen).
Routen, alle gemessen (siehe unten):

| Route | Werkzeuge | Seiten | DPI | Ergebnis |
|---|---|---|---|---|
| **Primär** (zweistufig) | `magick` + `gs` | alle | frei | kein Alpha, Eckpixel weiss |
| **Fallback A** (einstufig) | `gs` allein | alle | frei | kein Alpha, Eckpixel weiss |
| **Fallback B** | `sips` | **nur 1** | ~72 | **Alpha bleibt → Exit 3** |
| keines | — | — | — | Exit 1, nennt die fehlenden Werkzeuge |

Jede Fallback-Route wird **laut** angekündigt (stderr, `>>> FALLBACK <<<`).
Fallback B endet mit Exit 3, weil `sips` keinen Alpha-Kanal entfernen kann —
ein grüner Lauf auf genau dem Bild, das diese Pipeline beseitigen soll, wäre
schlimmer als gar keiner.

**Gemessene Zahlen** (A6, 2-Karten-Badge-PDF aus `BadgeRenderService::renderPdf`,
13 970 Byte, 2 Seiten; macOS, ImageMagick 7.1.2-31, Ghostscript 10.08.0):

| | Maße | Kanäle | Eckpixel (2,2) |
|---|---|---|---|
| Stufe 1 `magick -density 200` | **827 × 1165 px** | `srgba` (PaletteAlpha) | `#FFFFFF00` — weiss, alpha 0 |
| Stufe 2 `-background white -alpha remove -alpha off` | 827 × 1165 px | `srgb` (kein Alpha) | `#FFFFFF` — literal weiss |
| `sips -s format png` | 298 × 420 px | `srgba` | `#00000000` — schwarz, alpha 0 |

**Was in Stufe 1 wirklich dasteht** (nicht die verbreitete Vermutung): 89,8 %
aller Pixel (865 288 von 963 455) sind **vollständig transparent**, und sie
tragen als RGB **weiss** — `#FFFFFF00`. Die Behauptung „transparente Pixel
erscheinen schwarz" ist damit **für den `magick`-Pfad falsch** und **für den
`sips`-Pfad wörtlich richtig** (`sips` legt `#00000000` ab). Der Defekt ist
real, nur seine Mechanik ist die andere: nicht ein fixed Schwarz, sondern ein
**Alpha-Kanal, dessen Behandlung der Konsument festlegt**. Gemessen an einem
Textband (x 330…827, y 200…360 = 79 520 px, Endbild der Karte):

| Konsument von Stufe 1 | Hintergrund | Schrift |
|---|---|---|
| Alpha ignorieren (`-alpha off`) | weiss | 2 Farben, 7 756 dunkle px — **+14,1 % zu fett**, Anti-Aliasing weg |
| auf weiss komponieren | weiss | 22 Farben, 6 799 dunkle px, echte Graustufen (17/68/119/187) |
| auf schwarz komponieren | **schwarz** | **1 Farbe, 0 sichtbare Tintepixel** — der gesamte Ausweistext ist weg |

Der QR-Code überlebt beide Stufen unverändert: sein PNG ist deckend, im
QR-Kasten (158 × 158 px) sind in Stufe 1 wie in Stufe 2 exakt 12 653 px `#FCFEFC`
und 12 153 px `#040204` — nur die 158 px Seitenhintergrund im Kasten wechseln die
Farbe. Auf schwarz flachgerechnet kippt allerdings die **Figur-Grund-Wahrnehmung**
des QR (die weissen Module heben sich nun vom schwarzen Grund ab), was eine
Vision-Analyse der Modul-Polarität irreführen kann.

**Einstufige Alternative.** `gs -sDEVICE=png16m` ist ein **deckendes** Device:
kein Alpha-Kanal, der weisse Hintergrund wird von Ghostscript selbst gemalt, alle
Seiten, freie DPI — eine Stufe statt zwei. Gemessen gegen den Primärpfad:
2 821 abweichende Pixel von 963 455 (**0,29 %**), RMSE 0,0029, 25 statt 26
Farben — reines Anti-Aliasing-Rauschen, keine strukturelle Abweichung. Das Skript
nutzt diese Route als Fallback A, wenn `magick` fehlt.

**Zwei Stolperfallen, die in der Doku nicht stehen sollten:**

- `magick identify -format '%[pixel:p{x,y}]'` ist auf einem PaletteAlpha-Bild
  **unbrauchbar**: es meldet unabhängig vom gespeicherten Wert `srgba(0,0,0,0)`.
  Der zuverlässige Weg ist ein 1×1-Crop mit `txt:`
  (`magick f.png -crop 1x1+2+2 +repage txt:` → `#FFFFFF00`). Ein Skript, das
  hier `%[pixel:…]` auswertet, „beweist" einen schwarzen Hintergrund, den die
  Datei gar nicht enthält.
- `sips -s format png kaputt.pdf` quittiert mit `not a valid file - skipping` und
  **Exit 0**. Deshalb prüft das Skript die Existenz und Größe der Zieldatei,
  nicht den Exit-Code.

**Der Layoutkasten clippt nicht** (Render-Vertrag, gemessen). `x/y/w/h`
positionieren und reservieren Platz; `h` ist **keine Clip-Grenze** — nur
`photo`- und `image`-Entries tragen `overflow:hidden`, Textfelder nicht. Gemessen
an einer Fixture mit **einzigem** Feld (`name`, 42…97 × 26…36 mm, 15 pt,
zweizeiliger Name): die Tinte beginnt bei 42,5 mm / 28,6 mm, ist 53,1 × 12,2 mm
gross und reicht bis **40,8 mm** — 4,8 mm über den Kasten hinaus. Eine
Feld-Überlappung auf dem Ausweis ist deshalb **nicht automatisch ein
Renderer-Fehler**, sondern kann aus einem zu kleinen Kasten im Template kommen.
Die Vision-Checkliste muss das unterscheiden, bevor sie einen Befund meldet.

**Empfehlung, ausdrücklich NICHT umgesetzt (Produktentscheidung):**
`background-color: #ffffff` auf `body` bzw. `@page` im Badge-HTML wäre die
einfachere und robustere Lösung — dann wäre der Seitenhintergrund im PDF
selbst weiss und die Nachbearbeitung entfiele. Sie ist **nicht** implementiert,
weil sie den **Render-Vertrag** ändert (jeder Ausweis bekäme einen gemalten
Hintergrund, `BadgeRenderService::cardHtml` wird zum Render-Vertrag, gegen den
die Tests prüfen) und weil sie eine **Produktfrage** berührt, die nicht
technisch ist: Ein Ausweis mit weiss gemaltem Hintergrund ist nicht mehr
transparent, was für Ausweisspiele mit farbigem oder transparentem Untergrund
(Folien, Glas, Siebdruck) eine echte Einschränkung ist. Entscheidung liegt beim
Benutzer; bis dahin gilt die gemessene zweistufige Pipeline.

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

### Export — Speicherprofil (WP-2-d)

Der Export liest die genehmigten Applications **gechunkt** (`lazyById`, 200 pro
Batch; Eager-Loading auf `user.media`, `accreditation.category/event/team`
bleibt erhalten) und streamt sie:

- **CSV** ist vollständig streaming: `fputcsv` pro Zeile direkt in
  `php://output`, der Speicherbedarf ist O(1) unabhängig von der Anzahl.
- **PDF** hängt an dompdf: das erzeugte HTML (mit allen Base64-Portraits) und
  der Canvas-Objektgraph müssen in das PHP-`memory_limit` passen. Der
  Chunking-Fix entfernt den zweiten, bisher größten Brocken (alle
  Application-Modelle + Media-Rows auf einmal); die dompdf-Grenze bleibt
  **bewusst** und ist damit nicht versteckt, sondern hier dokumentiert. Wer sehr
  große Bestände exportiert, braucht ein entsprechendes `memory_limit` bzw. den
  Weg über den CSV-Export.

Die Karten eines Laufs teilen sich zusätzlich zwei pro Run gecachte Lookups:
den Verify-Host (`MediaHostResolver`) und die aufgelösten `BadgeImage`-Data-URIs
(Schlüssel `mandantId:imageId`). Dadurch kostet ein Export mit M Bildern
**O(distinct image ids)** Queries statt O(Karten × M).

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
  über einen mandant-gebundenen, signierten Token (v2) + Mandant-Host-Chain.
- **Der Verify-Pfad ist mandant-scoped, ohne Ausnahme:** die Application-Suche
  in `VerifyController` trägt immer `forMandant()`; ein `->when(hasCurrent(), …)`
  (fail-open) darf dort nicht eingeführt werden. Dasselbe gilt im Render-Pfad
  für `BadgeImage` (Lookup immer `forMandant()`; ohne aufgelösten Mandanten
  rendert der `image`-Entry eine leere Box).
- **Read-Pfade schreiben nicht:** `AdminApplicationResource` benutzt den
  gespeicherten Token, wenn er ein gültiges v2-Token der Application ist, sonst
  den frisch berechneten — die Spalte reparieren ausschließlich die
  Write-Pfade und `accreditation:backfill-qr-tokens`.
- **Wer Tokens mintet, lädt `accreditation`:** der v2-Token braucht
  `accreditation.mandant_id`, und `QrTokenService::mandantIdOf()` liest sie aus
  der Relation. Jeder Bulk-Pfad über Applications MUSS sie eager laden
  (`with('accreditation:id,mandant_id')` — eine Query pro Bulk) — sonst N+1 mit
  einer `accreditations`-Query pro Zeile. Referenz: `BackfillQrTokens` und
  `AllocationService::issueQrTokens()`; gepinnt in
  `AllocationQrTokenQueryTest`.
