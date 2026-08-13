# 01 — Multi-Tenancy (Mandanten)

## SOLL

- **Mandant = Verband** mit eigener Domain (eigener Host-Header). Jeder Request
  wird über den Host-Header einem Mandanten zugeordnet.
- **Hauptseite ist selbst ein Mandant** (Plattform-Mandant, z. B. `accriditation`).
- **Kein Theme-System** (anders als Portal): pro Mandant nur Logo, Header-Bild
  und Legal-Texte (Impressum, Datenschutz, AGB).
- **Team = Verein** (optional, pro Mandant freischaltbar über
  `features.teams_enabled`).
- **Kategorien erben vom Mandant**, ein Team kann sie überschreiben
  (z. B. andere Quota, andere Frist).
- **Personen-Konten pro Mandant**: Ein Konto gehört genau einem Mandanten
  (eigene Domain) — kein Cross-Mandant-Login.

## Mechanik

- `MandantContext`-Middleware: liest `Host`-Header → `domains`-Zuordnung →
  lädt den Mandanten in den Request-Context. Unbekannte Domain → `403`.
- Alle Mandant-abhängigen Queries laufen über `forCurrentMandant()`-Scopes
  (Portal-Muster, Mandanten-Isolation darf nicht regredieren).
- Statische Fallback-/Dev-Konfiguration in `backend/config/mandants.php`
  (Platzhalter-Struktur, `domain → slug/name/logo/legal`). Die echten Mandanten
  kommen in P1 als `mandants`/`domains`-Tabellen + Migration/Modell.
- SMTP je Mandant (settings-Overlay, P5).

## Rollen-Hierarchie

`super_admin` → `mandant_admin` (Verband) → `team_admin` (Verein) → `user` →
`verifier` (Ordner). Team-Admin sieht Verbands-Akkreditierungen eigener
Personen **read-only**.
