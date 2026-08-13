# Features — open-accriditation

Dauerhafter SOLL-Zustand des Systems: Architekturentscheidungen, Datenmodell,
API-Verträge und Feature-Spezifikationen, die langfristig gültig sind.

Temporäre Task-Listen, Code-Review-Notizen und Bug-Analysen gehören in
`AGENTS.todo.md` — **nicht** hierher.

## Inhalt

| Datei | Thema |
|---|---|
| `01-multi-tenancy.md` | Mandanten (Verbände), Host-Header-Routing, MandantContext, Team-Hierarchie |
| `02-domain-model.md` | Entity-Übersicht (mandants … wallet_passes) |

## Weitere folgen

- Auth & Rollen (JWT, httpOnly-Cookie, 5 Rollen)
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
