# Badge-Template-Editor — frei positionierbare Felder (P4)

**Status: SOLL-Spezifikation — NICHT implementiert.** Alle Abschnitte
beschreiben den Zielzustand. Existierende Code-Struktur ist als
„Ist (verifiziert)" gekennzeichnet; alles Übrige ist Entwurf.

**Anlass:** User-Entscheidung „VOLL frei positionierbar" — der Editor soll
Pentaho-artig Drag & Drop auf einer Ausweis-Vorschau bieten (X/Y in mm),
statt Positionen nur über Zahlenfelder zu pflegen. Enthält die Umsetzung des
offenen Punkts **P4-F4** (`qr`-Layout-Feld, siehe `badges-qr.md`).

## Ist (verifiziert, Stand dieser Spec)

| Baustein | Ort | Ist-Zustand |
|---|---|---|
| Schema/Validierung | `BadgeTemplateController` (`layout`-Rules) | Flat Array `[{field, x, y, w, h, size, align}]`; Whitelist `name/category/event/date/photo/status`; `x/y/w/h` numeric **≥ 0** (0 erlaubt!), `size` int ≥ 1, `align ∈ left/center/right`, `min:1` Felder — alles required, keine Bounds nach oben |
| Rendering | `BadgeRenderService` (A6 `105 × 148 mm`, Konstanten) | Absolute `div`s (`left/top/width/height` in mm, `font-size` pt, `text-align`), Werte via `e()` escaped; `photo` special-cased (Base64 aus privater Disk); QR **fix** unten rechts (`right:5mm; bottom:5mm; 20×20mm`) unabhängig vom Layout |
| Datenmodell | Migration `badge_templates` | `layout` ist Laravel-`json`-Spalte → Schema-Erweiterung braucht **keine Migration** |
| Frontend | `BadgeTemplatesPage` → Modal → `BadgeTemplateForm` | Tabelle mit Number-Inputs (X/Y/W/H/Größe/Ausrichtung) je Feldzeile + read-only `BadgePreview`; zod-Schema als Factory-Funktion (`badgeTemplateFormUtils.ts`); Defaults neue Zeile: `x0 y0 w40 h8 size12 left` |
| Vorschau-Projektion | `BadgePreview.tsx` + `@utility aspect-a6` | Karten-Aspect 105/148, aber Koordinaten werden auf eine **virtuelle 85×121-mm-Karte** projiziert — die Projektionsbasis weicht vom PDF-Format ab |

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
  { "field": "qr",       "x": 78, "y": 121, "w": 22, "h": 22 }
]
```

- **Neuer Eintragstyp `qr`** (löst P4-F4): eigener Array-Entry mit
  `x/y/w/h` in mm; `size`/`align` sind bei `qr` erlaubt, aber bedeutungslos
  (Renderer ignoriert sie). **Maximal ein `qr`-Entry pro Template.**
  Fehlt der Entry, gilt weiterhin die Fixposition unten rechts
  (`right: 5mm; bottom: 5mm; 20 × 20 mm`) — bestehende Templates rendern
  unverändert weiter (siehe PDF-Render-Kontrakt).
- **Koordinaten:** `x/y` = linke obere Ecke in mm von oben links der
  A6-Fläche (105 × 148 mm); `w/h` in mm; `size` in pt; `align` wie Ist.
  Neu: `x + w ≤ 105`, `y + h ≤ 148` wird erzwungen (Ist prüft nur `≥ 0`).
- **Mindestgrößen statt `≥ 0`:** Text-Felder `w ≥ 5, h ≥ 3`;
  `photo`/`qr` `w ≥ 10, h ≥ 10`. Ein 0×0-Feld (heute valide!) rendert ein
  unsichtbares div und soll künftig abgelehnt werden.
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
  - Feld-Palette/Liste: verfügbare Feldtypen inkl. `qr`; Klick legt ein Feld
    an Default-Position an (Defaults wie Ist: `w40 h8 size12 left`,
    `qr`: `20×20` an der bisherigen Fixposition).
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
| Mindestgrößen je Typ (Text 5×3 mm, photo/qr 10×10 mm) | **hart** (Reject) | Backend + zod |
| `size` int, `1 ≤ size ≤ 72` | hart | Backend + zod |
| `field`-Whitelist inkl. `qr`, `team`, `vest_number` | hart | Backend + zod |
| max. ein `qr`-Entry | hart | Backend + zod |
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

## Phasing (jede Etappe separat umsetz- und testbar)

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
- Playwright E2E, getaggt `{ tag: ['@feature:badge-template-editor'] }`
  (+ mind. ein weiterer Tag lt. Tag-Policy): Editor öffnen, Feld ziehen,
  speichern, erneut öffnen → Position persistiert; Ungültiges (ausßerhalb der
  Fläche) wird abgewiesen. `@smoke` bleibt unberührt.

### Etappe 3 — Polish

Resize-Griffe, Keyboard-Nudge, Überlappungs-/Duplikatwarnungen, Grid-Toggle,
Touch-Feinschliff.

**Test-Forderung:**
- Vitest: Warnungslogik (Rechteck-Schnitt, Duplikaterkennung).
- Playwright getaggt (`@feature:badge-template-editor`): Resize ändert `w/h`,
  Nudge verschiebt rastergenau, Warnung erscheint bei Überlappung.

## Invarianten (nicht regredieren)

- Validierung bleibt **server-autoritativ**; Editor-Warnungen sind reine UX.
- Alle Feldwerte via `e()` escaped; Portrait ausschließlich private Disk.
- „Ein Default pro Mandant" (`BadgeTemplateService`) bleibt Service-Invariante.
- `layout` bleibt portable `json`-Spalte; alle neuen Regeln reine PHP/zod-
  Arithmetik (kein PG-spezifisches SQL, AGENTS.md §2).
- Ohne `qr`-Entry gilt die historische Fixposition — niemals entfernen, solange
  Bestandstemplates ohne Entry existieren.
