# 02 — Domain-Model

## SOLL — Entity-Übersicht

Hierarchie: **Mandant (Verband) → Team (Verein, optional) → Kategorie →
Akkreditierung (Quota + Frist) → Application → Sub-Akkreditierung.**

| Entity | Beschreibung |
|---|---|
| `mandants` | Verbände, eigene Domain, Logo/Header/Legal-Texte, `teams_enabled` |
| `domains` | Hostname → Mandant-Zuordnung (Routing) |
| `teams` | Vereine (optional je Mandant), Heimstätte (Default-Ort), Kategorie-Overrides |
| `categories` | z. B. Presse, Fotograf, Delegation; erbt vom Mandant, Team überschreibt |
| `events` | Titel, Datum, Ort (Default = Heimstätte, überschreibbar), Wettbewerb, Frist (Default/Override); Ebene Mandant oder Team |
| `accreditations` | Kategorie + Event/Scope, Quota, Frist, VIP/Blacklist-Konfiguration |
| `applications` | Antrag: Kategorie/Scope, Status `requested/approved/denied/blacklisted`, Foto/Anhänge |
| `sub_accreditations` | Park-/Sitzkarte, nur bei Haupt-Akkreditierung, eigenes Kontingent, auto/manuell |
| `users` | pro Mandant eigenes Konto; Rollen `super_admin/mandant_admin/team_admin/user/verifier` |
| `badge_templates` | Ausweis-Layout (Feld-Set, Positionen, Logo/Header/Farben) |
| `blacklist` | Gesperrte Personen + Domänen (Block auf Mandant-Ebene) |
| `wallet_passes` | Apple/Google Wallet (PKPASS) je Akkreditierung |

## Anmelde-Scopes

Event/Spiel · Liga-weit · Saison (Verein) · Pro-Spiel.

## Portabilitätsregel

Schema/Queries bleiben zwischen **Postgres (Dev/Prod)** und **SQLite
`:memory:` (Tests)** portabel: kein PG-spezifisches SQL in Migrationen/Queries,
JSON-Spalten via Laravel `json`-Type, Datumsarithmetik über Query-Builder/
Eloquent. Wo Postgres-Features nötig sind → Service-Abstraktion + separater
Integrationstest.

## Anmerkung

Die genauen Spalten/FKs werden mit den P1/P2-Migrationen festgeschrieben.
Diese Skizze ist der stabile Ausgangspunkt („Setzen wie Sportdata Accreditation
Services", Nachbau auf eigenem Stack).
