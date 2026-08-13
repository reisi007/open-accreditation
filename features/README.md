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
| `auth/01-auth-and-roles.md` | Auth-Flow (Registrierung → Aktivierung → Login-Cookie → Logout → `/me`), Rollen-Matrix, Profil/Media-Vertrag |

## P1-Status

Multi-Tenancy, Auth/Rollen und Profil/Media sind **im Ist umgesetzt**
(Host-Resolution + `MandantContextMiddleware`, JWT-httpOnly-Cookie-Auth mit
Mandanten-Isolation, `role_user`-Pivot, private auth-gated Media).
Offene Hardening-Punkte (B1–B4, F2–F5) sind als „Hardening/Follow-up" in den
jeweiligen Feature-Dateien dokumentiert und laufen als P2/P7-Items.

## Weitere folgen

- Allocation-Engine (Quota, FCFS, Blacklist, VIP, Sub-Akkreditierungen)
- Ausweis & Badge-Templates (PDF, QR, CSV/Excel)
- E-Mail-Workflow (Mailables, SMTP je Mandant)
- Wallets (PKPASS, Apple/Google Wallet)

## Struktur-Referenz

Das Portal-Projekt (`portal.reisinger.pictures`) dient als Stack-Vorlage
(neueste Dependency-Versionen, Muster für Mandant/Brand-Konfiguration,
MandantContext-Middleware und `forCurrentMandant()`-Scopes). Mandanten
(„Brands") werden hier als **Mandanten** (Verbände) geführt — ohne Themes,
nur Logo/Header-Bilder + Legal-Texte.
