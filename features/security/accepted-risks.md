# Akzeptierte Risiken (Security Risk Register)

Dauerhaft geführte Liste **akzeptierter** Sicherheitsrisiken dieses Projekts.
Sie ist eine Sammlung von Entscheidungen, keine Regelleiste; die Regel, **wo**
Befunde geführt werden, steht in `AGENTS.md` §10.

- Ein **akzeptiertes** Risiko steht hier (mit Begründung und Re-Evaluierungsbedingung).
- Ein **offener** Befund steht in `AGENTS.todo.md`.
- Ein **geschlossenes** Risiko bleibt hier als Historie stehen, bis es in der
  Git-Historie aufgeht.

Einträge:

- **A1 (accepted 2026-09-19, low):** `team_admin` kann per `GET /api/admin/teams/{team}/logo`
  das Logo eines Sibling-Teams im **eigenen** Mandanten lesen (Route-Gate `teams.view` ohne
  Team-Scope; `TeamController::showLogo`). Auth-gated, mandanten-isoliert, Public-Asset —
  bewusst akzeptiert (W4-F2 L1). Re-evaluieren, falls Team-Logos je sensitiv werden.
- **A2 (accepted 2026-09-26, low):** `deployment/dev.env` ist **versioniert** und enthält
  einen `APP_KEY`-Platzhalter aus 32 Null-Bytes plus die Dev-DB-Zugangsdaten
  (`accriditation`/`accriditation`). Kein Geheimnis, aber ein Prod-Footgun: die Basis-
  `docker-compose.yml` erzwingt `APP_KEY` per `${APP_KEY:?…}`, ein unbedachtes
  `docker compose --env-file deployment/dev.env --profile prod up` würde also **starten**.
  Mitigation ist der Boot-Guard `AppServiceProvider::assertProductionAppKeyIsStrong()`, der
  genau diesen Platzhalter in `APP_ENV=production` mit Log-Critical ablehnt — der Dienst
  verweigert den Start statt still mit bekanntem Schlüssel zu laufen. Deckt sich mit A3.
- **A3 (accepted 2026-09-26, medium):** `MANDANTS_CACHE_TTL` (Default 3600 s) cacht die
  Host-Allow-List der Mandant-Domains. Wird eine Mandant-Domain **gelöscht**, muss der
  Cache explizit invalidiert werden (`MandantContext::forgetHostnames()`), sonst bleibt der
  Host bis zum TTL allow-listed. Das ist **kein** Datenzugriff (der Host löst danach auf
  `MandantContext` auf und liefert 404, weil es die Mandant nicht mehr gibt), aber es
  ist eine unnötige Angriffsfläche für Subdomain-Takeover-artige Szenarien. Für jeden
  hostname-schreibenden Pfad (Domain anlegen/ändern/**löschen**, Mandant löschen) ist der
  Aufruf Pflicht — inklusive der inzwischen nachgezogenen `MandantController::destroy()`.
  Re-evaluieren, wenn `trustHosts` oder die Cache-TTL umgebaut werden.
- **A4 (accepted 2026-09-27, low):** zwei offene Dependabot-Advisories für `npm/svgo`
  (1 × high `removeScripts`-Executable-Links, 1 × medium `foreignObject`-Sanitize;
  **beide ohne `first_patched_version`** — es gibt derzeit keinen Fix zum Einspielen).
  `svgo` ist **keine** direkte Abhängigkeit, sondern transitive über
  `@iconify/tailwind4` → `@iconify/utils`, und der Pfad ist **rein build-zeitlich**:
  SVGOs Input sind die gepinnten Icon-Sets `@iconify-json/mdi` und
  `@iconify-json/material-symbols`, die `vite build` über den Tailwind-v4-Plugin
  verarbeitet. Die Icons werden zur Laufzeit ausschließlich als CSS-Klassen
  referenziert (`iconify mdi--menu`), es gibt **keinen** SVG-Optimizer-Lauf und
  **keinen** untrusted-SVG-Durchsatz — hochgeladene Medien laufen serverseitig über
  `symfony/html-sanitizer` (siehe `AGENTS.md` §11), nicht über dieses npm-Paket. Die
  Advisories betreffen `removeScripts`-Sanitize von Angreifer-SVG und greifen daher nur
  bei unvertreutem Input. Bewusst **kein** Lockfile-Churn auf eine nicht existierende
  Version. Re-evaluieren, wenn (a) ein `svgo`-Release mit Fix erscheint, (b) Iconify
  von der CSS-Klassen-Nutzung auf den Vite-Plugin-Pfad wechselt (dann verarbeitet
  SVGO ggf. eigene Icons) oder (c) SVGO je zur Laufzeit auf User-Uploads gelegt wird.
  **Geschlossen 2026-10-05 (Position 50):** `svgo` 4.1.0 erschienen und per
  `pnpm update svgo` eingespielt (Lockfile; `pnpm audit` 17 → 2 Advisories, beide
  svgo-Treffer weg). Pfad und Begründung oben bleiben als Historie stehen.
- **A5 (accepted 2026-09-28, medium):** Die **Konto-Löschung** (Nutzer selbst, `mandant_admin`
  und `super_admin`) ist **unumkehrbar und protokolliert nur in das Anwendungslog** — es gibt
  **kein** `audit_logs`, weder Migration noch Model. **Nutzerentscheid 2026-09-28:** „kein
  Audit, nur das Anwendungslog". **Konsistent mit dem Bestand:** das Löschen eines *Mandanten*
  protokolliert bereits auf dieselbe Weise (`MandantController:283`, `Log::error(…,
  ['mandant_id' => …])`), die Entscheidung führt also kein fremdes Muster ein, sie erweitert
  ein bestehendes. **Die Löschung ist deshalb verpflichtend, strukturiert und mit
  VOLLSTÄNDIGEM Kontext zu loggen** — Akteur, Ziel-Id, Ziel-E-Mail, Mandant, Anzahl der
  mitgerissenen Anträge, Media und Rollenzuordnungen — weil das Anwendungslog der **einzige**
  Ort ist, an dem die Frage „wer hat welches Konto gelöscht" je beantwortet werden kann.
  **Die Haltbarkeit hängt an einer Konfigurationsvariable, nicht an einer Zusage:**
  `LOG_STACK` ist in `.env.example` auf `single` gesetzt (eine Datei, **keine** Rotation per
  Config); ein Wechsel auf `daily` deckelt auf `max_files = 14`, auf `monthly` auf `3` — beide
  **hart kodiert** in `config/logging.php`. Wer den Kanal umstellt, ohne das zu bedenken, macht
  die Protokollierung unbrauchbar, **ohne** dass irgendetwas rot wird. Re-evaluieren, wenn
  (a) `LOG_STACK`/`LOG_DAILY_DAYS` umgebaut werden, (b) Protokolle in ein externes System
  wandern, oder (c) die Nachweispflicht aus einer Vertrags- oder Zertifizierungsanforderung folgt.
- **A6 (accepted 2026-09-28, medium):** `JWTGuard::logout()`
  (`vendor/php-open-source-saver/jwt-auth/src/JWTGuard.php:219-223`) hat
  `catch (JWTException $e) {}` mit dem Kommentar *„Proceed with the logout as normal if we can't
  invalidate the token"*. Wirft der Cache-Storage beim Sperren eine `JWTException`, antwortet
  `POST /api/auth/logout` mit **200 „Erfolgreich abgemeldet."**, obwohl **nichts** gesperrt wurde
  — gemessen mit einem Storage, der gezielt wirft. **Vendor-Verhalten, im Repo nicht zu ändern**:
  die Datei liegt unter `vendor/` und jede Änderung wäre beim nächsten `composer install` weg;
  stattdessen ist der **Docblock** `AuthController:246` („Invalidates the current JWT
  (blacklist)") zu relativ und beschreibt einen Pfad, der im Fehlerfall schweigt.
  **WICHTIG, zwei Korrekturen vom 2026-09-28, und die zweite heisst die erste nicht zurücknehmen:**
  Der ursprüngliche Beleg („logout widerruft, danach 401") war ein **Falsch-Positiv** — der
  Harness transportierte das Cookie **nicht** (`withCookie()` verwirft still), der 401 kam aus dem
  geleerten `JWT::$token`-Singleton. **Die Korrektur dieses Falsch-Positivs ist aber kein Beweis
  gegen Weg B, sondern endlich ein gültiger Beweis dafür**, gemessen über den einzigen
  funktionierenden Kanal (`TestCase::withJwtCookie()`):

  | Messung (Konto **besteht** durchgehend) | Ergebnis |
  |---|---|
  | Replay bei vorhandenem Blacklist-Eintrag | **401** — der Widerruf hält |
  | Replay nach `Cache::flush()` | **200** — Eintrag weg, Token gilt wieder |

  **Damit ist die Schwachstelle, die dieser Eintrag als *konditional* führte, gemessen statt
  vermutet:** sie entsteht genau dann, wenn jemand den Cache leert. Der Deploy tut es nicht
  (`entrypoint.sh`, per Test festgenagelt), also ist sie **nicht aktiv** — aber sie ist auch nicht
  weg, und ihre Obergrenze ist `JWT_TTL`. Wirft der Storage statt dessen eine `QueryException` —
  was eine fehlende Tabelle erzeugen würde —, ist es **laut** (500), nicht still. **Entscheidend
  für die Architektur:** die Konto-Löschung stützt sich **nicht** auf diesen Weg, sondern auf den
  **DB-Treffer** in `JWTGuard::user():107` (`retrieveById($payload['sub'])`) — fehlt die Zeile,
  ist `$this->user` null → **401 sofort**, und das gilt **auch nach einem vollständigen
  `Cache::flush()`** (gemessen mit zwei Prämissen, die eine Fehldeutung ausschliessen: Cache
  nachweislich leer, Token nachweislich unexpired — die Ablehnung kann nur aus der fehlenden
  Zeile kommen). **Weg B scheitert an genau derselben Probe** (nach dem Flush wieder 200). **Weg A
  ist also nicht von Weg B abhängig**, und `AccountDeletionRevokesAccessImmediatelyTest` nagelt
  das fest — mit der Gegenprobe, dass dasselbe Token **vor** dem Löschen 200 liefert, und der
  Mutation (Löschen → Zeile behalten), die **alle vier** Tests rot macht. Re-evaluieren, wenn das
  Paket aktualisiert wird oder wir von der Cache-Blacklist auf einen persistenteren Store wechseln.

  **Korrigierte Zahlen (2026-09-28, gemessen statt gerechnet):** Die Blacklist-Einträge leben
  **~10081 Minuten (~7 Tage)**, nicht ~61 — `Blacklist::getMinutesUntilExpired()` nimmt
  `$exp->**max**($iat->addMinutes($refreshTTL))`, den **späteren** der beiden Zeitpunkte. Die
  **sicherheitsrelevante** Grenze bleibt trotzdem `JWT_TTL` = 60 min, gemessen von der **Ausgabe**
  (`iat+59min` → 200, `iat+62min` → 401), denn `exp` greift, sobald der Eintrag fehlt. **Und die
  Ursache ist unkritisch:** `deployment/entrypoint.sh` führt `migrate → storage:link → seed →
  config:cache|config:clear` aus, **kein** `cache:clear`/`optimize:clear`; ein Test nagelt das
  fest (eine Zeile `cache:clear` in den Entrypoint einfügen → rot). Ein **Container-Neustart beim
  Deploy** ändert daran **nichts**: `CACHE_STORE=database`, die Blacklist ist eine Zeile in der
  `cache`-Tabelle im Volume `db_data`, nicht im Dateisystem des Containers. Das Risiko ist damit
  **konditional** — es entsteht erst, wenn jemand ein Cache-Leeren einbaut.

  **Und die Schärfe dieser Silhouette ist höher als zunächst notiert:** wirft der Storage eine
  `JWTException`, antwortet die Route mit **200 „Erfolgreich abgemeldet."**, der Store ist **leer**
  — **und das Token funktioniert weiter** (gemessen, nicht behauptet). Fail-open in einem
  **Auth**-Pfad: Erfolg gemeldet, Wirkung ausgeblieben.
- **A7 (accepted 2026-09-28, low):** **`Mandant` und `MandantDomain` sind nicht route-gebunden.**
  `User` **ist** es (`User::resolveRouteBindingQuery()` scoped über `isMemberOfMandant()` seit
  `a761f4a`, weil dort PII gemint wird) — `Mandant` trägt dieselbe Form **nicht**, weil die naive
  Lösung falsch ist: ein `where('mandant_id', currentId)`-Scope entzöge `super_admin` den Zweck,
  jeden Mandanten über einen beliebigen Host zu verwalten (`PUT /api/admin/mandants/{B}` mit A als
  Host ist ein getesteter, gewollter Flow). `MandantDomain` ist **überhaupt nicht** gebunden
  (`DELETE …/domains/{domain}` nimmt `string $domain` und sucht selbst über die Relation). **Was
  durchsickert, ist die blosse Existenz eines Mandanten** — jeder Mandant hat ohnehin ein
  öffentliches Portal, sein Hostname steht in der öffentlichen `trustHosts`-Liste, und es sind
  eine Handvoll Zeilen. **Kein Datenzugriff.** Die richtige Form, falls jemand es will, ist eine
  **403/404-Unifikation im Controller** — wie `TeamController`/`UserController` sie für ihre
  Ressourcen bereits haben. **Herkunft:** stand als offener Follow-up im Review vom 2026-09-26;
  beim §4-Schnitt des Boards (Archiv raus, aktueller Plan rein) wäre es ohne diese Zeile gefallen.
- **A8 (accepted 2026-10-05, low):** `npm/braces` high-Advisory, **kein Patch verfügbar**
  (`patched_versions: null`) — Kette `braces@3.0.3 ← micromatch@4.0.8 ← @lingui/cli +
  eslint-plugin-lingui`, zusätzlich Wurzel über `@lingui/vite-plugin` (in `dependencies`,
  indirekt via `@lingui/cli`; gemessen `pnpm why --prod`). Also **im Produktionsgraph, aber ohne
  Browser-Durchsatz**: Build-Tooling, gemessen 0 Treffer für `braces`/`micromatch`/`picomatch` im
  Build-Bundle; `@lingui/vite-plugin` lädt nur in `vite.config.ts`/`vitest.config.ts`.
  *(Korrigiert 2026-10-05, Runden 50/51: erst „dev-only"-Etikett, dann dessen Umkehrung — beide
  ohne vollständige Pfadmessung; diese Fassung misst beide Wurzeln.)* Wie A4, nur ohne Fix in Sicht:
  bewusst kein Lockfile-Churn. Re-evaluieren, wenn ein Patch erscheint. Daneben: `npm/dompurify`
  low ist per Dependabot-PR **#23** (`3.4.15 → 3.4.16`) abgedeckt — kein Register-Eintrag nötig,
  Deferral per PR.
