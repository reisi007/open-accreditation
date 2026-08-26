# Badge-Template-Editor — frei positionierbare Felder (P4)

**Status: SOLL-Spezifikation — NICHT implementiert.** Alle Abschnitte
beschreiben den Zielzustand. Existierende Code-Struktur ist als
„Ist (verifiziert)" gekennzeichnet; alles Übrige ist Entwurf.

**Anlass:** User-Entscheidung „VOLL frei positionierbar" — der Editor soll
Pentaho-artig Drag & Drop auf einer Ausweis-Vorschau bieten (X/Y in mm),
statt Positionen nur über Zahlenfelder zu pflegen. Enthält die Umsetzung des
offenen Punkts **P4-F4** (`qr`-Layout-Feld, siehe `badges-qr.md`).

**Erweiterung (User-Entscheidung 2026-08-26, IST — implementiert):** Der
Editor unterstützt neben Datenfeldern auch **selbst platzierte Bilder**
(Logos, Vereinswappen, Hintergründe) — neuer Elementtyp `image`, frei
positionierbar mit `x/y/w/h` in mm und wählbarer Bildquelle (Upload oder
Mandant-Brand-Bild). Backend-Infrastruktur (Migration `badge_images` +
Upload-/Delivery-API), Validierung (`src`-Union + Mandanten-Scoping der
`image_id`, RV-S2) und Renderer-Zweig (Base64-Embed, `object-fit` contain/
cover) sind umgesetzt; Editor-Integration (FE2/FE3) folgt. Details im
Abschnitt „Elementtyp `image`".

## Ist (verifiziert, Stand dieser Spec)

| Baustein | Ort | Ist-Zustand |
|---|---|---|
| Schema/Validierung | `BadgeTemplateController` (`layout`-Rules) | Schema v2: Whitelist inkl. `qr`/`team`/`vest_number`/`image`; A6-Bounds (`x+w ≤ 105`, `y+h ≤ 148`), Mindestgrößen (Text 5×3, Box 10×10 mm), max. ein `qr`-Entry; `image.src` Union-Validierung inkl. Existenz + Mandanten-Scoping der `image_id` (RV-S2) |
| Rendering | `BadgeRenderService` (A6 `105 × 148 mm`, Konstanten) | Absolute `div`s (`left/top/width/height` in mm, `font-size` pt, `text-align`), Werte via `e()` escaped; `photo` special-cased (Base64 aus privater Disk, `object-fit: cover`); QR an Entry-Position oder fix unten rechts (Fallback); **`-`Entry** (Base64 aus privater Disk, `object-fit` contain Default/cover, leere Box bei fehlender Quelle) |
| Datenmodell | Migrationen `badge_templates` + `badge_images` | `layout` ist Laravel-`json`-Spalte; `badge_images` (id, `mandant_id` FK, `path`, `mime`, `original_name`, timestamps) |
| API | `BadgeImageController` (`/api/admin/badge-images`) | `GET` (Liste, mandantengescopet), `POST` (Upload: `mimes:jpeg,png,webp\|max:2048` + 2000×2000 px, private Disk `badge-images/{slug}/…`), `DELETE` (nur eigener Mandant), auth-gated Delivery `GET /{id}/file` |
| Frontend | `BadgeTemplatesPage` → Modal → `BadgeTemplateForm` + `BadgePropertiesPanel` | interaktiver Canvas (Drag&Drop, Resize, Snap), Palette (10 Typen inkl. `image`), Eigenschaften-Panel mit Bildquellen-Auswahl (Upload/Brand) + Fit-Umschalter; zod-Schema als Factory-Funktion (`badgeTemplateFormUtils.ts`); Frontend-API-Funktionen `listBadgeImages`/`uploadBadgeImage`/`deleteBadgeImage`/`badgeImageFileUrl` an echte Endpoints verdrahtet |

## Zielbild

Template-Autor positioniert Felder direkt auf der DIN-A6-Vorschau: ziehen,
Griffpunkte zum Skalieren, Auswählen und Eigenschaften im Panel bearbeiten.
Das PDF-Ergebnis entspricht exakt der Vorschau (WYSIWYG in mm).

---

## Template-Schema v2

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
- **Neuer Eintragstyp `image`** (User-Entscheidung 2026-08-26, SOLL — noch
  nicht implementiert): frei platzierbares Bild (Logo, Vereinswappen,
  Hintergrund) als eigener Array-Entry mit `x/y/w/h` in mm und Pflicht-Quelle
  `src`; `fit` optional (`contain`/`cover`). `size`/`align` sind erlaubt,
  aber bedeutungslos (Renderer ignoriert sie, wie bei `qr`). Mehrere
  `image`-Entries sind erlaubt (Co-Branding); Details im Abschnitt
  „Elementtyp `image`".
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

## Elementtyp `image` — frei platzierte Bilder (IST)

**Status: IST (User-Entscheidung 2026-08-26) — Backend implementiert.**
Eigene Bildelemente ohne Datenfeld-Bezug, identische Geometrie-Mechanik wie
die Datenfelder. Backend-Infrastruktur (Migration + API), Controller-
Validierung inkl. Mandanten-Scoping der `image_id` (RV-S2) und Renderer-
Zweig (Base64, `object-fit`) sind umgesetzt; Editor-UI-Integration
(Quellenwahl, Upload-Flow) folgt im FE2/FE3-Zyklus.

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

## Editor-UX (SOLL)

- **Canvas statt Tabelle:** Die Feld-Tabelle im `BadgeTemplateForm` wird zur
  interaktiven Fläche: Die A6-Vorschau (`aspect-a6`, weißes Karte-Panel)
  nimmt Drag & Drop entgegen. Die Zahleneingaben wandern in ein
  **Eigenschaften-Panel** rechts (bleiben kanonisch für Präzision +
  Barrierefreiheit).
- **Projektion vereinheitlichen:** Die mm→%-Projektion rechnet gegen die
  echten A6-Konstanten (105 × 148), nicht mehr gegen die virtuelle
  85×121-Karte (Ist-Abweichung, siehe Ist-Tabelle) — damit Preview = Druck.
- **Interaktion:**
  - Ziehen verschiebt das ausgewählte Feld (Pointer Events, touch-tauglich);
    Resize-Griff unten rechts ändert `w/h`.
  - **Snap/Grid:** Raster 1 mm (Toggle 0,5 mm für Feinpositionierung);
    Koordinaten im Panel zeigen immer die gerasterten Werte.
  - Auswahl per Klick (sichtbarer Rahmen), Entfernen per Button/Taste;
    Pfeiltasten nudgen 1 mm (mit Shift 5 mm).
  - Feld-Palette/Liste: verfügbare Feldtypen inkl. `qr` und `image`; Klick
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
  react-hook-form (Drag schreibt via `setValue` in dieselben Form-Werte —
  eine Source of Truth), zod-Schema als Factory-Funktion im Component-Body
  (kein Module-Scope `t``), React-Compiler-Policy (keine Memo-Antipatterns),
  dynamische mm-Werte bleiben die dokumentierte Inline-Style-Ausnahme
  (Laufzeitwerte), statische Editor-Chrome ausschließlich Tailwind/daisyUI.
  Keine Persistenz in localStorage.

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
  `photo` ohne Portrait. Alt-Templates ohne `image`-Entries rendern
  unverändert.

## Phasing (jede Etappe separat umsetz- und testbar)

> Operativ läuft die Umsetzung als **FE1–FE4-Etappenplan** (`AGENTS.todo.md`,
> Abschnitt „Feld-Editor Umsetzung"): Etappe 1 ≈ FE1 (Schema + Backend),
> Etappe 2 ≈ FE2/FE3 (Basis-UI, Drag & Drop), Etappe 3 ≈ FE4 (Polish).
> E2E-Tag durchgängig `@feature:badge-editor`.

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

### Etappe 2 — Editor-UI (Drag-&-Drop-MVP)

Interaktiver Canvas ersetzt die Feld-Tabelle; Auswahl + Eigenschaften-Panel;
Snap 1 mm; Anlage/Löschung von Feldern; Projektion auf echte A6-Basis.

**Test-Forderung:**
- Vitest Unit: Snap-/Clamp-Mathematik, Drag→FormValues-Mapping (inkl.
  NaN-defensive wie im Ist-Preview).
- Playwright E2E, getaggt `{ tag: ['@feature:badge-editor'] }`
  (+ mind. ein weiterer Tag lt. Tag-Policy): Editor öffnen, Feld ziehen,
  speichern, erneut öffnen → Position persistiert; Ungültiges (ausßerhalb der
  Fläche) wird abgewiesen. `@smoke` bleibt unberührt.

### Etappe 3 — Polish

Resize-Griffe, Keyboard-Nudge, Überlappungs-/Duplikatwarnungen, Grid-Toggle,
Touch-Feinschliff.

**Test-Forderung:**
- Vitest: Warnungslogik (Rechteck-Schnitt, Duplikaterkennung).
- Playwright getaggt (`@feature:badge-editor`): Resize ändert `w/h`,
  Nudge verschiebt rastergenau, Warnung erscheint bei Überlappung.

### Frei platzierbare Bilder (`image`) — Einplanung FE2/FE3 (SOLL, 2026-08-26)

Die Backend-Voraussetzungen gehen als eigener Slice voraus (FE1-artig, kein
UI-Change nötig): Migration `badge_images` + Upload-/Delivery-API,
Whitelist-/Validierungs-Erweiterung (`src`-Union, Mandanten-Scoping der
`image_id`), Renderer-Zweig im `BadgeRenderService`. Die Editor-Integration
selbst landet bewusst im **FE2/FE3-Zyklus**:

- **FE2 (Editor-Basis-UI):** `image`-Elemente in Palette/Eigenschaften-Panel —
  Quellenwahl (Logo/Header/Upload inkl. Upload-Flow + Thumbnail-Liste),
  Fit-Umschalter, Zahleneingaben X/Y/W/H, Persistenz-Roundtrip.
- **FE3 (Drag&Drop):** Bildelemente wie Datenfelder ziehbar/rasternd,
  Bounds-Clamping, Überlappungs-Warnung auch gegen Bildelemente.

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
  Quelle blockiert Speichern (FE2); Bildelement rastert beim Ziehen (FE3).

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
