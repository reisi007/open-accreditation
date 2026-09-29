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
- **Seitenhintergrund:** **deckend weiss**, `background-color:#ffffff` auf dem
  Karten-Wurzelcontainer (Nutzerentscheidung 2026-09-28). **Kein** optionales
  Detail und **keine** Transparenz — die frühere transparente Seite war ein
  Defekt, kein Feature (Begründung, Messwerte und der bewusst bezahlte Preis in
  „Weisser Seitenhintergrund" unten).
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
  Funktion für `photo`, Platzhalter, `image` **und den QR-Code** (siehe unten).
  Ein ungültiges `fit` ist nicht speicherbar (422) und kippt im Renderer nicht
  still auf einen Default.
- **`status`:** deutsche Labels — `approved → Akkreditiert`, `requested →
  Beantragt`, `denied → Abgelehnt`, `blacklisted → Gesperrt`.

### QR-Code (Verify-URL)

Jede Karte trägt **zusätzlich** einen Verifikations-QR-Code. Ohne `qr`-Eintrag
im `layout` liegt er an der **historischen festen Position unten rechts**
(`right: 5mm; bottom: 5mm; 20 × 20 mm`), damit Bestandstemplates identisch
weiterdrucken; mit `qr`-Eintrag positioniert der Eintrag ihn (`left/top/width/
height` in mm, Minimum 10 × 10 mm, `size`/`align` werden ignoriert). Rendering
via Endroid `QrCode\Builder` (`size: 300`, `margin: 0`) als `data:`-URI (PNG).

#### Die QR-Geometrie: `cover`, hart kodiert (2026-09-28, P7)

**Der QR-Zweig war die letzte Ausnahme von der `fit`-Regel und ist es nicht
mehr.** Bis P7 rendierte er `<img style="width:100%;height:100%">` **ohne**
mm-Geometrie. Da dompdf `object-fit` nicht kennt (gemessen: die Eigenschaft
kommt in `vendor/dompdf/` mit null Treffern vor und fällt still durch den
Kaskadenlauf), bedeutete das **STRECK** — und ein nicht-quadratischer `qr`-Kasten
ist erlaubtes Tenant-Input (Minimum 10 × 10 mm, also z. B. `w:30, h:20`). Ein in
ein 3:2-Rechteck gezogener Code verliert sein Modul-Seitenverhältnis und **wird
nicht gescannt**; damit fällt die Verifikation dieses Ausweises aus. Das ist kein
Kosmetikfehler wie bei einem gestreckten Porträt, sondern ein Ausfall des
Produkts.

Der Zweig geht jetzt durch **dieselbe** `fittedImage()`-Rechnung wie `photo`,
Platzhalter und `image` — eine Funktion, vier Zweige. Gemessen am echten Render
(dompdf → `scripts/pdf-to-png-vision.sh`, 200 dpi; A6 = 827 × 1165 px):

| `qr`-Box | gezeichnetes Rechteck | sichtbarer schwarzer Block | Kontrollrechnung |
|---|---|---|---|
| 30 × 20 mm | `left 0.00 / top −5.00 / 30.00 × 30.00 mm` | 233 × 158 px = 29.59 × 20.07 mm | 30 mm × 296/300 (2 px Ruhezone) = 233.1 px ✔ |
| 20 × 30 mm | `left −5.00 / top 0.00 / 30.00 × 30.00 mm` | — | Gegenprobe: Beschnitt quer statt hoch |
| 25 × 25 mm | `left 0.00 / top 0.00 / 25.00 × 25.00 mm` | — | Entartung: Box = Quelle |

**`cover`, nicht `contain`** — und der Unterschied ist hier nicht Geschmack:
`cover` skaliert mit dem grösseren Achsenfaktor, der Code **füllt die Box**, und
der Beschnitt der zweiten Achse wird von `overflow:hidden` abgeschnitten. Die
gemessenen Module bleiben quadratisch (233 px entsprechen exakt 30 mm ×
296/300), nur die Randmodule der gequetschten Achse entfallen. Ein beschnittener
Code scannt weiter, ein **gestreckter nicht** — und `contain` würde den Code auf
die kurze Achse schrumpfen lassen und die vom Verband reservierte Fläche
verschenken. Ein Verifikationscode soll die ihm zugewiesene Box füllen, nicht in
ihr verschwinden.

Der `qr`-Eintrag trägt **kein** `fit`-Feld (das Wire-Format kennt es für `qr`
nicht), deshalb ist `cover` hart kodiert und kein `fitFor(…, $default)`-Default.
Ein handgebautes Layout, das trotzdem ein `fit` mitbringt, darf den Code **nicht**
auf `contain` kippen — als Regressionstest festgenagelt
(`test_qr_box_ignores_a_fit_key_and_always_prints_cover`).

Der Docblock von `BadgeRenderService` behauptete bis P7 „**Every** picture in a
badge therefore gets its drawn rectangle computed in millimetres". Für den QR war
das **falsch**; er benennt die Ausnahme jetzt explizit, statt sie zu verschweigen.

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

### Weisser Seitenhintergrund (Nutzerentscheidung 2026-09-28) — **SOLL**

**Die Seite ist deckend weiss.** `BadgeRenderService` malt
`background-color:#ffffff` auf den **Karten-Wurzel-container** (den A6-grossen
`.card`-div, den `cardHtml()` zurückgibt), nicht auf einzelne Boxen. Das ist
**kein** optionales Detail und **keine** Transparenz: der Export liefert keine
transparenten Ausweise mehr.

**Warum es überhaupt gab.** dompdf malt von sich aus **keinen** Seitenhintergrund.
Vor der Entscheidung kam jedes Badge-PDF vollständig transparent aus dem Renderer
heraus; die Rasterung trug darum einen **Alpha-Kanal**, und wie die Seite am Ende
aussah entschied der **Konsument**, nicht der Rasterer — auf weissem Papier
korrekt, auf dunklem Grund schwarz, mit der schwarzen Badgeschrift unsichtbar
(gemessen: 0 sichtbare Tintepixel in einem 640 × 160-Textband). Genau darum
existiert die zweistufige Nachbearbeitung in
`scripts/pdf-to-png-vision.sh`.

**Gemessen, vorher/nachher** (A6-Einzelkarte, 200 dpi, 827 × 1165 px, ImageMagick
7.1.2-31 + Ghostscript 10.08.0, Stufe 1 = Rohraster, Stufe 2 = Skript-Postcondition;
Zahlen aus dem Skript-Lauf, Zelle „ECKALPHA“ = `-alpha extract` des Eckpixels (2,2)):

| Zustand | vorher: Kanäle | vorher: Eckpixel / Eckalpha | nachher: Kanäle | nachher: Eckpixel / Eckalpha |
|---|---|---|---|---|
| Porträt vorhanden | `srgba` | `#FFFFFF00` / **0** | `srgb` | `#FFFFFF` / **1** |
| Platzhalter-Silhouette | `srgba` | `#FFFFFF00` / **0** | `srgb` | `#FFFFFF` / **1** |
| kein Bild an der Stelle | `srgba` | `#FFFFFF00` / **0** | `srgb` | `#FFFFFF` / **1** |

Nachher trägt **keine** der drei Rasterungen einen Alpha-Kanal (`srgb`), und die
ganze Seite ist deckend: `-alpha extract` liefert `min=1 max=1 mean=1`, also
kein einziger halbwegs transparenter Pixel — auch nicht in der letzten Zeile oder
Spalte (der Füllrechteck-Operator lautet `0.000 0.002 297.638 419.528 re f`, die
gesamte A6-Fläche).

**Dass kein gedrucktes Pixel gewandert ist** (die eigentliche Regressionssorge,
weil die `fit`-/`QR`-Geometrie gerade vermessen ist): (a) der Diff des
**kompletten Content-Streams** vorher/nachher besteht in allen drei Zuständen aus
**genau zwei zusätzlichen Zeilen** — `1.000 1.000 1.000 rg` und die
Vollseiten-Füllung; jede Textposition, jedes Bild-XObject-Bildmaß und jeder
Clip-Pfad ist byte-identisch. (b) Der Pixelvergleich der Rasterungen
(vorher-Stufe 2 = auf Weiss geflatet, nachher-Stufe 1 = deckend gemalt) ergibt
**0 abweichende Pixel** bei RMSE 0 in allen drei Zuständen. (c) Die vorhandenen
Goldens bleiben unverändert grün: `BadgeImageFitGeometryTest`,
`BadgeRenderServiceTest` (inkl. der `fit`-/`QR`-Rechteck-Goldens) und die
übrigen Badge-Suiten, 138 Tests / 939 Assertions ohne Anpassung. Der neue
Regressionsschutz ist `backend/tests/Feature/BadgePageBackgroundTest.php`.

**Die Folge, die ausdrücklich bezahlt wird: die Transparenz ist weg.** Das war
der Grund, warum die Frage offen war — Folie, Glas und Siebdruck brauchen sie. Der
Nutzer hat das **wissend** entschied; diese Spec hält die Kosten fest, damit sie
niemand neu erfindet, weil sie niemandem aufgefallen ist.

**Was `pdf-to-png-vision.sh` daraus folgt — und was nicht.** Für **unsere**
Ausweise ist die Nachbearbeitung **überflüssig**: es gibt nichts mehr zu
entfernen, der Hintergrund ist deckend. Das Skript **behält seine Logik trotzdem
unverändert**, weil es **fremde** PDFs weiterhin robust machen muss, und weil
seine Postcondition („kein Alpha-Kanal, deckender Hintergrund") überhaupt erst
beweist, dass das so ist. **Die Postcondition ist deshalb KEINE Pflicht, die man
einsehen darf, sobald der Hintergrund steht** — wer sie aus unseren Ausweisen
heraus abbauen will, baut die Robustheit des Verifikationswerkzeugs ab. Der
zweistufige Pfad bleibt der Primärpfad, Fallback A (`gs -sDEVICE=png16m`,
ebenfalls deckend) und Fallback B (`sips`, kann keinen Alpha entfernen → Exit 3)
bleiben, wie sie sind.

### Was ein PDF-Test über „ist das Bild drin" **nicht** behaupten darf (2026-09-29)

`BadgeTest::test_export_pdf_contains_template_field_text_and_photo` behauptete
zwei Dinge und traf **keine** davon:

1. **Die Porträt-Fixture war unlesbar.** `storePortrait()` schrieb den Literal-
   String `'fake-portrait-bytes'`. dompdf kann das nicht dekodieren, ersetzt das
   `<img>` durch seinen eingebauten Broken-Image-Platzhalter (ein **SVG**, als
   Vektorbefehle gezeichnet) und bettet **gar kein** Bild-XObject für das Foto
   ein. „The portrait and the QR code are embedded as image XObjects" war nie
   wahr. Die Fixture ist jetzt eine **echte 8×8-PNG** (truecolour, colour type
   2) als Base64-Konstante — bewusst **nicht** per GD erzeugt: ein lebendes
   `GdImage` im Test-Prozess neben dompdfs GD-gestütztem PNG-Pfad rendert hier
   ein **leeres** Dokument (gemessen), weil beide Bibliotheken prozess-globalen
   GD-Zustand teilen.
2. **Die Assertion nannte dompdfs internen Zähler, und der ist GD-Build-
   abhängig.** dompdf vergibt `/I<n>` pro eingebettetem Bild und nimmt für ein
   PNG den **Alpha-Split-Pfad** (Maske + Bild, zwei Labels) — ausser bei
   colour type 2/4 oder einer Palette mit Bit-Tiefe **genau 4**
   (`Cpdf::addPngFromFile()`:
   `$is_alpha = in_array($color_type, [4,6]) || ($color_type == 3 && $bit_depth != 4)`).
   Der QR-Code ist eine **Paletten**-PNG, und ihre Bit-Tiefe wählt GDs
   Quantisierer, wenn `endroid/qr-code` `imagetruecolortopalette($im, false, 16)`
   aufruft: auf dem CI-Image **4** (also ein Label, `/I1 Do`, Test grün), hier
   mit libgd 2.3.3 **1** (also zwei Labels, `/I2 Do`, Test rot). Direkt
   reproduziert mit zwei synthetischen PNGs: 8-Farb-Palette (Tiefe 4) → `/I1 Do`,
   16-Farb-Palette (Tiefe 8) → `/I2 Do`. Ein Zählerstand aus einer Fremdbibliothek
   ist von einer Suite, die auf zwei GD-Builds läuft, nicht festnagelbar.

Der Test behauptet jetzt **nummernunabhängig** und **nennt beide Bilder**: der
Content-Stream zeichnet **genau zwei** Bilder (`preg_match_all('#/I\d+ Do\b#')`),
das Porträt steckt mit **eigenen** Pixelmaßen (8×8) als XObject im PDF, und der
512×512-Platzhalter steckt **nicht** drin. Mutation geprüft: Fixture zurück auf
`'fake-portrait-bytes'` → genau diese Assertion wird rot.

### Ein gemeinsamer PDF-Extraktor für die vier Badge-Suiten

Dass derselbe Test auf zwei GD-Builds unterschiedlich ausfiel, war nur die
sichtbare Hälfte. Beim Umschreiben fiel der **Extraktor** selbst auf: vier
Kopien desselben Helpers, alle mit
`rtrim(substr(...))` zwischen `stream\n` und `endstream`. `rtrim()` strippt auch
`\r`, ` ` und `\0` — und der Payload sind **komprimierte** Bytes, deren letztes
Byte jedes davon legal sein kann. Trifft es eins, geht ein Byte verloren,
`gzuncompress()` scheitert, das `@` schluckt die Warnung, und der Helper
liefert einen **leeren String**: der Test meldet dann ein fehlendes Feld
(`Expected: … To contain: Jane Doe`) statt eines kaputten Helpers. Der
Content-Stream des Ausweises endet auf `0d 0a` — genau das ist eingetreten, sobald
die Porträt-Fixture einen anderen Stream erzeugte.

Die Grenze ist `/Length N` (dompdf schreibt es auf jedes komprimierte Objekt);
danach ist der Extraktor eine **Implementierung**:
`tests/Support/ExtractsPdfContentStream.php`, von `BadgeTest`,
`BadgeRenderServiceTest`, `BadgeImageFitGeometryTest` und
`BadgePageBackgroundTest` geteilt.

Das Wörterbuch ist dabei zwischen dem letzten `obj` und dem `stream`-Keyword
begrenzt, **nicht** bei einer festen Bytezahl: sonst greift ein 200-Byte-Fenster
bei zwei dicht aufeinander folgenden Stream-Objekten in das `/Length` des
**vorherigen** Objekts, und ein First-Match-Regex liest den Payload mit der
falschen Länge. Genau das liefert
`ExtractsPdfContentStreamTest::test_it_does_not_mistake_the_endstream_terminator_for_a_stream_keyword`
— bzw. sein Geschwister mit zwei Streams. Derselbe Test deckt den Fallback
(`/Length` fehlt, Grenze über `\nendstream`) ab, den **kein** Badge-erreichen
kann, weil dompdf immer ein `/Length` schreibt: ein Zweig, den keine Karte
erreicht, ist ein Zweig, den niemand testet.

### Visuelle Verifikation des gerenderten PDF (`PDF-VISION`)

Ein Badge-PDF ist nur dann visuell prüfbar, wenn daraus **das richtige PNG**
wird. Bis zur Entscheidung vom 2026-09-28 malte der Renderer **keinen** weissen
Seitenhintergrund — dompdf ließ die Seite durch, damit hing das Aussehen der
Rasterung vom *Konsumenten* ab und nicht vom Rasterer, und die drei üblichen
Konsumenten lieferten drei verschiedene Bilder. Seitdem ist die Seite an der
Quelle weiss (siehe oben); das Skript ist trotzdem **kein** Einmal-Werkzeug
unserer Ausweise, sondern die Rasterung, die **jedes** PDF — auch ein fremdes,
transparentes — verlässlich macht. Genau darum existiert es als Skript mit
Postcondition statt als Einmal-Anweisung:

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
| **Primär** (zweistufig) | `magick` + `gs` | alle | frei | kein Alpha, Eckpixel **opak** (alpha 1), Tinte gemessen |
| **Fallback A** (einstufig) | `gs` allein | alle | frei | kein Alpha, Eckpixel **opak** (alpha 1) — aber **Tinte nicht messbar → Exit 3** |
| **Fallback B** | `sips` | **nur 1** | ~72 | **Alpha bleibt → Exit 3** (plus: Tinte nicht messbar) |
| keines | — | — | — | Exit 1, nennt die fehlenden Werkzeuge |

Jede Fallback-Route wird **laut** angekündigt (stderr, `>>> FALLBACK <<<`).
Fallback B endet mit Exit 3, weil `sips` keinen Alpha-Kanal entfernen kann —
ein grüner Lauf auf genau dem Bild, das diese Pipeline beseitigen soll, wäre
schlimmer als gar keiner.

**Fallback A endet seit der Tinte-Prüfung ebenfalls mit Exit 3, und das ist
eine absichtliche Verschärfung.** Ohne `magick` ist die *Deckung* weiterhin nur
zu überspringen (sie prüft einen Defekt, den der `gs`-Weg nicht erzeugt — `png16m`
ist ein deckendes Device), die *Tinte* dagegen nicht: sie ist eine Eigenschaft der
Quelldatei, die kein Rasterweg einbauen oder umgehen kann, und `sips` kann keine
Statistik. „Nicht messbar“ heisst dort deshalb **Abbruch, nicht Durchwinken**.
Gemessen (macOS, PATH ohne `magick`, aber mit `gs`): ein **gültiges** Badge
(QR + zwei Textzeilen) endet mit Exit 3 und der Meldung *„die Tintenmenge in
badgefix-step2-01.png war nicht messbar — es gibt kein magick im PATH“*. Die
Meldung nennt den Grund und den Unterschied zum Bild-Befund, sie ist also von
einem echten Postcondition-Fehler zu unterscheiden. Vor der Tinte-Prüfung lief
derselbe Fall mit Exit 0 durch.

### Was die Postcondition prüft — und was ausdrücklich nicht

Es sind **zwei** Postconditionen, und sie prüfen verschiedene Eigenschaften:

1. **Deckung** — kein Alpha-Kanal und ein **deckender** Hintergrund. Sie prüft
   die *Deckung* und **nicht** dessen Farbe. Bewusste Entscheidung, keine
   Vereinfachung — und sie hat einen Preis, der hier stehen muss, weil er eine
   echte Grenze des Verfahrens ist.
2. **Tinte** — die Graustufen-Standardabweichung der Seite muss über
   `INK_SIGMA_MIN = 0.001` liegen. Sie beantwortet die Frage, die die Deckung
   nicht beantworten kann: **steht auf der Seite überhaupt etwas?**

**Gemessen an zwei Leerseiten** (`magick`-Pipeline, 827 × 1165 px, eine Seite,
200 dpi; erzeugt mit `magick -size 827x1165 xc:<farbe>`), gegen die drei
Skript-Stände dieser Woche:

| Seite | Skript-Stand | Exit | gemeldete Fehler |
|---|---|---|---|
| komplett **weiss** | alt (Weissheit + `*a*`-Muster) | **3** | „hat noch einen Alpha-Kanal“ **und** „Eckpixel ist nicht weiss“ |
| komplett **schwarz** | alt | **3** | dito |
| komplett weiss | Graustufen-Fix (`e402412`) | **0** | — |
| komplett schwarz | Graustufen-Fix | **3** | „Eckpixel ist nicht weiss“ |
| komplett weiss | **nur Deckung** (Opazität, `caf6bed`) | **0** | — |
| komplett schwarz | **nur Deckung** | **0** | — |
| komplett weiss | **heute** (Deckung **+** Tinte) | **3** | „ist LEER — die Graustufen-Streuung der Seite ist 0“ |
| komplett schwarz | **heute** | **3** | dito |
| gültiges Badge (QR + Text) | **heute** | **0** | — |

Drei Dinge sind daraus abzulesen, und alle drei sind Folgen *derselben*
Entscheidung:

1. **Eine opake, aber leere Seite galt bis zur Tinte-Prüfung als erfüllt**
   (Exit 0) — die Kehrseite von „Farbe ist nicht Teil der Postcondition“: eine
   deckende Farbe ist gültig, also war eine deckende Leere es auch. **Das ist
   behoben**: heute endet die Leerseite mit Exit 3, und zwar unabhängig von der
   Hintergrundfarbe (weiss, schwarz, `#1b2a3c`, `#f2e9d8` — alle vier gemessen
   mit σ **exakt 0**). Für `BadgeExportService` heisst das konkret: ein Verband
   exportiert 500 Ausweise, **eine A6-Karte pro Antrag** (`html()` loopt über
   `$applications` und ruft je Antrag `cardHtml()`, `BadgeRenderService.php:242-248`;
   `renderPdf()` setzt A6 porträt, `:215-223`), und niemand sieht auf
   jede einzelne. Ein Template mit dunklem Volldruck-Hintergrund, dessen Inhalt
   nicht gerendert wurde, lieferte 500 unbrauchbare Seiten **ohne jede
   Reaktion**.
2. **Der alte Stand hätte eine Leerseite nie durchgelassen** — und zwar mit
   der *falschen* Begründung. Er meldete „hat noch einen Alpha-Kanal“, weil sein
   Muster `*a*` den Buchstaben `a` in „**g**r**a**y“ traf (eine Seite ganz ohne
   Bild-XObject rastern als `gray`). Zusätzlich meldete er „Eckpixel ist nicht
   weiss“ auf einem Eckpixel, dessen Wert er korrekt als `#FFFFFF` ausgab — die
   `txt:`-Zeile eines Graustufenbildes lautet `gray(255)` und nicht
   `255,255,255`, das Muster konnte also strukturell nie greifen. Zwei Fehler,
   beide gegen dieselbe Tatsache: die Seite war in Ordnung.
3. **Die Umstellung auf Opazität ist die kleinere der beiden Korrekturen.** Die
   Graustufen-Falle betraf den *wichtigsten* Fall (Text ohne Bild, also die
   normale Badge-Seite), die Weissheit den *seltensten* (deckendes
   Volldruck-Badge). Die zweite Korrektur beseitigt einen Fehlalarm, die erste
   einen Fail-open: eine unbekannte Kanalkennung gilt jetzt als **nicht
   feststellbar** (Exit 3) statt als „kein Alpha“.

**Die zweite Postcondition ist implementiert — die Tinte.** „Der Hintergrund ist
eine deckende Farbe“ und „der Hintergrund ist weisses Papier“ sehen am Eckpixel
identisch aus — der Unterschied ist am Rand schlicht nicht mehr rekonstruierbar.
Die Weiss-Prüfung konnte wenigstens einen deckenden schwarzen Hintergrund
*melden*; diese Fähigkeit ging mit der Umstellung auf Opazität verloren, und
genau dafür steht jetzt `ink_sigma` daneben: es misst die **Verteilung** der
Pixel über die ganze Seite und ist damit unabhängig vom Farbwert des
Hintergrunds. Ausdrücklich **keine** Farbregel im Eckpixel — der Eckpixel ist
per Konstruktion die Ecke und damit die am wenigsten repräsentative Stelle der
Seite.

**Was gemessen wird, und warum der Beschnitt um 1 % dazugehört.** Gemessen wird
`magick <datei> -colorspace Gray -gravity center -crop '99%x99%+0+0' +repage
-format '%[fx:standard_deviation]' info:` auf den Stufe-2-PNGs, **nach** dem
Beschnitt. Der Beschnitt ist **keine Kosmetik, er ist die Voraussetzung**: die
antialiasierte Rasterkante des gemalten Seitenhintergrunds ist sonst Teil der
Messung. Gemessen auf dem **Primärweg des Skripts** (`magick -density N` →
`-alpha remove -alpha off`), σ **ohne** Beschnitt für eine komplett schwarze
Leerseite:

| Dichte | 72 | 100 | 150 | 200 | 300 | 400 |
|---|---|---|---|---|---|---|
| σ ohne Beschnitt (schwarz) | 0,01300 | 0,01103 | **0** | 0,00655 | **0** | 0,00552 |
| σ ohne Beschnitt (`#1b2a3c`) | 0,01095 | 0,00930 | **0** | 0,00552 | **0** | 0,00465 |
| σ ohne Beschnitt (`#f2e9d8`) | 0,00109 | 0,00092 | **0** | 0,00055 | **0** | 0,00046 |
| σ **mit** Beschnitt (alle drei) | **0** | **0** | **0** | **0** | **0** | **0** |

Ohne den Beschnitt passieren die schwarze und die dunkelblaue Leerseite bei
**72, 100, 200 und 400 dpi** die Schwelle (Exit 0) — und die cremefarbene bei
72 dpi. An **150 und 300 dpi** landet die Kante genau auf dem Pixelraster und σ
ist exakt 0: **die Dichte entscheidet mit, nicht die Farbe** — dieselbe
Leerseite ist bei der einen Dichte unauffällig und bei der anderen ein Befund.
Eine Seite, auf der **nichts** gemalt wurde,
zeigt das Messartefakt nie (σ = 0 mit und ohne Beschnitt) — es braucht einen
**gemalten** Seitenhintergrund, damit die Kante überhaupt entsteht. Der Beschnitt
ist relativ (1 %), damit er bei jeder Dichte greift; **was er kostet, ist
gemessen**: bei A6 / 200 dpi (827 × 1165 px, Beschnitt auf 819 × 1153) sind es
**4 Pixel links/rechts = 0,51 mm** und **6 Pixel oben/unten = 0,76 mm** — nach
gemessen, indem ein einzelnes schwarzes Pixel zeilen- und spaltenweise von der
Kante nach innen geschoben wurde (Zeile 5 unsichtbar, Zeile 6 sichtbar; Spalte 3
unsichtbar, Spalte 4 sichtbar). Die vier Eckpixel eines A6-Blatts sind für die
Tinte also **nachweislich unsichtbar** (σ_crop = 0 gegen σ_ohne = 0,00204).

**Die Schwelle ist eine rechenbare Zusage, keine gesetzte Zahl.** Für vollschwarze
Tinte gilt σ = `sqrt(k·(n−k))/n`, mit `k` = dunkle Pixel und `n` = Pixel im
beschnittenen Bild — nachgerechnet an eigenen Fixtures mit *exakt* bekannter
Pixelzahl (`magick -size 827x1165 xc:white -fill black -draw "point …"`), A6 /
200 dpi, n = 944 307:

| dunkle Pixel `k` | 1 | 4 | 9 | 25 | 400 |
|---|---|---|---|---|---|
| σ gemessen | 0,00102907 | 0,00205813 | 0,00308719 | 0,00514527 | 0,0205770 |
| `sqrt(k(n−k))/n` | 0,00102907 | 0,00205813 | 0,00308719 | 0,00514527 | 0,0205770 |

Abweichung ≤ 3,2 · 10⁻⁸ absolut bzw. ≤ 0,0004 % relativ — und die ist kleiner
als die Ausgabegenauigkeit der Messung selbst (`%[fx:…]` druckt 8
Nachkommastellen). **`INK_SIGMA_MIN = 0.001` ist damit auf A6/200 dpi genau „ein
einziges schwarzes Pixel auf der Seite“**, und ein Leser kann es nachrechnen.
Wichtiger ist aber, wo die Schwelle relativ zum *Problem* liegt — und das ist
**nicht** die Rundungsgrenze:

| Dichte | 72 | 100 | 150 | 200 | 300 | 400 |
|---|---|---|---|---|---|---|
| σ eines **einzigen** schwarzen Pixels | 0,002855 | 0,002058 | 0,001372 | **0,001029** | 0,000686 | 0,000514 |
| Verhältnis Schwelle / 1-Pixel-Linie | 0,35× | 0,49× | 0,73× | **0,97×** | 1,46× | 1,94× |

Die Schwelle liegt also **auf** der Ein-Pixel-Linie, nicht 10⁴ darüber: bei
200 dpi 2,8 % darunter, bei 300 und 400 dpi 1,5- bzw. 1,9-fach darüber (dort
sind ~2 bzw. ~4 dunkle Pixel nötig). Das ist die richtige Stelle — sie ist der
kleinste darstellbare Inhalt — und sie ist **fail-closed**: bei hoher Dichte
wird ein Ein-Pixel-Artefakt *abgelehnt*, nicht durchgewinkt. Der Abstand zum
realen Inhalt ist der eigentliche Puffer: `renderQr()` läuft **unbedingt**
(`cardHtml()`, `BadgeRenderService.php:296`), und die kleinste erlaubte QR-Box
sind 10 × 10 mm (`MIN_BOX_W_MM`/`MIN_BOX_H_MM`,
`BadgeTemplateController.php:84,86`). Gemessen: eine Seite mit **nur** dem QR
liest σ = **0,041** (Primärweg) bzw. **0,051** (`gs -sDEVICE=png16m`) — das ist
der strukturelle Boden, 41- bis 51-fach über der Schwelle.

**Farbraum-Stabilität, gemessen statt behauptet.** Die Graustufen-Konvertierung
ist hier die **gamma-kodierte** Rec709-Luma ohne Linearisierung (gemessen:
`#3e5d8e` → 89,94, exakt `0,2126·62 + 0,7152·93 + 0,0722·142`). Für die
Ein-Pixel-Linie ist das **irrelevant**, weil Schwarz und Weiss unter jeder
Luminanzgewichtung exakt auf 0 und 1 fallen: gemessen **0,00102907** bei
`-colorspace Gray`, `-grayscale Rec709Luminance`, `-depth 8` und
`-type TrueColor`. Bei *farbiger* Tinte unterscheiden sich die Operatoren
allerdings um bis zu 16 % (`#1b2a3c`: 0,0294 gegen 0,0341) — das ist erst dann
entscheidend, wenn eine Seite ohnehin innerhalb von 16 % der Schwelle liegt.

**Die Leerseite ist nicht „fast 0", sie ist exakt 0.** 24 Messungen, vier
Füllfarben (`#ffffff`, `#000000`, `#1b2a3c`, `#f2e9d8`) über sechs Dichten
(72…400 dpi), **und** dieselben Fixtures auf einer zweiten Toolchain: Linux,
bash 5.2.37, ImageMagick **7.1.2-15**, Ghostscript 10.05.1 gegen macOS,
ImageMagick 7.1.2-31, Ghostscript 10.08.0 — **32 von 32 Messwerten bit-gleich**
(inklusive der ungleichen Werte 0,0513544 / 0,0664243 / 0,00992347). Die
Grenze dieser Aussage: beide Läufe waren aarch64, die Gleichheit über
Prozessorarchitekturen ist damit **nicht** gemessen.

**Graustufen, nicht Farbe.** Ein gleichförmig schwarzer und ein gleichförmig
weisser Hintergrund fallen in Graustufen auf **denselben** exakten Nullwert —
darum genügt **eine** Schwelle für beide. Farb-σ reagiert dagegen auf den
*Farbton* einer Fläche statt darauf, ob gezeichnet wurde (eine vollflächig
zweifarbige Leerseite: 0,108 grau gegen 0,397 farbig, Faktor 3,7, obwohl auf
beiden nichts steht).

**Zwei Grenzen, die bleiben — beide gemessen, keine davon ausgelassen.**

* **σ ist eine Stufenfunktion der Pixelzahl, keine kontinuierliche Tintenmenge.**
  Unterhalb einer gut sichtbaren Textgrösse entscheidet das Pixelraster, nicht
  die Schriftgrösse. Ein einzelnes 0,5-pt-Zeichen liest 0 bei 72 und 100 dpi,
  0,00137 bei 150, 0,00103 bei 200, **0,00069 bei 300** (→ LEER) und 0,00089 bei
  400 (→ LEER). Dasselbe Zeichen bei 1 pt liest dagegen 0,00126…0,00285 und
  besteht überall, ein ganzes 1-pt-Wort (`Presse`, Helvetica) 0,0027…0,0041.
  **Die Zone, in der das Urteil mit der Dichte kippt, liegt also bei einem
  einzelnen Halbpunkt-Glyphen, nicht bei einem 1-pt-Wort** — und sie ist für
  unsere Ausweise gegenstandslos, weil `renderQr()` unbedingt läuft. Wer die
  Stufe strenger braucht, muss die QR-Mindestgrösse erzwingen (Layout-Regel,
  `MIN_BOX_*`), nicht die Schwelle tiefer legen: σ = 0,0005 entspräche **einem
  Viertel** eines Pixels, und ein Vertrag auf Bruchteile von Pixeln ist keiner.
* **σ misst Tinte, nicht Lesbarkeit.** Der Kontrast ist eine **zweite**
  Postcondition und wird hier bewusst nicht erfunden — sie braucht eine
  Farbregel, und die hat der Eckpixel nicht her. Ein Kontrast-Urteil im
  Auftragsgang dieser Pipeline wäre eine eigene Entscheidung; die
  Vision-Analyse beurteilt die gerenderte Seite ohnehin.

**Und eine dritte, die erst diese Messung gefunden hat: der Beschnitt ist kein
Freiraum für Dekoration.** σ misst Tinte **irgendwo** in der mittleren Zone,
nicht den Ausweis. Eine Seite, auf der **nur** ein 1 pt dicker Dekorrahmen
steht — 1 pt vom Blattrand eingerückt, **kein Text, kein QR** — misst σ =
0,049 (Rahmen ab 1 pt) bis 0,111 (ab 3 pt) und gilt damit als „Inhalt“
(Exit 0). Der Rahmen maskiert genau den Fall, den die Postcondition fangen soll,
wenn der Karteninhalt fehlschlägt und die Vorlage ein Rahmenmotiv mitbringt.
Umgekehrt gilt: **ein gültiger Ausweis kann seinen Inhalt nicht im verworfenen
Band verstecken** — das Band ist 0,51 mm × 0,76 mm tief, und das kleinste legal
platzierbare Element ist ein 3 mm hohes Textfeld (`MIN_TEXT_H_MM`,
`BadgeTemplateController.php:82`) bzw. die 10-mm-QR-Box.

Der Contract für eine Vision-Analyse bleibt davon unberührt: die Befundtabelle
gibt zu jeder Seite Kanal, Pixelwert, Eckalpha **und** den σ-Wert aus, und die
Vision-Analyse beurteilt die gerenderte Seite ohnehin.

**Gemessene Zahlen, Stand VOR der Hintergrund-Entscheidung** (A6,
2-Karten-Badge-PDF aus `BadgeRenderService::renderPdf`, 13 970 Byte, 2 Seiten;
macOS, ImageMagick 7.1.2-31, Ghostscript 10.08.0). Sie stehen hier, weil sie den
Defekt **beweisen**, den die Entscheidung beseitigt — der heutige Stand steht in
„Weisser Seitenhintergrund" oben:

| | Maße | Kanäle | Eckpixel (2,2) | Eckalpha |
|---|---|---|---|---|
| Stufe 1 `magick -density 200` | **827 × 1165 px** | `srgba` (PaletteAlpha) | `#FFFFFF00` — weiss, alpha 0 | **0** — nicht erzwungen |
| Stufe 2 `-background white -alpha remove -alpha off` | 827 × 1165 px | `srgb` (kein Alpha) | `#FFFFFF` — literal weiss | **1** — opak |
| `sips -s format png` | 298 × 420 px | `srgba` | `#00000000` — schwarz, alpha 0 | **0** |

Die dritte Zeile ist eine **Fehlerdiagnose**, kein gültiger Pfad: sie zeigt, was
Fallback B erzeugt, und begründet dessen Exit 3. Auf einem frisch gerenderten
2-Seiten-Badge liefert der heutige Stand für beide Seiten `srgb` mit Eckalpha 1
und endet mit Exit 0; die Stufe-1-Zeile ist die transparente Vorstufe, nicht das
Ergebnis.

**Was in Stufe 1 wirklich dasteht** — gemessen **vor** der
Hintergrund-Entscheidung, an einem PDF ohne gemalten Seitenhintergrund (nicht die
verbreitete Vermutung): 89,8 %
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

**Historisch: die Empfehlung, die eine Produktfrage offen liess** (vor
2026-09-28). `background-color: #ffffff` auf `body` bzw. `@page` wäre die
einfachere und robustere Lösung gewesen — der Seitenhintergrund wäre im PDF
selbst weiss und die Nachbearbeitung entfallen. Sie wurde zurückgestellt, weil
sie den **Render-Vertrag** ändert (jeder Ausweis bekäme einen gemalten
Hintergrund, `BadgeRenderService::cardHtml` wird zum Render-Vertrag, gegen den
die Tests prüfen) und weil sie eine **Produktfrage** berührt, die nicht
technisch ist: ein Ausweis mit weiss gemaltem Hintergrund ist nicht mehr
transparent, was für Ausweisspiele mit farbigem oder transparentem Untergrund
(Folien, Glas, Siebdruck) eine echte Einschränkung ist. **Entschieden am
2026-09-28, wissend und mit genau diesem Preis** — umgesetzt als
`BadgeRenderService::PAGE_BACKGROUND_STYLE` auf dem Karten-Wurzelcontainer
(siehe „Weisser Seitenhintergrund" oben). Der Abschnitt ist als
Entscheidungshistorie stehen geblieben, damit der Grund für die
Raster-Nachbearbeitung und der Preis der Entscheidung nicht verloren gehen.

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
