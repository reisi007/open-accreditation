# Wallet / PKPASS (P6)

**Status:** Implementiert (SOLL-Dokumentation der bestehenden
`WalletPassService`). Apple Wallet (`.pkpass`) und Google Wallet für genehmigte
Applications (`type = 'main'`) und Sub-Applications (`type = 'park'|'seat'`,
also Park-/Sitzkarten).

## Typen & Routing

`WalletPassService::ALLOWED_TYPES = ['main', 'park', 'seat']`. `assertType()`
erzwingt: eine `Application` ist immer `main`; eine `SubApplication` verlangt
`park` oder `seat`. Build-Einstiege: `buildApplePass()` und `buildGooglePass()`.

## Credential-Degradation (keine Secrets im Repo)

Es werden **niemals** echte Zertifikate/Keys committet. Der Service degradiert
kontrolliert (siehe `config/wallet.php`):

- **Apple:** Ohne `WALLET_CERT`/`WALLET_KEY`/`WALLET_WWDR` wird ein **UNSIGNED**
  `.pkpass`-Bundle gebaut (`pass.json`, `icon.png` 116px, `icon@2x.png` 232px,
  `manifest.json`, ZIP). Die Struktur ist vollständig valide, die `signature`
  entfällt — iOS verweigert die Installation, das Bundle bleibt aber für
  Struktur-/Debug-Tooling und den Download-Vertrag nutzbar. Liegen alle drei
  Cert-Dateien vor, wird das Manifest via `openssl_pkcs7_sign`
  (`PKCS7_BINARY | PKCS7_DETACHED`, DER-Payload aus dem S/MIME-Output) in die
  von Apple erwartete `signature` geschrieben (uncompressed im ZIP).
- **Google:** Ohne Service-Account wird das rohe `EventTicketObject`-JSON
  zurückgegeben (Preview-Darstellung). Mit Service-Account-Email + Key (PEM-
  String, PEM-Pfad oder JWK) wird ein **RS256-JWT** (`typ: savetowallet`)
  erzeugt — das Format, das die Google-Wallet-API verlangt. Der JWK wird via
  `jwkToPem()` in einen PKCS#1-PEM-Private-Key gewandelt.

## Google Wallet Issuer Setup (Owner-Checkliste)

> **Status:** Externer Owner-Schritt (P6-B2, USER-DECISION: JETZT einleiten).
> Die Code-Seite ist fertig — bis die Credentials unten vorliegen, bleibt
> alles im kontrollierten Degradations-/Preview-Modus (siehe oben).

### Was der Code heute tut (gegen den Code verifiziert)

- **Config-Keys** (`config/wallet.php`):
  - `wallet.google.issuer_id` ← `GOOGLE_ISSUER_ID`
  - `wallet.google.service_account_email` ← `GOOGLE_SERVICE_ACCOUNT_EMAIL`
  - `wallet.google.service_account_key` ← `GOOGLE_SERVICE_ACCOUNT_KEY`
  - `wallet.google.class_id` ← `GOOGLE_CLASS_ID` (Default `accriditation`)
- **Build-Pfad** (`WalletPassService::buildGooglePass()`): Das
  `EventTicketObject` wird immer gebaut; die Antwort hängt von den
  Service-Account-Credentials ab:
  - **Ohne** Service-Account (E-Mail *oder* Key fehlt): rohes
    `EventTicketObject`-JSON als Antwort (**Preview** — strukturell valide,
    aber nicht in eine Wallet installierbar).
  - **Mit** E-Mail + Key: **lokal signierter RS256-JWT** (`typ: savetowallet`,
    `aud: google`, Payload `eventTicketObjects`) — das Format, das die Google
    Wallet API erwartet. Es findet **niemals** ein API-Call zu Google statt;
    der Service erzeugt/signiert nur und liefert das Ergebnis an den Endpoint
    zurück.
- **Einbau der Issuer-ID:** `$classId = "{issuerId}.{classId}"`,
  `id = "{classId}.{serial}"`.
  **Verifizierte Aussage:** Ohne `GOOGLE_ISSUER_ID` wird der Issuer-Teil zum
  Leerstring → `classId = ".accriditation"`,
  `id = ".accriditation.main-{applicationId}"` (leerer Präfix mit führendem
  Punkt). Präzise gilt also: Der **Preview-Modus** hängt an den
  **Service-Account-Credentials**, der **leere id-Präfix** an der **fehlenden
  Issuer-ID** — zwei unabhängige Degradationen. Eine Issuer-ID allein aktiviert
  keinen Produktiv-Pfad; erst E-Mail **und** Key zusammen schalten den
  JWT-Pfad frei.
- **Keine automatische Klassen-Registration:** Der Code legt weder
  `EventTicketClass` noch andere Ressourcen bei Google an. Die Klasse
  `{issuerId}.{classId}` muss extern einmalig existieren, sonst weist Google
  gespeicherte Objekte zurück.
- **Key-Format-Falle (wichtig):** `WalletPassService::googlePrivateKey()`
  erkennt **ausschließlich** (a) einen PEM-String/-Dateiinhalt ab
  `-----BEGIN` oder (b) eine **bare RSA-JWK** (`kty: "RSA"` mit
  `n/e/d/p/q/dp/dq/qi`). Die beim Download übliche **GCP-Service-Account-
  JSON** (`{"type": "service_account", …, "private_key": …}) wird **nicht**
  erkannt — der Service degradiert dann still in den Preview-Modus. Owner muss
  das `private_key`-Feld der JSON als PEM-Datei extrahieren (empfohlen) oder
  eine bare JWK liefern.

### Owner-Aktionen (extern, einmalig)

1. **Issuer-Account anlegen:** Google Pay & Wallet Console
   (`pay.google.com/business/console`) öffnen, mit dem Google-Konto des
   Verbands anmelden und den Zugang zur Google Wallet API beantragen
   (Organisationsname, Land, Kontakt, Use Case). Google prüft den Antrag.
2. **Issuer ID notieren:** Nach Freischaltung steht in der Console die
   numerische **Issuer ID** (Beispielformat 16-stellig, vgl. Test-Fixture
   `3388000000000000`).
3. **Cloud-Projekt vorbereiten:** Google-Cloud-Projekt wählen/anlegen und die
   **Google Wallet API** aktivieren.
4. **Service Account + Key erzeugen:** Service Account im Cloud-Projekt
   anlegen, RSA-Key erstellen und gemäß „Key-Format-Falle" als **PEM-Datei**
   bereitstellen. Key niemals committen — ausschließlich `.env`/Secret-Store
   (Invarianten unten).
5. **Service Account mit dem Issuer verknüpfen:** In der Wallet Console die
   Service-Account-E-Mail als Nutzer des Issuer-Accounts hinterlegen
   („Developer"-Zugriff), damit JWTs mit `iss = {service-account-email}` für
   diesen Issuer akzeptiert werden.
6. **EventTicketClass einmalig registrieren:** Klasse
   `{ISSUER_ID}.accriditation` (oder eigener `GOOGLE_CLASS_ID`) via Console
   bzw. Wallet-API-Insert anlegen — automatisiert der Code **nicht**.
7. **Env setzen** (Backend) und Config-Cache leeren (`php artisan config:clear`),
   falls aktiv:
   ```env
   GOOGLE_ISSUER_ID=<numerische Issuer ID>
   GOOGLE_SERVICE_ACCOUNT_EMAIL=<sa-name>@<project>.iam.gserviceaccount.com
   GOOGLE_SERVICE_ACCOUNT_KEY=</absoluter/pfad/private-key.pem>
   # optional (Default accriditation):
   GOOGLE_CLASS_ID=accriditation
   ```

### Danach — automatisierte Code-Seite (ohne weiteres Zutun)

- `/api/applications/{application}/wallet/google` antwortet statt des
  Preview-JSON mit dem signierten `savetowallet`-JWT
  (`WalletController::google`, Content-Type `application/json`).
- Apple-Seite bleibt unberührt (eigene Cert-Chain, siehe Credential-Degradation).

### Offene Code-Punkte (noch NICHT implementiert)

- **Save-to-Wallet-Erlebnis:** Das Frontend bietet den Google-Button derzeit
  nur als Datei-Download (`MyAccreditationsPage`, `download="wallet.json"`).
  Für echte Wallet-Installationen fehlt der Deep-Link
  (`https://pay.google.com/gp/v/save/{jwt}`) bzw. ein entsprechender Button —
  Follow-up, sobald der Issuer existiert.
- **Google-Pass für Sub-Akkreditierungen:** Park-/Sitzkarten haben aktuell nur
  den Apple-Endpoint (`routes/api.php` — `/sub-applications/{subApplication}/wallet`);
  ein Google-Äquivalent fehlt. Der Service selbst unterstützt `park`/`seat`
  bereits (`ALLOWED_TYPES`).

## Pass-Struktur — Apple (`applePassData`)

| Feld | Wert |
|---|---|
| `formatVersion` | `1` |
| `passTypeIdentifier` | `config('wallet.apple.pass_type_id')` |
| `serialNumber` | `{type}-{applicationId}` |
| `description` | `Akkreditierung {category}` bzw. `{Sitzkarte|Parkkarte} {category}` |
| `organizationName` | `config('wallet.apple.organization_name')` oder Mandant-Name |
| `logoText` | `Kategorie · Event` (auf 40 Zeichen gekürzt) |
| `foregroundColor` / `backgroundColor` | `rgb(20,20,20)` / `rgb(240,240,240)` |
| `eventTicket.primaryFields` | `name` (Bewerbername) |
| `eventTicket.secondaryFields` | `category` (+ `event`, falls vorhanden) |
| `eventTicket.auxiliaryFields` | `date` (Datum) + `status`/`type` |
| `barcode` | `PKBarcodeFormatQR`, `message = verifyUrl`, `messageEncoding = utf-8` |
| `teamIdentifier` | nur wenn `config('wallet.apple.team_id')` gesetzt |
| `relevantDate` | nur wenn gesetzt (siehe Semantik unten) |

## Pass-Struktur — Google (`googleObject`)

`state: ACTIVE`, `passId = serial`, `issuerName = mandant_name`,
`ticketHolderName = user_name`, `ticketType = category`, `barcode`
(`QR_CODE`, `value = verifyUrl`, `alternateText = type_label`), `textModulesData`
(Name/Kategorie/Event/Datum/Typ). `eventName` nur wenn Event vorhanden;
`dateTime.start = relevant_date` nur wenn gesetzt.

## QR / Verify-URL

Der Barcode kodiert die **öffentliche Verify-URL** (Host-Chain via `VerifyLink`):
deterministischer Token (`QrTokenService::make`) + Mandant-Host. Der Token ist
reproduzierbar (gleiche Application → gleicher Token). Für Sub-Applications ist
die **Main-Application** der Token-Träger — der QR verifiziert die verknüpfte
genehmigte Haupt-Akkreditierung. Der Pass trägt **keine Secrets**.

## `relevantDate`-Semantik (P6-B1, USER-DECISION)

`relevantDate` im Kontext (`WalletPassService::context`) bevorzugt
`accreditation.deadline_end` (bzw. `subAccreditation.deadline_end`): die
**Schließung des Akkreditierungs-Gültigkeitsfensters**. Die Lock-Screen-
Relevanz ist damit der Moment, an dem der Ausweis/Pass ungültig wird. Der
**Event-Termin ist der Fallback**, falls kein `deadline_end` gesetzt ist.

**Entscheidung (dokumentiert & im Code kommentiert):** `deadline_end` bleibt die
`relevantDate`. NIEMT die Operanden vertauschen (`$relevantDate = $deadline ??
$eventDate;`). Die Event-Date ist bewusst nur Fallback. Dies ist eine
Produkt-Semantik-Entscheidung und im Code (`WalletPassService::context`) sowie
hier festgehalten.

## Invarianten (nicht regredieren)

- Keine Zertifikate/Keys im Repo — Signatur/JWT erfolgt nur bei konfigurierten
  Credentials; sonst strukturvalides Unsigned-Bundle bzw. JSON-Preview.
- `relevantDate` = `deadline_end` (Event als Fallback) — nicht invertieren.
- QR verifiziert immer die genehmigte Main-Application (auch bei Park-/Sitzkarten).
