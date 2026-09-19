# Event-Type-Presets — fachliches Schema (`event_types.presets`, v1)

**Status:** Implementiert (W3, 2026-09-19). SOLL-Dokumentation des fachlichen
Preset-Schemas für mandant-spezifische Event-Typen (`event_types`, z. B.
`bundesliga`, `cup`, `cl`).

Ein Event-Typ bündelt Vorbelegungen, die beim Anlegen von Events/Akkreditierungen
dieses Typs als Default greifen: Badge-Template, PDF-Vorlagen
(Einverständniserklärung / Akkreditierungsdruck), Quota-/Frist-Defaults und
Kategorie-Vorgaben. `presets` ist eine portable Laravel-`json`-Spalte
(`EventType`-Cast `presets → array`) — **kein** PG-spezifisches Schema (§2).

## Zweischichtige Validierung

| Schicht | Verantwortlich | Prüft | Fehler-Key |
|---|---|---|---|
| Struktur-Hülle (W2) | `EventTypeController::assertPresetsValid()` | Wurzel = JSON-Objekt (assoziativ), Tiefe ≤ 3, nur skalare Blätter, ≤ 16 KB, valides UTF-8 | `presets` |
| Fachschema (W3) | `App\Services\EventTypePresetSchema` | Version, Whitelist, Typen, Wertebereiche, referenzielle Existenz (mandanten-gescopet) | `presets.<pfad>` |

Die Hülle bleibt die einzige Quelle für Größen-/Tiefenlimits — das Fachschema
dupliziert sie **nicht**. Beide Schichten antworten mit **422**.

## Entscheidungen (dokumentiert)

| Entscheidung | Wahl | Begründung |
|---|---|---|
| Unbekannte Keys | **Strikte Whitelist → 422** | Ein stilles Ignorieren würde Tippfehler (`bagde_template_id`) und Schema-Drift unsichtbar machen. Versionierung + strikte Whitelist erlauben spätere Erweiterung ohne Interpretationsspielraum. |
| Versionierung | **`v` Pflicht, `v = 1`** | Jede Schema-Änderung ist eine neue Version; `v = 1` wächst nie still. Ein nicht-leeres Preset ohne `v` ist ungültig. Ein **leeres** Preset (`{}`/`[]`) bleibt als „keine Presets" ohne Version zulässig. |
| Badge-Template-Default | **Referenz (`badge_template_id`), kein eingebettetes Layout** | `badge_templates.layout` ist die einzige Quelle der Layout-Wahrheit (`BadgeRenderService`, `features/badge-template-editor.md`). Ein eingebettetes Default-Layout wäre eine zweite, driftende Schema-Kopie. |
| Referenz-Prüfung | **Existenz zur Schreibzeit, mandanten-gescopet** | Kein Cross-Mandant-Leak über das JSON (analog `image_id`-Scoping in `badge-template-editor.md`). Verwaiste Referenzen nach Löschen werden zur **Lesezeit** defensiv ignoriert (Fallback-Kette). |
| Kategorie-Vorgaben | **Slug-Liste, Existenz im Mandanten** | Slugs sind das stabile Identifikationsmerkmal (`Category::effectiveForTeam()`); IDs wären nach Team-Override mehrdeutig. Doppelte und fremde Slugs sind hart abgelehnt. |
| Typstrenge | **Echte JSON-Typen** (`is_int`/`is_bool`/`is_string`/`array_is_list`) | Server-autoritativ; `"50"` ist kein gültiges Quota. Numerische Strings werden bewusst **nicht** koerziert. |

## Feldtabelle

### Wurzel

| Key | Typ | Pflicht | Regel |
|---|---|---|---|
| `v` | int | **ja** (bei nicht-leerem Preset) | exakt `1` |
| `badge_template_id` | int | nein | > 0, muss ein `badge_templates`-Row **des aktuellen Mandanten** sein |
| `consent_pdf` | object | nein | siehe unten |
| `accreditation_pdf` | object | nein | siehe unten |
| `defaults` | object | nein | siehe unten |

### `consent_pdf` — Einverständniserklärung

| Key | Typ | Pflicht | Regel |
|---|---|---|---|
| `enabled` | bool | **ja** (wenn Objekt vorhanden) | Schalter ja/nein |
| `template` | string | nein | Enum, v1: `standard` (eingebaute Vorlage; weitere Templates → v2) |

### `accreditation_pdf` — Akkreditierungsdruck-Layout

| Key | Typ | Pflicht | Regel |
|---|---|---|---|
| `layout` | string | **ja** (wenn Objekt vorhanden) | Enum: `badge` (eine A6-Karte pro genehmigtem Antrag, bestehender `BadgeExportService`) \| `list` (Druckliste) |

### `defaults` — Quota/Frist/Kategorien

| Key | Typ | Pflicht | Regel |
|---|---|---|---|
| `quota` | int | nein | `0 … 100000` (Plausibilitätsgrenze, kein Domänen-Maximum) |
| `deadline_offset_days` | int | nein | `1 … 365` Tage relativ zum Event-Datum |
| `auto_approve` | bool | nein | Default für `accreditations.auto_approve` |
| `category_slugs` | array<string> | nein | Liste (kein Objekt), max. 50, je Slug `^[a-z0-9]+(?:-[a-z0-9]+)*$`, max. 120 Zeichen, **eindeutig**, jeder Slug existiert als `category` **des aktuellen Mandanten** |

Mindestens ein Key pro vorhandenem Teilobjekt (`consent_pdf`, `accreditation_pdf`,
`defaults`) ist Pflicht — leere Teilobjekte werden mit 422 abgelehnt.

## Beispiel-JSON (Voll-Preset)

```json
{
  "v": 1,
  "badge_template_id": 12,
  "consent_pdf": {
    "enabled": true,
    "template": "standard"
  },
  "accreditation_pdf": {
    "layout": "badge"
  },
  "defaults": {
    "quota": 50,
    "deadline_offset_days": 14,
    "auto_approve": false,
    "category_slugs": ["presse", "fotograf"]
  }
}
```

Minimal-Preset: `{ "v": 1 }`. Leeres Preset: `{}` / `[]` (keine Version nötig).

## Fehler-Vertrag (422)

Fehler landen auf dem exakten Blatt-Key, damit Clients das Feld direkt markieren
können — Muster wie `BadgeTemplateController` (`layout.<i>.<key>`):

| Fall | Fehler-Key |
|---|---|
| Version fehlt / falsch / falscher Typ | `presets.v` |
| Unbekannter Root-Key | `presets.<key>` |
| Unbekannter Key in Sektion | `presets.consent_pdf.<key>` usw. |
| Falscher Skalar-Typ | `presets.defaults.quota`, `presets.consent_pdf.enabled`, … |
| Quota negativ / zu groß | `presets.defaults.quota` |
| Frist-Offset außerhalb 1…365 | `presets.defaults.deadline_offset_days` |
| Fehlendes/Fremd-Badge-Template | `presets.badge_template_id` |
| Unbekannter/doppelter/ungültiger Kategorie-Slug | `presets.defaults.category_slugs.<index>` bzw. `presets.defaults.category_slugs` |
| Leeres Teilobjekt | `presets.consent_pdf` / `presets.accreditation_pdf` / `presets.defaults` |
| Struktur-Hülle (Objekt/Tiefe/Größe/UTF-8) | `presets` |

## Fallback-Kette Event → Typ → Mandant-Default

Presets sind **Defaults, keine erzwungenen Werte**: Die konkrete
Event-/Akkreditierungs-Zeile gewinnt immer. Auflösungsreihenfolge je Belang:

| Belang | Event-Ebene (stärkster Override) | Typ-Ebene (`event_types.presets`) | Mandant-/System-Default (Fallback) |
|---|---|---|---|
| Badge-Template | event-spezifische Template-Referenz (SOLL, noch nicht im `events`-Schema) | `badge_template_id` | Mandant-Default (`BadgeTemplateService`, `is_default = true`) |
| Einverständnis-PDF | Event-Override (SOLL) | `consent_pdf.enabled` + `.template` | aus (kein PDF) |
| Akkreditierungsdruck | Event-Override (SOLL) | `accreditation_pdf.layout` | `badge` (bestehender Export) |
| Quota | `accreditations.quota` | `defaults.quota` | kein Default (Pflichtfeld der Akkreditierung) |
| Frist | `events.deadline_start`/`deadline_end` | `defaults.deadline_offset_days` | kein Default |
| Auto-Freigabe | `accreditations.auto_approve` | `defaults.auto_approve` | `false` |
| Kategorien | Event-Kategorie-Zuordnung | `defaults.category_slugs` | alle Mandant-Kategorien |

**Toleranz verwaister Referenzen:** Wird ein referenziertes Badge-Template oder
eine Kategorie später gelöscht, ist das gespeicherte Preset **kein Fehler**.
Der Resolver überspringt den toten Verweis und fällt auf die nächste Stufe der
Kette zurück. Die Schreibzeit-Validierung hält den Bestand sauber; die
Lesezeit-Auflösung bleibt robust.

## Betroffene Klassen

| Klasse | Verantwortung |
|---|---|
| `App\Services\EventTypePresetSchema` | Fachschema v1: Whitelist, Typen, Bereiche, mandanten-gescopete Referenz-Existenz |
| `App\Http\Controllers\Api\Admin\EventTypeController` | Struktur-Hülle + Aufruf des Fachschemas in `assertPresetsValid()` |
| `App\Models\EventType` | `presets`-Cast (`array`), mandanten-gescopetes Route-Binding |

## Invarianten (nicht regredieren)

- **Strikt additiv/versioniert:** `v = 1` wird nie nachträglich erweitert;
  neue Keys/Enums erfordern `v = 2` (Resolver müssen beide Versionen lesen).
- **Kein Cross-Mandant-Leak:** `badge_template_id` und `category_slugs` werden
  ausschließlich gegen den aktuellen Mandanten aufgelöst.
- **Portabilität (§2):** Validierung ist reine PHP-Logik über `json`-Spalten,
  keine PG-Funktionen.
- **Hülle bleibt die einzige Limit-Quelle:** 16 KB / Tiefe 3 werden nicht im
  Fachschema dupliziert.

## Tests

`backend/tests/Feature/EventTypePresetSchemaTest.php` — Voll-Preset,
Minimal-/Leer-Preset, jede Regelverletzung (falscher Typ, unbekannter Key,
Tiefe/Größe, falsche/fehlende Version, Quota negativ/zu groß, Frist-Spanne
1/365/0/366, fehlendes/fremdes Badge-Template, unbekannter/doppelter/fremder/
ungültiger Kategorie-Slug, leere Teilobjekte, Update-Pfad).
