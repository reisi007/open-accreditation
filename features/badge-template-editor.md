# Badge-Template-Editor — Raster + konfigurierbare Labels (P4)

**Status: SOLL-Spezifikation — KORRIGIERT am 2026-09-27, Rückbau abgeschlossen
am 2026-09-27.** Der Editor ist in **FE1–FE4 implementiert** (`a17332b`,
`eb88cbc`, `8634e40`, `68f52d7`); die Entscheidung „Raster + konfigurierbare
Labels" war bereits weitgehend der Ist-Stand und ist es nach dem Rückbau der
Zieh-Interaktion vollständig (siehe „Rückbau der Zieh-Interaktion").

## Korrektur vom 2026-09-27

**Anlass (die tatsächliche Entscheidung):** User-Entscheidung **2026-09-27**
„**Raster + konfigurierbare Labels**" — der Editor bietet **kein** Drag & Drop.
Felder werden **nicht** frei per Maus gezogen; Position und Größe entstehen aus
einem Raster plus konfigurierbaren Label-Eigenschaften. Enthält die Umsetzung
des offenen Punkts **P4-F4** (`qr`-Layout-Feld, siehe `badges-qr.md`).

**Warum dieses Dokument bis eben falsch war:** Es zitierte als Anlass wörtlich
„User-Entscheidung ‚VOLL frei positionierbar'" und hat die daraus abgeleitete
Drag-&-Drop-Spec über die Etappen hinweg fortgeschrieben. **Diese
User-Entscheidung existiert nicht** — sie wurde nie getroffen. Wer dieses
Dokument als gültige Spec las, bekam einen Zielzustand serviert, für den es
weder eine Entscheidung noch eine belegbare Absicht gab. Der Satz bleibt hier
nur als Zitat stehen, damit der Fehler nachvollziehbar ist — er ist **keine
gültige Entscheidung**.

**Korrigierte Fehlannahme (zwei Fehler in einer Kette):** Aus dem Umstand, dass
`frontend/package.json` **keine** Drag-&-Drop-Bibliothek enthält, folgt
**nicht**, dass Drag & Drop nie umgesetzt wurde. FE3 (`8634e40`) hat es mit
**nativen Pointer Events** umgesetzt (`setPointerCapture` + `onPointerDown/
Move/Up` in `BadgeCanvas.tsx`) — dafür wird keine Bibliothek gebraucht. Die
„Raster + konfigurierbare Labels"-Entscheidung widerrief dieses Ziehen
(„Der Editor bietet **kein** Drag & Drop"); der Rückbau ist abgeschlossen.

### Geltungsbereich dieser Korrektur

- **Weiter gültig, unberührt:** Feldtypen, Validierung, Mindestgrößen, Bounds,
  Elementtyp `image` (Datenmodell, Quellen, Sicherheit), PDF-Render-Kontrakt,
  Invarianten. Hängen nicht an der Editor-Interaktion.
- **Superseded** (setzten „frei positionierbar per Maus" voraus): „Zielbild",
  der Interaktions-Teil von „Editor-UX (SOLL)", Phasing-Etappe 2 und Etappe 3
  sowie der FE3-Teil der `image`-Einplanung. An Ort und Stelle markiert.
- **Offen, hier bewusst nicht entschieden:** siehe „Offene Fragen".

**Erweiterung (User-Entscheidung 2026-08-26, IST — implementiert):** Der
Editor unterstützt neben Datenfeldern auch **selbst platzierte Bilder**
(Logos, Vereinswappen, Hintergründe) — neuer Elementtyp `image`, platziert
mit `x/y/w/h` in mm und wählbarer Bildquelle (Upload oder
Mandant-Brand-Bild). Backend-Infrastruktur (Migration `badge_images` +
Upload-/Delivery-API), Validierung (`src`-Union + Mandanten-Scoping der
`image_id`, RV-S2) und Renderer-Zweig (Base64-Embed, `object-fit` contain/
cover) sind umgesetzt; die Editor-Integration ist in FE2/FE3 mit umgesetzt.
Details im Abschnitt „Elementtyp `image`".

## Ist (verifiziert, Stand dieser Spec)

| Baustein | Ort | Ist-Zustand |
|---|---|---|
| Schema/Validierung | `Api/Admin/BadgeTemplateController` (`layout`-Rules) | Schema v2: Whitelist inkl. `qr`/`team`/`vest_number`/`image`; A6-Bounds (`x+w ≤ 105`, `y+h ≤ 148`), Mindestgrößen (Text 5×3, Box 10×10 mm), max. ein `qr`-Entry; `image.src` Union-Validierung inkl. Existenz + Mandanten-Scoping der `image_id` (RV-S2) |
| Rendering | `BadgeRenderService` (A6 `105 × 148 mm`, Konstanten) | Absolute `div`s (`left/top/width/height` in mm, `font-size` pt, `text-align`), Werte via `e()` escaped; `photo` special-cased (Base64 aus privater Disk, `object-fit: cover`); QR an Entry-Position oder fix unten rechts (Fallback); **`-`Entry** (Base64 aus privater Disk, `object-fit` contain Default/cover, leere Box bei fehlender Quelle) |
| Datenmodell | Migrationen `badge_templates` + `badge_images` | `layout` ist Laravel-`json`-Spalte; `badge_images` (id, `mandant_id` FK, `path`, `mime`, `original_name`, timestamps) |
| API | `BadgeImageController` (`/api/admin/badge-images`) | `GET` (Liste, mandantengescopet), `POST` (Upload: `mimes:jpeg,png,webp\|max:2048` + 2000×2000 px, private Disk `badge-images/{slug}/…`), `DELETE` (nur eigener Mandant), auth-gated Delivery `GET /{id}/file` |
| Frontend | `BadgeTemplatesPage` → Modal → `BadgeTemplateForm` + `BadgePropertiesPanel` | Palette (10 Typen inkl. `image`) + `BadgePropertiesPanel` mit **Zahleneingaben X/Y/W/H (mm), Schriftgröße, Ausrichtung, Bildquelle (Upload/Brand) + Fit-Umschalter**; `BadgeCanvas` als **Vorschau mit Auswahl + Pfeiltasten-Nudge** über dem **sichtbaren 5-mm-Raster-Overlay** (`backgroundImage`, `CANVAS_GRID_STEP_MM = 5`) — das ist „Raster + konfigurierbare Labels": die **absoluten** Koordinaten entstehen ausschließlich aus den Panel-Zahleneingaben, der Canvas verschiebt relativ um 1 mm (Shift = 5 mm) und sonst nichts (Maus-Drag, Eckgriffe und magnetische Guides wurden am 2026-09-27 zurückgebaut, das Nudge am selben Tag wiederhergestellt). zod-Schema als Factory-Funktion (`badgeTemplateFormUtils.ts`); Frontend-API-Funktionen `listBadgeImages`/`uploadBadgeImage`/`deleteBadgeImage`/`badgeImageFileUrl` an echte Endpoints verdrahtet |

## Zielbild

> **⚠️ SUPERSEDED (2026-09-27).** Der folgende Zielzustand setzte die
> Entscheidung „VOLL frei positionierbar" voraus. Er ist **nicht mehr der
> Soll-Zustand** und wird **nicht** weiterverfolgt. Er bleibt nur als
> Beschreibung des umgesetzten Standes FE3/FE4 im Repo lesbar — nicht als
> Ziel. Der neue Zielzustand („Raster + konfigurierbare Labels") ist noch
> **nicht ausformuliert**, siehe „Offene Fragen".

Template-Autor positioniert Felder direkt auf der DIN-A6-Vorschau: ziehen,
Griffpunkte zum Skalieren, Auswählen und Eigenschaften im Panel bearbeiten.
Das PDF-Ergebnis entspricht exakt der Vorschau (WYSIWYG in mm).

---

## Template-Schema v2

> **Status nach der Korrektur vom 2026-09-27: gilt für den Renderpfad
> unverändert, für den Editor offen.** Belegbar ist: der PDF-Renderer
> (`BadgeRenderService::renderField`/`renderQr`) liest `x/y/w/h` direkt aus dem
> `layout`-Array und emittiert `position:absolute;left/top/width/height` in mm —
> er kennt die Editor-Interaktion nicht. Dasselbe gilt für die serverseitige
> Validierung (Bounds/Mindestgrößen) in `BadgeTemplateController`. **Positionen
> in mm werden also auch ohne Maus-Ziehen gebraucht**, das Schema selbst ist
> kein Drag-&-Drop-Artefakt.
>
> **Ob der Editor `x/y/w/h` weiterhin als freie mm-Werte schreibt oder künftig
> Raster-Zellkoordinaten (z. B. `row`/`col`/`span`) mit serverseitiger Umrechnung
> persistiert, ist eine offene Design-Entscheidung** — im vorliegenden Dokument
> bewusst nicht getroffen und nicht als gültig behauptet. Siehe „Offene Fragen".
> Bis zur Entscheidung gilt: das persistierte Format ist **unverändert** `x/y/w/h`.

Erweiterung bleibt **additiv und abwärtskompatibel** — kein Versionsflag, keine
Migration (`layout` bleibt `json`-Array):

```json
[
  { "field": "name",     "x": 10, "y": 12,  "w": 80, "h": 10, "size": 18, "align": "left" },
  { "field": "photo",    "x": 5,  "y": 25,  "w": 25, "h": 30, "size": 12, "align": "left" },
  { "field": "qr",       "x": 78, "y": 121, "w": 22, "h": 22 },
  { "field": "image",    "x": 5,  "y": 130, "w": 20, "h": 12, "src": { "kind": "brand", "ref": "logo" } },
  { "field": "image",    "x": 40, "y": 130, "w": 15, "h": 12, "src": { "kind": "upload", "image_id": 17 }, "fit": "cover" }
]
```

- **Neuer Eintragstyp `qr`** (löst P4-F4): eigener Array-Entry mit
  `x/y/w/h` in mm; `size`/`align` sind bei `qr` erlaubt, aber bedeutungslos
  (Renderer ignoriert sie). **Maximal ein `qr`-Entry pro Template.**
  Fehlt der Entry, gilt weiterhin die Fixposition unten rechts
  (`right: 5mm; bottom: 5mm; 20 × 20 mm`) — bestehende Templates rendern
  unverändert weiter (siehe PDF-Render-Kontrakt).
- **Neuer Eintragstyp `image`** (User-Entscheidung 2026-08-26, **IST —
  implementiert**, war hier als „SOLL — noch nicht implementiert" geführt):
  platzierbares Bild (Logo, Vereinswappen, Hintergrund) als eigener Array-Entry
  mit `x/y/w/h` in mm und Pflicht-Quelle `src`; `fit` optional
  (`contain`/`cover`). `size`/`align` sind erlaubt, aber bedeutungslos
  (Renderer ignoriert sie, wie bei `qr`). Mehrere `image`-Entries sind erlaubt
  (Co-Branding); Details im Abschnitt „Elementtyp `image`".
- **Koordinaten:** `x/y` = linke obere Ecke in mm von oben links der
  A6-Fläche (105 × 148 mm); `w/h` in mm; `size` in pt; `align` wie Ist.
  Neu: `x + w ≤ 105`, `y + h ≤ 148` wird erzwungen (Ist prüft nur `≥ 0`).
- **Mindestgrößen statt `≥ 0`:** Text-Felder `w ≥ 5, h ≥ 3`;
  `photo`/`qr`/`image` `w ≥ 10, h ≥ 10`. Ein 0×0-Feld (heute valide!) rendert
  ein unsichtbares div und soll künftig abgelehnt werden.
- **Neue optionale Datenfelder** (Whitelist-Erweiterung, siehe unten):
  `team`, `vest_number`. Eine Aufteilung Vor-/Nachname ist bewusst **nicht**
  Teil dieser Spec (offene Datenschema-Entscheidung, siehe Feldtypen).

## Feldtypen

**Ist (verifiziert, `BadgeRenderService::valueFor`):**

| `field` | Quelle |
|---|---|
| `name` | `application.user.name` (**ein** Vollnamen-Feld — `users` hat keine `first_name`/`last_name`-Spalten) |
| `category` | `application.accreditation.category.name` |
| `event` | `application.accreditation.event.title` |
| `date` | Event-Datum (`d.m.Y`) |
| `photo` | Portrait (`user.media`, `type='portrait'`, private Disk) |
| `status` | deutsche Status-Beschriftung |

**SOLL-neu (Datenquellen heute bereits vorhanden, verifiziert):**

| `field` | Quelle | Verhalten bei fehlender Quelle |
|---|---|---|
| `team` (Verein) | `application.accreditation.team.name` (`accreditations.team_id` ist nullable) | leerer String (wie `event` ohne Event) |
| `vest_number` | `application.user.vest_number` (nullable String, Westennummer — klassisches Ausweis-Feld) | leerer String |

**Bewusst offen (Entscheidung vor Umsetzung):** `first_name`/`last_name`
erfordern entweder eigene Users-Spalten (Migration) oder definierte Split-
Logik auf `users.name` — beides hat Migrations-/Datenqualitäts-Folgen und ist
hier nicht vorgegeben. Bis dahin bleibt `name` das einzige Namens-Feld.

## Elementtyp `image` — platzierte Bilder (IST)

**Status: IST (User-Entscheidung 2026-08-26) — Backend und Editor
implementiert.** Eigene Bildelemente ohne Datenfeld-Bezug, identische
Geometrie-Mechanik wie die Datenfelder. Backend-Infrastruktur (Migration +
API), Controller-Validierung inkl. Mandanten-Scoping der `image_id` (RV-S2),
Renderer-Zweig (Base64, `object-fit`) und die Editor-UI (Quellenwahl,
Upload-Flow) sind umgesetzt.

> **⚠️ Einschränkung nach der Korrektur vom 2026-09-27:** Die *Platzierung per
> Maus* (Drag & Drop auf Bildelemente) ist superseded. Das Element selbst, sein
> `x/y/w/h`-Geometrie-Vertrag, die Quellenwahl und die Renderer-Regeln bleiben
> gültig — der Renderer positioniert Bildelemente wie Datenfelder anhand der
> mm-Werte, unabhängig davon, wie der Editor sie setzt.

### Eigenschaften

| Property | Typ | Bedeutung |
|---|---|---|
| `field` | Literal `"image"` | eigener Entry-Typ analog `qr` (kein Datenfeld) |
| `x`, `y` | number, mm | linke obere Ecke auf der A6-Fläche (wie Datenfelder) |
| `w`, `h` | number, mm | Boxgröße; Bounds `x+w ≤ 105`, `y+h ≤ 148` (hart) |
| `src` | object, **required** | Bildquelle — discriminated union, siehe unten |
| `fit` | `"contain"` \| `"cover"`, optional | Seitenverhältnis-Handling, **Default `contain`** |
| `size`, `align` | — | erlaubt, aber bedeutungslos (Renderer ignoriert sie, wie bei `qr`) |

### Bildquellen (`src`) — discriminated union

- `{ "kind": "brand", "ref": "logo" | "header" }` — verweist auf das
  hochgeladene Logo/Header-Bild des Mandanten. Ist-Anker (verifiziert):
  Spalten `mandants.logo_path`/`header_path`; Dateien auf der `private`-Disk
  unter `mandants/{slug}/logo|header.{ext}` (`MandantMediaService`); Delivery
  auth-gated via `/api/mandant/logo|header` bzw.
  `/api/admin/mandants/{mandant}/logo|header`, öffentlich
  `/api/portal/mandant/logo|header` (`PortalMediaController`).
- `{ "kind": "upload", "image_id": <int> }` — verweist per ID auf ein
  mandanteneigenes Badge-Bild aus der neuen Upload-Infrastruktur (unten).
- **Explizit KEINE Render-Quelle:** statische Brand-Files unter
  `frontend/public/` (Fallback-Logos, `03-caddy-brand-files.md`) — sie leben
  im SPA-Dist/Caddy-Layer und sind aus dem Backend nicht adressierbar. Wer das
  Fallback-Logo im Ausweis braucht, lädt es als Badge-Bild hoch.

### Upload-Infrastruktur (neu, SOLL)

- Portable Migration `badge_images`: `id`, `mandant_id` (FK), `path`, `mime`,
  `original_name`, Timestamps; Pfadmuster `badge-images/{slug}/{uniq}.{ext}`
  auf der `private`-Disk (Präzedenz: `mandants/{slug}/…` im
  `MandantMediaService`).
- Admin-API analog `badge-templates`: `GET/POST /api/admin/badge-images`,
  `DELETE /api/admin/badge-images/{badgeImage}`, auth-gated Stream
  `GET /api/admin/badge-images/{badgeImage}/file` für die Editor-Vorschau;
  Writes hinter `throttle:admin`.
- Upload-Validierung identisch zum Self-Service-Media
  (`MandantMediaSelfServiceController`): `file` required, `image`,
  `mimes:jpeg,png,webp`, `max:2048` KB, plus Dimensionslimit 2000×2000 px
  (Muster `MAX_IMAGE_DIMENSION`); Extension wird aus dem validierten MIME-Typ
  abgeleitet, nie aus dem Client-Dateinamen.

### Renderer-Verhalten (PDF)

- Absolut positionierter div (`left/top/width/height` in mm) mit
  `overflow:hidden`, darin `<img>` als **Base64-`data:`-URI von der privaten
  Disk** (gleiche Technik wie `photo`/QR — kein Netzzugriff im Renderpfad).
- **Seitenverhältnis (SOLL-Entscheidung): Default `contain`** — Logos/Wappen
  dürfen nicht beschnitten werden und werden einpassend skaliert;
  `"fit": "cover"` ist das Opt-in für füllende Platzierung inkl. Beschnitt
  (Verhalten wie `photo` mit `object-fit: cover`).
- **Fehlende Quelle** (Upload gelöscht, kein Logo hinterlegt) → leere Box an
  der Layout-Position (konsistent zu `photo` ohne Portrait); die Karte druckt
  trotzdem.
- **Brand-Auflösung zur Druckzeit:** `brand`-Refs werden logisch aufgelöst
  (kein Binär-Snapshot im Template) — ein Logo-Austausch wirkt auf künftige
  Exporte. Dokumentierte Entscheidung; historische Snapshots gibt es bewusst
  nicht.

### Rückwärtskompatibilität & Sicherheit

- Templates ohne `image`-Entries bleiben **unverändert**: additive Whitelist-
  Erweiterung, keine Migration (`layout` bleibt `json`-Array), Alt-Layouts
  rendern visuell identisch weiter.
- `layout.src` enthält **niemals client-kontrollierte Pfade oder URLs** — nur
  den Enum-Ref (`brand.ref`) bzw. eine Integer-ID (`image_id`). Die Auflösung
  erfolgt ausschließlich serverseitig gegen mandantengescopete Quellen
  (verhindert SSRF/Path-Traversal/Cross-Mandant-Leak über das persistierte
  layout-JSON).

## Editor-UX

> **⚠️ TEILWEISE SUPERSEDED (2026-09-27).** Dieser Abschnitt war als reine
> SOLL-Spezifikation für „frei positionierbar" geschrieben und ist in dieser
> Form **nicht mehr gültig**. Aufgeteilt in:
>
> - **Weiter gültig** (unabhängig von der Interaktionsentscheidung): Canvas
>   statt Feld-Tabelle, mm→%-Projektion gegen echte A6-Konstanten, Raster als
>   Konzept, Bildquellen-/Fit-Auswahl, Framework-Disziplin, weiche Warnungen.
> - **Superseded** (setzten Maus-Drag voraus): die Regeln zu *Ziehen* und
>   *Resize-Griffen* — inklusive der daran hängenden
>   Pixel-zu-mm-Projektion „gegen die LIVE gerenderte Kartenhöhe". Siehe
>   „Folge für die Umsetzung".
> - **Wiederhergestellt** (2026-09-27): das *Pfeiltasten-Nudge*. Es war nie
>   Drag&Drop, und die Panel-Eingaben können es nicht ersetzen (`step="any"`:
>   „1 mm nach links" bei 27,4 mm ist dort eine Neu-Eingabe).

- **Canvas statt Tabelle:** Die Feld-Tabelle im `BadgeTemplateForm` wird zur
  interaktiven Fläche: Die A6-Vorschau (`aspect-a6`, weißes Karte-Panel) zeigt
  die Elemente an ihren Layout-Positionen (Auswahl per Klick). Die
  Zahleneingaben wandern in ein **Eigenschaften-Panel** rechts (bleiben
  kanonisch für Präzision + Barrierefreiheit). *Ob die Vorschau darüber hinaus
  direkt bedienbar bleibt, ist offen — s. „Offene Fragen".*
- **Projektion vereinheitlichen:** Die mm→%-Projektion rechnet gegen die
  echten A6-Konstanten (105 × 148), nicht mehr gegen die virtuelle
  85×121-Karte (Ist-Abweichung, siehe Ist-Tabelle) — damit Preview = Druck.
- **Raster (gilt weiter):** Ein Raster strukturiert die Fläche. **Ist-Stand:
  5 mm** (`CANVAS_GRID_STEP_MM = 5`) mit sichtbarem Overlay. Der in der alten
  Fassung genannte *Raster*-Toggle („Raster 1 mm, Toggle 0,5 mm") ist **nicht
  umgesetzt** und wird hier **nicht** als Ziel fortgeschrieben — die Rasterweite
  ist Teil der offenen Fragen. Die *Feinpositionierung* ist davon unberührt und
  existiert als Tastaturschritt (1 mm), siehe nächster Punkt.
- **Auswahl:** Auswahl per Klick (sichtbarer Rahmen), Entfernen per
  Button/Taste.
- **Feinpositionierung per Tastatur (gilt wieder, 2026-09-27):** Pfeiltasten auf
  dem fokussierten Box-Button verschieben um **1 mm**, mit **Shift** um einen
  Rasterschritt (**5 mm**), hart in die A6-Grenzen geklemmt. Der Box-Button ist
  ein nativer `<button>` und damit ohne `tabIndex` fokussierbar — das ist der
  einzige Tastatureinstieg in die Vorschau. Die absolute Position bleibt
  kanonisch im Panel; das Nudge ist die *relative* Korrektur, die `step="any"`
  nicht abdeckt.
- **Feld-Palette/Liste:** verfügbare Feldtypen inkl. `qr` und `image`; Klick
  legt ein Feld an Default-Position an (Defaults wie Ist: `w40 h8 size12
  left`, `qr`: `20×20` an der bisherigen Fixposition, `image`: `30×20` mm
  ohne Quelle — Speichern bleibt blockiert, bis eine Quelle gewählt ist).
- **Bildquelle (nur `image`):** das Eigenschaften-Panel zeigt statt
  Schriftgröße/Ausrichtung die Quellenwahl — Mandant-Logo, Header oder
  Upload (Dateiauswahl → POST `/api/admin/badge-images` → Thumbnail-Liste
  vorhandener Uploads), plus Fit-Umschalter contain/cover.
- **Warnungen (soft, blockieren nicht):** Überlappung zweier Felder sowie
  Duplikate eines Feldtyps werden im Canvas/Panel markiert; `qr` darf nur
  einmal existieren (Zweit-Anlage wird verhindert). Hart abgelehnt wird nur
  außerhalb der Fläche bzw. unter Mindestgröße (Validierungs-Abschnitt).
- **Framework-Disziplin (frontend/AGENTS.md):** State bleibt in
  react-hook-form (die Positionen stammen aus derselben Form-State — eine
  Source of Truth), zod-Schema als Factory-Funktion im Component-Body
  (kein Module-Scope `t``), React-Compiler-Policy (keine Memo-Antipatterns),
  dynamische mm-Werte bleiben die dokumentierte Inline-Style-Ausnahme
  (Laufzeitwerte), statische Editor-Chrome ausschließlich Tailwind/daisyUI.
  Keine Persistenz in localStorage.

### ~~Interaktion: Ziehen / Resize~~ — SUPERSEDED (2026-09-27)

> **Ersetzt.** Die Entscheidung lautet „Raster + konfigurierbare Labels":
> **kein** freies Ziehen per Maus. Die folgenden Regeln gelten nicht mehr und
> sind zu entfernen bzw. neu zu fassen — die von ihnen abhängige
> Pixel-zu-mm-Projektion, der Resize-Pfad und die magnetischen Guides sind
> gemeinsam mit dem UI zu streichen oder zu ersetzen.
>
> - ~~Ziehen verschiebt das ausgewählte Feld (Pointer Events,
>   touch-tauglich); Resize-Griff unten rechts ändert `w/h`.~~
> - ~~**Snap/Grid:** Raster 1 mm (Toggle 0,5 mm für Feinpositionierung);
>   Koordinaten im Panel zeigen immer die gerasterten Werte.~~ *(Raster als
>   Konzept gilt, die konkrete Feinpositionierung nicht — siehe offene
>   Fragen.)*
> - ~~Auswahl per Klick (sichtbarer Rahmen), Entfernen per Button/Taste;
>   Pfeiltasten nudgen 1 mm (mit Shift 5 mm).~~ *(Auswahl per Klick und
>   Entfernen gelten weiter. Das Pfeiltasten-Nudge ist **kein** Drag&Drop und
>   war nie von der Maus-Entscheidung abhängig: Es wurde am 2026-09-27 mit
>   `e04fdfd` entfernt und am selben Tag wiederhergestellt — siehe „Editor-UX"
>   oben.)*

## Validierung

Server-autoritativ (Controller), client-seitig gespiegelt (zod):

| Regel | Schwere | Seite |
|---|---|---|
| `x ≥ 0 ∧ y ≥ 0 ∧ x+w ≤ 105 ∧ y+h ≤ 148` | **hart** (Reject) | Backend + zod |
| Mindestgrößen je Typ (Text 5×3 mm, photo/qr/image 10×10 mm) | **hart** (Reject) | Backend + zod |
| `size` int, `1 ≤ size ≤ 72` | hart | Backend + zod |
| `field`-Whitelist inkl. `qr`, `team`, `vest_number`, `image` | hart | Backend + zod |
| max. ein `qr`-Entry | hart | Backend + zod |
| `image.src` required + valide Union (`brand.ref ∈ logo/header` ∨ `upload.image_id` int) | hart | Backend + zod |
| `image_id` existiert und gehört zum aktuellen Mandanten | hart | Backend |
| Überlappung zweier Feld-Rechtecke | weich (Warnung) | Editor-UI only |
| Duplikat eines Datenfeld-Typs | weich (Warnung) | Editor-UI only |

Bounds/Mindestgrößen als benannte Konstanten aus `BadgeRenderService`
(`A6_WIDTH_MM`/`A6_HEIGHT_MM`) ableiten — kein dupliziertes Magic-Number-Paar;
die zod-Seite erhält die Werte als Props/Konstanten-Export, nicht hart codiert.

## PDF-Render-Kontrakt (`BadgeRenderService`)

- **Einheiten sind identisch:** dompdf versteht CSS-mm/-pt als physikalische
  Einheiten — `1 layout-mm = 1 gedruckter-mm`, kein Umrechnungs-/Skalierungsschritt
  serverseitig. Karte bleibt fixer `105 × 148 mm`-Container, `@page A6, margin 0`.
- **`qr`-Entry:** Statt des fixen QR-divs rendert der Service den QR-Bildblock
  am Entry (`left/top/width/height` mm, gleiche `<img>`-Ausgabe, Endroid
  Builder unverändert). Der QR bleibt **immer** Teil jeder Karte — auch wenn
  das Layout ihn nicht adressiert.
- **Rückwärtskompatibilität:** Templates ohne `qr`-Entry behalten exakt die
  heutige Geometrie (`right:5mm; bottom:5mm; 20×20mm`). Bestehende Templates
  rendern visuell identisch weiter; fehlende Keys bleiben defensiv belegt
  (`?? 0` / Defaults) wie im Ist.
- **Neue Datenfelder:** Erweiterung des `valueFor`-Match mit null-sicherer
  Auflösung (leerer String statt `null`-Ausgabe, konsistent zu `event`/`date`).
  Escaping via `e()` bleibt für alle interpolierten Werte Pflicht; `photo`-
  Sonderbehandlung (private Disk, Base64, `object-fit: cover`, leere Box bei
  fehlendem Bild) bleibt unangetastet.
- **`image`-Entry (SOLL):** absolut positionierter, `overflow:hidden`-div mit
  Base64-`<img>` von der privaten Disk (`object-fit` je `fit`, Default
  `contain`). Die Quelle wird serverseitig aufgelöst (`brand` →
  `MandantMediaService`-Pfad des aktuellen Mandanten, `upload` →
  mandantengescopete `badge_images`-Zeile); fehlende Quelle → leere Box wie
  `photo` ohne Portrait. Der Mandanten-Scope des `upload`-Lookups ist
  **unbedingt** (`forMandant()`): ohne aufgelösten Mandanten rendert der Entry
  eine leere Box, statt den Filter zu fallen lassen (WP-2-d). Alt-Templates ohne
  `image`-Entries rendern unverändert.

## Phasing (jede Etappe separat umsetz- und testbar)

> **⚠️ SUPERSEDED (2026-09-27).** Diese Etappenfolge ist überholt: Etappe 1 ist
> umgesetzt, Etappe 2 und 3 sind zwar **umgesetzt** (FE3/FE4), beruhen aber auf
> der falschen Annahme „frei positionierbar" und stehen damit im Widerspruch zur
> Entscheidung vom 2026-09-27. Sie beschreibt **keinen** gültigen Zielzustand
> mehr. Verbindlich ist nur die Zuordnung zum tatsächlichen Umsetzungsstand:
>
> - **FE1** (`a17332b`) — Schema + Backend. **Gilt**, unabhängig von der UX.
> - **FE2** (`eb88cbc`) — „Editor-Basis-UI" (Palette, A6-Canvas,
>   Eigenschaften-Panel, Zahleneingaben, Bildquellen). **Gilt weiter** — das
>   ist genau der „konfigurierbare Labels"-Teil der neuen Entscheidung.
> - **FE3** (`8634e40`) — „Drag&Drop" (Grid/Snap, Bounds-Clamp,
>   Überlappungs-Warnung). **Superseded.** Achtung: der Commit mischt beides —
>   das 5-mm-Raster/Snap und die Bounds-Clamp-Projektion bleiben brauchbar,
>   das Maus-Ziehen selbst nicht.
> - **FE4** (`68f52d7`) — „Polish" (Resize, Ausrichtungs-Guides, Nudge,
>   Auto-Fit). **Superseded**, mit Ausnahme des wrap-bewussten Auto-Fits, der
>   rein typografisch ist und nicht an der Interaktion hängt.
>
> E2E-Tag durchgängig `@feature:badge-editor` (unverändert gültig).

### Etappe 1 — Schema + Backend (kein UI-Change)

Whitelist erweitern (`qr`, `team`, `vest_number`), Bounds-/Mindestgrößen-
Regeln im Controller (Konstanten aus dem Render-Service), QR-Fallback +
neue `valueFor`-Fälle im Renderer.

**Test-Forderung:**
- PHPUnit Feature: Accept/Reject-Matrix `BadgeTemplateController` (Bounds
  oben/unten/rechts, Mindestgrößen, `qr`-Duplikat, neue Whitelist-Werte,
  Bestands-Layouts weiterhin valide).
- PHPUnit (Unit/Feature): `BadgeRenderService` — QR an Entry-Position vs.
  Default-Fixposition ohne Entry; `team`/`vest_number` mit/ohne Quelle;
  Regression: Alt-Layout rendert unverändert.
- Vitest: zod-Schema-Spiegel in `badgeTemplateFormUtils` (Bounds, Mindest-
  größen, qr-Eindeutigkeit).

### ~~Etappe 2 — Editor-UI (Drag-&-Drop-MVP)~~ — SUPERSEDED (2026-09-27)

> **Ersetzt** (siehe Zuordnung im Phasing-Kopf). Die Test-Forderungen bleiben
> nur als Nachweis der umgesetzten Historie lesbar; sie beschreiben **kein**
> gültiges Ziel mehr. Der Basis-UI-Teil (Palette, Eigenschaften-Panel,
> Zahleneingaben) ist gültig und bleibt; der Drag-&-Drop-Teil nicht.

Interaktiver Canvas ersetzt die Feld-Tabelle; Auswahl + Eigenschaften-Panel;
Snap 1 mm; Anlage/Löschung von Feldern; Projektion auf echte A6-Basis.

**Test-Forderung:**
- Vitest Unit (Stand 2026-09-27, nach Rückbau + Nudge-Rückkehr): zod-Spiegel +
  Payload-Mapping, `findFreePosition`/`boxesOverlap` (Warnungen),
  `badgeCanvasFontSizeCss` (Auto-Fit), Rasterkonstante `CANVAS_GRID_STEP_MM`
  (samt ihrem dritten Nutzer, dem groben Shift-Nudge-Schritt) und
  `computeNudgePosition`/`nudgeDirectionFromKey`. **Nicht mehr:**
  Snap-/Resize-/Drag-Mathematik — sie ist mit der Zieh-Interaktion entfallen.
- Playwright E2E, getaggt `{ tag: ['@feature:badge-editor'] }`
  (+ mind. ein weiterer Tag lt. Tag-Policy): Editor öffnen, Position/Größe
  **über das Panel** setzen, WYSIWYG in mm prüfen, speichern, erneut öffnen →
  persistiert; Ungültiges (außerhalb der Fläche) wird abgewiesen. Ergänzend die
  **Abwesenheitsnageln** auf die entfernte **Zeiger**-Interaktion (keine
  Eckgriffe, keine Guides, ein Drag-Sweep verändert weder Geometrie noch
  Panel-Werte) **und** das **Nudge-Verhalten** (1 mm pro Pfeiltaste, Shift =
  5 mm, Klemmung an den A6-Grenzen, Roundtrip). `@smoke` bleibt unberührt.

### ~~Etappe 3 — Polish~~ — TEILWEISE SUPERSEDED (2026-09-27)

> **Ersetzt.** Resize-Griffe und Ausrichtungs-Guides gehören zur superseded
> Maus-/Feinsteuerungs-Interaktion. Das **Keyboard-Nudge ist wieder da** (es
> war nie Teil des Maus-Entscheids, siehe „Editor-UX"), die weichen Warnungen
> (Überlappung/Duplikat) und der Grid-Toggle sind ohnehin **nicht** betroffen
> und bleiben gültig.

~~Resize-Griffe~~, Keyboard-Nudge, Überlappungs-/Duplikatwarnungen, Grid-Toggle,
Touch-Feinschliff.

**Test-Forderung:**
- Vitest: Warnungslogik (Rechteck-Schnitt, Duplikaterkennung) +
  Nudge-Mathematik (Richtung, 1-mm-/5-mm-Schritt, Klemmung, NaN-Sicherheit).
- Playwright getaggt (`@feature:badge-editor`): Nudge verschiebt 1 mm bzw. mit
  Shift 5 mm und überlebt den Roundtrip, Größe nur über das Panel
  (`Resizes a field from the panel…`), Warnung erscheint bei Überlappung.

### Bilder (`image`) — Einplanung FE2/FE3 (IST, 2026-08-26)

> **⚠️ TEILWEISE SUPERSEDED (2026-09-27).** Backend-Slice **und** FE2 sind
> umgesetzt und gültig. Superseded ist nur der **FE3-Teil**: „Bildelemente wie
> Datenfelde ziehbar/rasternd" setzt das freie Maus-Ziehen voraus, das es nicht
> gibt. Bounds-Clamping, die **Pfeiltasten-Feinpositionierung** (auch für
> Bildelemente) und die Überlappungs-Warnung (auch gegen Bildelemente) bleiben
> als Verhalten bestehen. „Rastert beim Ziehen" (E2E) ist entsprechend
> hinfällig.

Die Backend-Voraussetzungen gingen als eigener Slice voraus (FE1-artig, kein
UI-Change nötig): Migration `badge_images` + Upload-/Delivery-API,
Whitelist-/Validierungs-Erweiterung (`src`-Union, Mandanten-Scoping der
`image_id`), Renderer-Zweig im `BadgeRenderService`. Die Editor-Integration
ist im **FE2/FE3-Zyklus** umgesetzt:

- **FE2 (Editor-Basis-UI) — gilt weiter:** `image`-Elemente in
  Palette/Eigenschaften-Panel — Quellenwahl (Logo/Header/Upload inkl.
  Upload-Flow + Thumbnail-Liste), Fit-Umschalter, Zahleneingaben X/Y/W/H,
  Persistenz-Roundtrip.
- ~~**FE3 (Drag&Drop) — superseded:** Bildelemente wie Datenfelder
  ziehbar/rasternd, Bounds-Clamping, Überlappungs-Warnung auch gegen
  Bildelemente.~~ *(Bounds-Clamping + Warnung gelten als Verhalten weiter, nur
  nicht mehr mausgetrieben.)*

**Test-Forderungen:**
- PHPUnit Feature: Accept/Reject-Matrix `BadgeTemplateController` für `image`
  (Bounds, 10×10-Minimum, fehlende/illegale `src`, fremd-mandant `image_id` →
  Reject, mehrere `image`-Entries ok, Alt-Layouts weiterhin valide);
  Upload-API (MIME/Größe/Dimensionen, Mandanten-Isolation, Delete, Delivery
  auth-gated); `BadgeRenderService` (Position + `object-fit` contain/cover,
  Brand-Auflösung logo/header, fehlende Quelle → leere Box, Regression:
  Alt-Layout rendert unverändert).
- Vitest (FE2): zod-Spiegel der `src`-Union + `fit`-Default + Payload-Mapping
  in `badgeTemplateFormUtils.ts`; Defaults der image-Zeile.
- Playwright getaggt `{ tag: ['@feature:badge-editor'] }` (+ mind. ein
  weiterer Tag lt. Tag-Policy): Bild platzieren, Quelle wählen, speichern,
  erneut öffnen → persistiert; Upload-Flow Ende-zu-Ende; ungültige/fehlende
  Quelle blockiert Speichern (FE2). Das Bildelement wird wie die anderen
  Elemente **über X/Y/W/H im Panel** platziert (kein Ziehen).

## Rückbau der Zieh-Interaktion (umgesetzt 2026-09-27)

**Kernbefund:** Das war **kein Neubau**, sondern ein Rückbau.
„Raster + konfigurierbare Labels" beschreibt den Stand, den der Editor nach
diesem Schritt hat:

| Baustein der Entscheidung | Zustand | Quelle |
|---|---|---|
| Raster | vorhanden: sichtbares 5-mm-Overlay (`backgroundImage`/`backgroundSize` aus `CANVAS_GRID_STEP_MM`) | `BadgeCanvas.tsx`, `badgeTemplateFormUtils.ts` (`CANVAS_GRID_STEP_MM`) |
| Konfigurierbare Labels | vorhanden: Zahleneingaben X/Y/W/H (mm), Schriftgröße, Ausrichtung, Bildquelle, Fit | `BadgePropertiesPanel.tsx` |
| Auswahl | vorhanden: Klick-Auswahl mit Rahmen, Klick auf den Kartenhintergrund oder `Escape` hebt sie auf | `BadgeCanvas.tsx` |
| Maus-Drag zum Verschieben | **entfernt** (war `handleDragStart/Move/End`, Pointer Events + `setPointerCapture`, px→mm gegen `getBoundingClientRect()`) | — |
| Eckgriffe zum Skalieren | **entfernt** (war `handleResizeStart/Move/End` + `DragState`/`ResizeState` + `computeDragResize`) | — |
| Magnetische Ausrichtungs-Guides | **entfernt** (war `computeAlignmentSnap`/`findAlignedGuides` + `[data-badge-guide]`) | — |
| Pfeiltasten-Nudge | **vorhanden (wiederhergestellt 2026-09-27)**: `handleKeyDown`/`handleNudge` + `computeNudgePosition`, 1 mm bzw. Shift = 5 mm, geklemmt an den A6-Grenzen | `BadgeCanvas.tsx`, `badgeTemplateFormUtils.ts` |

**Das Nudge ist kein Drag&Drop — und `e04fdfd` hat es zu Unrecht mitgerissen.**
Die Entscheidung „Raster + konfigurierbare Labels" fiel gegen **freies Ziehen per
Maus**; Tastaturbedienung war nie ausgeschlossen, und `computeNudgePosition`
existierte bereits vor dem Rückbau. Der im Rückbau-Commit offen notierte
Tradeoff — das Nudge sei die einzige tastaturfähige *relative* Feinverstellung,
die Panel-Eingaben seien `step="any"` und damit nur absolut — ist damit
aufgelöst: die **absolut**e Geometrie schreibt weiterhin nur das Panel, das
Nudge schreibt **relativ** in dieselbe Form-State (`onMove` →
`setValue('fields.{i}.x'|'y')`), beide schreiben nie in verschiedene Quellen.

**Keine DnD-Bibliothek in `frontend/package.json` — und das war nie ein Mangel.**
Die Zieh-Interaktion war nativ per Pointer Events implementiert; eine Bibliothek
wurde nie gebraucht. Ihre Abwesenheit sagte nichts über den Umsetzungsstand aus
(die verbreitete gegenteilige Annahme ist falsch) — und sie ist jetzt auch nicht
mehr nötig.

**Bewusste Konsequenzen des Rückbaus** (nicht zu „reparieren"):

- `BadgeCanvas` hat `rows`/`selectedIndex`/`overlapIndices`/`onSelect`/`onMove`.
  `onResize` ist mit seinem Schreiber (`handleResizeField`) entfallen — es gab
  keinen Resize-Pfad mehr, den sie speisen könnte. Die **absolut**e Geometrie
  hat damit weiterhin **einen** Schreiber: die Panel-Eingaben.
- `snapToGrid` bleibt **weg** — es war ausschließlich von den Drag-Pfaden
  erreichbar und würde toter Code sein. `clampToBounds` kehrt als
  modulprivater Helfer **zurück**, weil `computeNudgePosition` es braucht; es
  ist bewusst *nicht* mehr exportiert (kein zweiter öffentlicher Schreiber).
  `CANVAS_GRID_STEP_MM` bleibt (Raster-Overlay, `findFreePosition`-Scan und der
  grobe Shift-Schritt des Nudges).
- Der Hinweistext unter der Vorschau nennt **beide** Schreiber: das Panel für
  die absolute Geometrie **und** die Pfeiltasten für den relativen Feinschritt
  („Das Raster hat 5 mm. Position und Größe des gewählten Feldes stellst du im
  Eigenschaften-Panel ein. Feiner geht es mit den Pfeiltasten auf der Vorschau:
  1 mm pro Tastendruck, mit Shift 5 mm."). Die Nennung ist **nicht** optional:
  eine vorhandene, aber nicht beworbene Steuerung ist nicht auffindbar. Sie
  enthält **keine** Maus-Anleitung — der Hinweis ist der Ort, an dem der
  zurückgebaute Zieh-Hinweis am ehesten zurückkäme, und genau deshalb hängen
  dort die Abwesenheitsnageln (siehe „Testfolge").
- **Die `BadgePropertiesPanel` und das Raster-Overlay blieben unangetastet** — sie
  waren die Ersetzung, nicht der Gegenstand des Rückbaus.

**Testfolge (AGENTS.md §3):** Die vier E2E-Tests, die das superseded Verhalten
prüften (*„drags a field onto the grid…"*, *„resizes a field with the corner
handle…"*, *„nudges the selected field with arrow keys…"*, *„shows alignment
guides…"*), prüften zuerst nur das, was die Entscheidung verlangt: Platzierung/
Größe aus dem Panel mit WYSIWYG-Nachweis in mm und Roundtrip, plus
**Abwesenheitsnageln** (`[data-resize-handle]`, `[data-badge-guide]` je 0; der
Drag-Sweep ändert weder Geometrie noch Panel-Werte). Mit dem zurückgeholten
Nudge kam die Pfeiltasten-Nagel dazu — sie wurde durch das
**Verhaltenstest-Paar** ersetzt (Vitest: `computeNudgePosition`/`nudgeDirectionFromKey`;
Komponente: 1 mm pro Taste, Shift = 5 mm, Klemmung an den A6-Grenzen;
E2E: *„nudges the focused field with arrow keys…"* inkl. Roundtrip). Die
Drag-/Resize-Abwesenheitsnageln sind **unverändert** und weiterhin aktiv —
`BadgeCanvas.test.tsx` schärft den Drag-Test zusätzlich: seit die Canvas wieder
einen Schreiber (`onMove`) hat, prüft er nicht mehr nur unveränderte Pixel,
sondern explizit `expect(onMove).not.toHaveBeenCalled()`.

**Der Hinweistext hat seine eigene Nagel** (Nennung der Tastatursteuerung,
2026-09-27). Sie prüft **Bedingungen, nicht Zeichenketten**: verankert am
Rastersatz, behauptet „Pfeiltasten" *und* „Eigenschaften-Panel" als Konzepte
(zweisprachig) und verbietet Mause-Zieh-Vokabular (`ziehen`/`ziehbar`/`Maus`,
`drag`/`drop`) — dialog- bzw. containerweit, nicht nur im genauen Satz. Eine
sprachliche Umformulierung des Hinweises darf die Nagel nicht entwerten, und
eine wiederkehrende Zieh-Anleitung muss auch mit **anderem Wortlaut** fallen
(`entziehen`/`Zurückziehen` bleiben über Wortgrenzen außen vor). Sabotage
gemessen: Satz entfernt → 1 Failure; „Ziehen verschiebt das Feld." in den
Hinweis → 1 Failure.

## Am 2026-09-27 getroffene Entscheidungen (schließen die offenen Fragen)

Diese vier Punkte sind entschieden und damit **gültige Spec**:

1. **„Raster" = sichtbares 5-mm-Overlay als Orientierungshilfe.** Keine
   Zell-Auswahl („Klick auf eine Zelle setzt das Label") und kein
   Layout-Schema (Spalten/Zeilen). **Die Rasterweite bleibt 5 mm**
   (`CANVAS_GRID_STEP_MM`) — sie existierte bereits sichtbar, und eine Änderung
   wäre eine zweite, nicht geforderte Entscheidung gewesen.
2. **„Konfigurierbare Labels" = die vorhandenen Panel-Zahleneingaben X/Y/W/H in
   mm, kanonisch.** Sie sind der einzige Schreiber der Geometrie. Ergänzend
   bereits vorhanden und gültig: Schriftgröße, Ausrichtung, Bildquelle, Fit.
3. **Das persistierte Schema `x/y/w/h` in mm bleibt unverändert.** Der Renderpfad
   (`BadgeRenderService`) und die serverseitige Validierung konsumieren freie
   mm-Werte; der Editor schreibt dieselben. **Kein** Raster-Zellkoordinaten-
   Schema (`row`/`col`/`span`) — das ist nicht entschieden worden und wird
   nicht eingeführt.
4. **Die A6-Vorschau bleibt bestehen, ist aber nicht mehr frei *ziehbar*:**
   Vorschau über dem Raster, die Auswahl und den **Pfeiltasten-Nudge** trägt.
   Das Panel bleibt der kanonische Schreiber für die **absolute** Position;
   verschoben wird relativ (1 mm / Shift = 5 mm, geklemmt an den A6-Grenzen).
   Die Platzierung wandert damit **nicht** vollständig in Listen-/Formular-
   bedienung. Daraus folgt für `image` (Punkt 5 der alten Liste): Bilder werden
   wie alle anderen Elemente **über X/Y/W/H im Panel** platziert, nicht
   rastergebunden. Der PDF-Render-Kontrakt „Preview = Druck" (WYSIWYG in mm)
   gilt unverändert weiter, weil Vorschau und Panel dieselben mm-Werte
   projizieren.

## Invarianten (nicht regredieren)

- Validierung bleibt **server-autoritativ**; Editor-Warnungen sind reine UX.
- Alle Feldwerte via `e()` escaped; Portrait ausschließlich private Disk.
- „Ein Default pro Mandant" (`BadgeTemplateService`) bleibt Service-Invariante.
- `layout` bleibt portable `json`-Spalte; alle neuen Regeln reine PHP/zod-
  Arithmetik (kein PG-spezifisches SQL, AGENTS.md §2).
- Ohne `qr`-Entry gilt die historische Fixposition — niemals entfernen, solange
  Bestandstemplates ohne Entry existieren.
- `image`-Quellen sind nie client-kontrollierte Pfade/URLs im layout-JSON —
  nur Enum-Ref (`brand.ref`) oder Integer-ID (`image_id`); Bild-Bits kommen
  ausschließlich von der privaten Disk (Base64-Embed). Das Mandanten-Scoping
  der `image_id` ist Pflicht (kein Cross-Mandant-Leak).
- Bestandstemplates ohne `image`-Entries rendern unverändert — die
  Whitelist-Erweiterung bleibt strikt additiv.
