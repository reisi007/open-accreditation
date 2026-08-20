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
