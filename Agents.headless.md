# Headless-Regeln — open-accreditation auf geteilten, displayfreien Maschinen

Verbindliche Arbeitsregeln für Implementierungs- und Verifikations-Agenten auf
einer **headless** Maschine: kein Display, gemeinsam genutzter Build-Host, kein
GPU-Bedarf. Ergänzt die `AGENTS.md` im Repo-Root (Projektregeln) und
`backend/AGENTS.md` / `frontend/AGENTS.md` (Modulregeln); es **ersetzt nichts**
davon.

**Für wen diese Datei gilt:** jeden Build- oder Verifikations-Agenten auf so einer
Maschine. Die globale `AGENTS.md` verweist für diesen Workspace hierher.

**Woher sie kommt:** nicht aus Vorsicht, sondern aus vier gemessenen
Fehlschlägen dieser Maschine. Jede Regel unten hat einen Befund, der eine ganze
Verifikationsrunde gekostet hat. Eine Regel ohne solchen Befund gehört nicht
hierher.

---

## 1. Grundsatz: „hier nicht testbar" ist eine Aussage über ein Gate, nicht über die Aufgabe

Die zentrale Regel. Auf dieser Maschine sind Postgres und Mailpit **nicht** über
`localhost` erreichbar, `ps` fehlt, und ein geteilter Host kann andere
Dienste wegschieben. Das entbindet nicht von der Arbeit:

1. **Implementieren.** Ein Task wird nicht wegen einer fehlenden Umgebung
   zurückgestellt oder auf eine abweichende Variante umgebogen.
2. **Alles headless Prüfbare prüfen.** Backend-Suite, Postgres-Gate, Vitest,
   `lint`, `build` inkl. `check:i18n`, Playwright-Specs einzeln — alles läuft
   vollständig. Diese Abdeckung ist echt und wird vollständig ausgewiesen.
3. **Den nicht prüfbaren Teil als benanntes Gate führen.** Was nicht gemessen
   werden konnte, wird einzeln aufgeführt: die CI-Jobs, die volle
   Nightly-Suite, das Design-QA-Capturing unter Fremdlast.
4. **Nichts über das Gate hinaus behaupten.** Weder „läuft in CI" noch „wird dort
   schon passen". Der Goldensatz: *auf diesem Host gemessen, dort ungeprüft.*

**Praktisch:** Ein Task gilt als **halb abgenommen**, nicht als abgelehnt. Genau
diese Trennung erlaubt, auf headless Maschinen echte Arbeit zu liefern, ohne
irgendwo eine grüne Zahl zu erfinden.

---

## 2. Zwei Fehlerklassen, die diese Maschine provoziert

### 2.1 Der Dienst läuft, ist aber unter einem anderen Namen erreichbar

**Der teuerste Befund dieser Maschine, und er hat sich in dieser Sitzung
zweimal wiederholt — einmal von mir, einmal von einem Verifikator.**

Gemessen am 2026-09-30: `127.0.0.1:1025` und `:8025` → `Connection refused`.
Container-IP `172.17.0.3` → `Connection timed out`. `--network host` wirkungslos,
weil Dockers „host" **nicht** der Namespace der Shell ist. Die Schlussfolgerung
„Mailpit ist strukturell unerreichbar" wurde als dauerhafte Umgebungsgrenze
festgehalten — **in `AGENTS.todo.md`, in einem Commit, in zwei Briefings an
Subagenten.**

Es war ein **Namensproblem**: `getent hosts dind` löst auf, `dind:8025`,
`dind:1025` und `dind:5432` sind offen. Der Compose-Stack bindet seine Ports auf
den `dind`-Container, nicht auf `localhost`; die Shell sieht `localhost` als einen
anderen Namespace. **Konkret für E2E:** `frontend/tests/e2e/helpers/mailpit.ts`
defaultet auf `http://localhost:8025/api/v1` — ohne `MAILPIT_API_URL=http://dind:8025/api/v1`
fallen Mail-Specs **im Setup** (nicht an einer Assertion). Gemessen 2026-10-04
(Runde 24: ein verlorener Lauf).

**Regel:** Bevor ein Dienst als unerreichbar gilt, **muss der Alternative-Host
versucht werden.** Mindestens: der Containername, `dind`, der gemeldete
Bridge-Check und der `docker`-Name des laufenden Containers. Ein
„strukturell unerreichbar" ohne diese vier Versuche ist **kein Befund**, sondern
eine Vermutung mit Bindungswirkung — sie beendet Messungen, die noch möglich
wären.

**Was es kostete:** zwei der Suite-Fehlschläge waren nie umgebungsbedingt, und
eine ganze Verifikationsrunde lief gegen eine erfundene Grenze.

### 2.2 `APP_URL` entscheidet über 518 Tests — in beide Richtungen

`phpunit.xml:111` pinnt `APP_URL`. **Diese Regel nicht anfassen**, ohne §2.2 von
`AGENTS.todo.md` (Position 39) gelesen zu haben.

Gemessen, jeweils über die volle Suite:

| `APP_URL` | Ergebnis |
|---|---|
| `https://accreditation.test` (der gepinnte Wert) | **1669 passed / 0 failed / 1 skipped** |
| `http://localhost:5173` | 1131 passed / **520 failed** |
| leer | 1132 passed / **519 failed** |

Der Mechanismus ist **nicht** der, den man zuerst vermutet. `MakesHttpRequests`
präfixiert jede URI mit `config('app.url')`; damit setzt `APP_URL` den Host
**jeder** Suite-Anfrage. Ein **Loopback-Host** trifft
`MandantContextMiddleware.php:56` → `MandantContext::set(default())` →
`default()` ist `null` → `set(null)` **radiert den Mandanten aus, den der Test in
`setUp()` installiert hat**. 515 der 520 Fehlschläge sind das; nur 10 sind der
Wert `http` im Docblock von `VerifyLink.php:15`.

**Regel:** Wer hier ~520 Fehlschläge sieht, prüft **`APP_URL` in
`backend/.env`**, bevor irgendetwas diagnostiziert wird. Diese Datei ist
gitignoriert, geteilt und von anderen Agenten beschreibbar — sie ist
Umgebungszustand, kein Projektzustand.

---

## 3. Was headless prüfbar ist — und was ein benanntes Gate bleibt

| Prüfbar headless | Gate, das hier offen bleibt |
|---|---|
| **Relevante** Backend-Tests per `--filter` (SQLite `:memory:`) | die volle Suite → **CI** (§3a) |
| `vendor/bin/pint --test`, `pnpm lint`, `pnpm build` inkl. `check:i18n` | — |
| **Relevante** Vitest-Dateien per Filter | die volle Vitest-Suite → **CI** (§3a) |
| Playwright-Specs einzeln, `--workers=1`, mit `MAILPIT_API_URL` auf `dind` | die volle Suite, das Postgres-Gate und das strikte Nightly-Profil → **CI** (§3a) |
| Postgres-Gate via `DB_HOST=dind scripts/test-pgsql.sh` **bei Bedarf, nicht routinemäßig** | der CI-Job `backend-pgsql` auf dem GitHub-Runner |
| Screenshot-Harness (gegen laufenden Stack) | das Design-QA-Capturing unter **Fremdlast** — siehe §5 |

**Eine Unterscheidung, die hier leicht falsch getroffen wird:** „Mailpit nicht
erreichbar" heißt **nicht** „Mail-Feature ungeprüft". `MAIL_HOST=dind`
(`php`) bzw. `MAILPIT_API_URL=http://dind:8025/api/v1` (Playwright) stellen
beides her. Ein nicht gelaufener Test wird als **nicht gelaufen** geführt, nicht
als grün und nicht als rot.

---

## 3a. Die CI-Lokalisierung: lokal **relevante** Tests, in CI **alles** (Nutzerentscheid 2026-10-02)

**Der Host ist teuer, CI ist kostenlos.** Diese Regel ist die Antwort auf einen Ablauf, der auf diesem
Host gemessen zu lange gedauert hat: eine Verifikationsrunde, die **alle** Gates lokal fährt —
volle Backend-Suite, Postgres-Gate, Vitest, Build, Lint **und** Mutationsproben — ist genau die Sorte
Lauf, der §4 verbietet, weil er neben nichts anderem laufen darf. Sie macht den Agenten zum
Blocker, und ein Blocker, der auf eine eigene Freigabe wartet, hält jede Welle an.

| Was | Wo | Warum |
|---|---|---|
| **Relevante Tests** (die, die die Änderung betrifft) | **lokal** | schnell, gezielt, beantwortet die Frage „habe ich etwas gebrochen?" |
| **Volle Suiten, Postgres-Gate, striktes Nightly-Profil** | **CI** | der GitHub-Runner ist ephemer und **eigener** RAM; hier kostet derselbe Lauf eine ganze Welle |

**Die Regel:** Ein Agent fährt lokal **nicht** die volle Suite. Er fährt den **Filter**, der zur
Änderung gehört, und **pusht** — CI ist das Gate, das alles fährt. Was lokal nicht gemessen wurde,
ist **nicht** behauptet, sondern als **CI-Gate** benannt (§7 Punkt 3).

**„Immer wieder nachsehen, ob der Lauf nicht eh grün ist."** Das ist ausdrücklich erwünscht und
kein Polling-Ungeheuer: **vor** jedem neuen Schritt wird `gh run list` **einmal** gelesen. Ist der
vorige Lauf grün, gilt er als Messung für den Stand, den er gebaut hat — und **nur** für diesen.
Grün auf `9ec00d1` sagt **nichts** über `0b4ec2f`, das danach kam; das ist dieselbe Form wie eine
Board-Zahl aus einem Baum, den es nicht mehr gibt, nur in CI statt im Board.

### Das Gate zwischen den Wellen — explizit geplant, nie nebenbei

**Zwischen jeder Welle steht ein benannter Punkt: „CI muss grün werden."** Er ist **kein**
Nebenprodukt des Pushens, sondern ein **Schritt mit eigenem Nachweis**:

1. Implementierung committen → **pushen** (explizite Pfade, nie `git add -A`).
2. `gh run list` **einmal** lesen, bis der Lauf für diesen SHA existiert.
3. Das Ergebnis **mit SHA nennen**. Kein „läuft in CI", kein „wird schon passen".
4. **Rot ⇒ hat Vorrang vor allen neuer Arbeit** (§5(6)f). Analysieren, isoliert fixen, grün
   pushen, **erst dann** die nächste Welle.

**Ein Block gilt für „fertig", nicht für „verifiziert."** Der Unterschied ist der ganze Punkt:
*fertig* heißt implementiert, getestet, committet. *Verifiziert* heißt von ** jemand anderem
geprüft. Was lokal teuer ist (die volle Suite), ist darum **kein** Grund, eine Welle nicht
fertigzumachen — es ist ein Grund, sie zu pushen und die CI antworten zu lassen.

**Was das an §4 ändert und was nicht.** §4 (eine volle Suite zur Zeit) gilt **unverändert** für
das, was **lokal** läuft — und das ist nach dieser Regel nur noch der **Filter**, nicht die Suite.
§6 (Verifikationsläufe sequenziell) gilt ebenso unverändert. Was entfällt, ist der Grund, sie
aufzuschieben: es gibt jetzt einen Ort, an dem die volle Suite **parallel zum eigenen Arbeiten**
läuft, ohne dass ihr Ergebnis von Fremdlast zerstört wird — der GitHub-Runner hat keine.

**Grenze, die nicht zu schönreden ist:** CI prüft **einen** Push auf **einem** Runner. Sie beweist
nicht, was unter **Fremdlast** passiert (§5 dieser Datei), und sie ersetzt den strikten Nightly nicht
— sie ist der verzeihende Standardlauf. Für Flakiness bleibt `playwright.regression.config.ts` mit
`retries: 0`, `maxFailures: 1` maßgeblich, und das fährt weiter der Nightly.

---

## 4. RAM und Parallelität

**Verbindlich, und die härteste Regel dieser Datei:**

- **Ein einziger Arbeitsstrom.** Nie mehrere Agenten und eigene Läufe
  gleichzeitig. Parallele Toolchain-Instanzen vervielfachen den Speicherdruck.
- **Höchstens zwei parallele Ströme**, wenn zwingend etwas nebeneinander laufen
  muss — und dann **nur bei disjunkten Ziel-Dateien**. Die Regel in `AGENTS.md`
  §6 ist strenger und geht vor: **eine volle Suite zur Zeit**, weder zwei
  Subagenten noch ein zweiter Lauf im selben Agenten.
- **Ein Postgres-Lauf zur Zeit.** `scripts/test-pgsql.sh` legt eine Wegwerf-DB
  an; der Lauf ist der teuerste im Repo und der, der die RAM-Spitze setzt.
- **Den RAM-Deckel nicht als Antwort auf ein OOM erhöhen.** Die Überschreitung
  auf dem *Host* ist das Risiko, nicht die Belegung im Container.
- **Ein SIGKILL/OOM während eines Laufs ist RAM-Druck, kein Defekt im Code.**
  Nach Warten mit weniger Parallelität neu messen, nicht durch Ändern am Code
  „beheben". Ein solcher Kill als Befund zu melden ist die häufigste
  Fehlzuschreibung auf dieser Maschine.

Die RAM-Zahlen selbst stehen **bewusst nicht hier**, sondern in der globalen
`AGENTS.md` — sie sind host-spezifisch und veralten.

### 4.1 Wo die Grenze NICHT gilt — und warum das eine Entscheidung ist

**Nutzerentscheidung 2026-09-30: lokal höchstens zwei Ströme, CI unangetastet.**
Nicht aus Versehen, und nicht weil die CI es nicht bräuchte — die beiden Seiten
haben **gemessen verschiedene** Gründe:

| | Dieser Host | GitHub-Runner |
|---|---|---|
| RAM | geteilter Container, die Kolonie ist ein Produktionsprozess | eigener, ephemerer Runner |
| Kürzer Testlauf unter Last | **3–5×** gebläht (14 → 56 ms bei 24 Spinnern auf 18 Kernen) | Rauschen ist toleriert (`retries: 2`, `maxFailures: 10`) |
| Folge von Parallelität | grüne Ampel **ohne Aussagekraft**, OOM killt `mariadbd` | ein RetRY, ein rerun |

**Playwright läuft im strikten CI-Profil mit `--workers=4` (gemessen grün 2026-10-05, Position 48) — die Passage unten beschreibt den Vorzustand und bleibt als dessen Begründung lesbar.** Dass zwei Kommentare derselben Datei Unvereinbares über die Login-Drossel behaupteten, wurde durch Messung entschieden, nicht durch Auswahl: der strikte Lauf mit 4 Workern zeigte weder 429-Cluster noch Mutex-Kollision.

- `ci.yml:616` (Schritt `Prepare backend environment`) + `CACHE_STORE=array`-Kommentar `:622-629`: mit `array` ist der Limiter
  **zustandslos** → er kann keine 40 Logins akkumulieren → **kein 429**.
- `ci.yml:790` (Run-Step `Run E2E-Suite`, Voll-Lauf `:866`): strikt `--workers=4` (gemessen grün), verzeihend `:868`/`:870` mit `--workers=1`.

Beides konnte nicht gelten. Der gemessene Ausweg (statt Auswahl einer der beiden
Begründungen): strikter Lauf mit `--workers=4` — grün, also weder 429-Cluster
noch Mutex. **Also damals:** `--workers=1` war der Zustand, nicht die Begründung.

**`backend-pgsql` läuft unabhängig davon seriell**,
weil paratest pro Worker eine eigene Test-DB braucht und der Job gegen **eine**
Wegwerf-DB fährt — diese Begründung ist widerspruchsfrei.

**Regel für Agenten, die hier arbeiten:** „in CI läuft es parallel" ist **kein**
Argument, eine volle Suite neben eine zweite zu stellen. Auf diesem Host ist der
zweite Lauf der, der die Aussage der ersten zerstört — nicht der langsamste.

**Reihenfolge ist eine Betriebsregel, kein Vorschlag:** Implementierung →
Commit → Verifikation. Nie umgekehrt. Ein Verifikator, der in einem Baum mit
uncommitteter Arbeit mutiert, hat keinen Rückweg (ein Verifikator zerstört nie Implementierer-Arbeit —
Skill **`build-verify`**, agents-skills, Always-on-Regel 3 in `.agents/rules/build-verify.md`) — das
ist hier keine theoretische Vorsicht, sondern ein Fehler, der in dieser Sitzung
real passiert ist.

---

## 5. Zwei Messungen, die kein voller Lauf abbildet

**Fremdlast.** Unter Last bläht jeder Test einer Datei um ein Vielfaches auf, und
ein 10-s-Budget reißt, obwohl der Test intrinsisch eine halbe Sekunde braucht. Ein
grüner Lauf neben einem zweiten ist **ohne Aussagekraft** — zwei volle Läufe
*nacheinander* kosten nur Zeit, parallel kosten sie eine grüne Ampel, die nichts
belegt. Der Nightly mit `retries: 0` und `maxFailures: 1` ist deshalb der
einzige Lauf, der eine Aussage über Flakiness trägt.

**Bandzahl im Screenshot-Harness.** Sie hängt am Datenbestand, nicht nur an den
Seeds (§7 der `AGENTS.md`). „Drei Läufe ergeben dieselbe Zahl" ist erst nach
Position 5 und Position 6 eine belastbare Abnahme.

---

## 6. Werkzeuge und Umgebung

- **`php8.5-gd` verschwindet.** Am 2026-09-30 **zweimal** gemessen: `Installed: (none)`
  trotz vorhandenem Kandidaten. Ein Lauf zeigte **1672 grün**, der nächste
  **245 rot** — bei **identischem** Baum. Die Ursache liegt außerhalb des
  Projekt-Mounts (`/usr` wird bei einem Container-Reset verworfen), nicht im Repo.
  **Die Folge ist teurer als die 245 Tests:** eine **grüne Zahl aus früher in derselben
  Sitzung ist kein Beleg über den aktuellen Host.** Wer gegen eine im Board gespeicherte
  Baseline vergleicht, liest einen Extension-Verlust als Regression und beginnt, die
  falsche Stelle zu reparieren. **Bei ~245 gleichartigen Bild-Fehlschlägen zuerst
  `php -m | grep gd`** — nicht die Fehlerstapel. Heilung:
  `sudo apt-get install -y php8.5-gd`, danach JPEG und PNG prüfen.
- **`ps` und `pkill` fehlen.** Prozesse über `/proc/*/cmdline` auflisten, per PID
  beenden. Das ist nicht nur eine Notlösung — es ist die Lesart, die
  `frontend/tests/e2e/ownership-probe/run-child.ts` ohnehin braucht, weil es auf
  Maschinen ohne `ps` laufen muss.
- **`docker compose` kann fehlen** (nur `docker` vorhanden). Dann ist
  `scripts/e2e-up.sh` nicht benutzbar; die Dienste direkt starten oder das Plugin
  nachinstallieren.
- **Nach `migrate:fresh` immer `db:seed`** (`backend/AGENTS.md`), sonst ist Login
  und Auth tot.
- **Werkzeuge dieses Workspaces** (`scripts/`, versioniert):
  `scripts/e2e-up.sh` (Stack), `scripts/test-pgsql.sh` (Portabilitäts-Gate, legt
  die Wegwerf-DB selbst an), `scripts/pdf-to-png-vision.sh` (PDF → PNG mit
  Postconditions), `scripts/assert-db-driver.php` (prüft vor dem Postgres-Lauf,
  dass der aufgelöste Driver wirklich `pgsql` ist).

---

## 7. Berichtspflicht

Ein Abschlussbericht auf dieser Maschine trennt drei Dinge, die leicht
vermischt werden:

1. **gemessen** — mit Testzahl oder Gate-Exitcode, und mit dem Wert, unter dem die
   Umgebung stand (`APP_URL`, `MAIL_HOST`, `MAILPIT_API_URL`, `DB_HOST`)
2. **kompiliert, nicht verifiziert** — zählt als **ungeprüft**. Ein grüner
   `lint`/`build`/`check` belegt Ausführbarkeit, nicht Richtigkeit.
3. **nicht prüfbar hier** — benanntes Gate **mit Begründung und mit dem, was
   versucht wurde**. „Postgres nicht erreichbar" ohne die vier Namensversuche aus
   §2.1 ist keine Begründung.

Punkt 3 ist kein Makel, sondern die Information, die den nächsten Lauf überhaupt
möglich macht. Ein Bericht, der 2. als 1. darstellt, ist wertlos — und zwar nicht
nur für diesen Task, weil er die nächste Fehlersuche in die falsche Richtung
schickt.

**Nicht als Verifikation ausgeben:** eine grüne Zahl, die ein Kompilieren erzeugt
hat · ein Gate, das nicht gelaufen ist · eine Erwartung, die man auf den
gemessenen Wert nachgezogen hat · eine grüne Suite, während eine zweite lief.
