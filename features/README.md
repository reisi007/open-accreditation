# Features — open-accriditation

Dauerhafter SOLL-Zustand des Systems: Architekturentscheidungen, Datenmodell,
API-Verträge und Feature-Spezifikationen, die langfristig gültig sind.

Temporäre Task-Listen, Code-Review-Notizen und Bug-Analysen gehören in
`AGENTS.todo.md` — **nicht** hierher.

## Inhalt

| Datei | Thema |
|---|---|
| `01-multi-tenancy.md` | Mandanten (Verbände), Host-Resolution über `mandant_domains`, MandantContext-Middleware, Team-Hierarchie |
| `02-domain-model.md` | Entity-Übersicht: P1-Tabellen (mandants, users, roles, user_media) + P2/P3-Ausblick |
| `03-caddy-brand-files.md` | Statische Brand-/Logo-Dateien im React-Projekt (Fallback), Caddy per-Mandant Datei-Overrides (SOLL) |
| `05-e2e-test-image.md` | **E2E-Test-Image `accriditation-e2e`:** accriditation-base + Node/pnpm/Composer + Playwright-Chromium vorinstalliert; CI-E2E läuft komplett im Container |
| `accreditation/01-allocation-engine.md` | Allocation-Engine (P3c): deterministische Freigabe (VIP → FCFS), Quota, Blacklist, manuell + automatisch |
| `auth/01-auth-and-roles.md` | Auth-Flow (Registrierung → Aktivierung → Login-Cookie → Logout → `/me`), Rollen-Matrix, Profil/Media-Vertrag |

## P1-Status

Multi-Tenancy, Auth/Rollen und Profil/Media sind **im Ist umgesetzt**
(Host-Resolution + `MandantContextMiddleware`, JWT-httpOnly-Cookie-Auth mit
Mandanten-Isolation, `role_user`-Pivot, private auth-gated Media).
Offene Hardening-Punkte (B1–B4, F2–F5) sind als „Hardening/Follow-up" in den
jeweiligen Feature-Dateien dokumentiert und laufen als P2/P7-Items.

## Weitere folgen

- Sub-Akkreditierungen (Park-/Sitzkarten) als Erweiterung der Allocation-Engine
- Ausweis & Badge-Templates (PDF, QR, CSV/Excel)
- E-Mail-Workflow (Mailables, SMTP je Mandant)
- Wallets (PKPASS, Apple/Google Wallet)

## Struktur-Referenz

Das Portal-Projekt (`portal.reisinger.pictures`) dient als Stack-Vorlage
(neueste Dependency-Versionen, Muster für Mandant/Brand-Konfiguration,
MandantContext-Middleware und `forCurrentMandant()`-Scopes). Mandanten
(„Brands") werden hier als **Mandanten** (Verbände) geführt — ohne Themes,
nur Logo/Header-Bilder + Legal-Texte.
