# Task Board — open-accreditation

> Stand: 2026-09-28. **Nur offene TODOs** (aktueller Plan). Architektur-SOLL wandert nach
> Umsetzung nach `features/`. Referenz: Sportdata „Accreditation Services" + Screenshots des
> Altsystems (Bundesliga/ÖFB) in `reference/`.
>
> **Status (2026-09-29):** P1–P8 umgesetzt und verifiziert. `pnpm test:run` **405/405**, `lint` ✅, `build` ✅ (inkl. `check:i18n`, 418 Nachrichten) — **alle vier CI-Jobs grün, Lauf `36552912340`** (Zahlen unten). Screenshot-Harness grün, Bandzahl **24/24/24** (Position 17).
> 
> **E2E — der fehlende Arm ist geliefert, und er entscheidet die offene Frage (2026-09-29).** Bisher stand hier „zwei vergleichbare Arme": Parent `b433fd8` **103 passed / 2 failed**, HEAD `263290f` **119 passed / 8 failed**, gleiche Worker, gleiche **Fremdlast** — und die Ursache blieb **unentschieden**, weil der Arm **ohne** Fremdlast lokal unmöglich war (Maschine neu gestartet, **Docker's Socket verschwand**). **Genau diesen Arm hat der Nightly geliefert:** Lauf `36552912340` (2026-09-29, 03:30-UTC-Cron), frischer Runner, **striktes** Profil `playwright.regression.config.ts --workers=1` (`retries: 0`, `maxFailures: 1`), Ergebnis **142 passed / 52 skipped / 0 failed** — **null** Login-Fehlschläge, **null** `toHaveURL`-Timeouts. **Damit ist die Frage entschieden, und zwar gegen die Änderung:** die 6 Netto-Ausfälle waren die **Login-Drossel unter Fremdlast** (40/min, Zähler im DB-Cache), nicht die Zugabe von `loginAdminApi()`. Ein grüner **strikter** Lauf ist hier ein stärkeres Argument als ein grüner verzeihender — **ein** Fehlversuch wäre rot gewesen, und es war keiner. **Was das nicht ersetzt:** der Screenshot-Lauf ist davon unberührt (er braucht einen laufenden Dev-Stack), und der Messarm *lokal* bleibt bis zu einer ruhigen Maschine offen.
> 
> **Backend-Gates, gemessen 2026-09-29 gegen HEAD (Position 10 geschlossen):** SQLite **1669 passed / 0 failed / 1 skipped**,
> PostgreSQL **1668 passed / 0 failed / 2 skipped** — beide grün, und **beide zählen dieselben 1670 Tests**:
> `1669 + 1 = 1668 + 2`. **Die zwei Skips sind verschiedene Tests, nicht derselbe zweimal** (beide einzeln nachgefahren):
> der **auf beiden** Engine gemeinsame ist der Mailpit-Test (`MailTest.php:516`, der gegen `127.0.0.1:1025` probeht und
> dort nichts findet — **gemessen: skipped**); der **zweite, nur auf PostgreSQL** ist der dokumentierte
> `AllocationAtomicityTest` (`AllocationAtomicityTest.php:726`), der auf SQLite **läuft** — **gemessen: passed, 6 Assertions** —
> weil SQLite-`PRAGMA defer_foreign_keys` die Verletzung stagingen kann und Postgres nur `DEFERRABLE`-Constraints aufschiebt,
> und diese Migration keine deklariert. **Der Postgres-Lauf braucht `DB_HOST=dind bash scripts/test-pgsql.sh` — `dind` ist
> in dieser Sandbox der funktionierende Postgres-Host, `localhost` ist keiner.** Mailpit liegt auf `dind:8025` (erreichbar):
> `MAIL_HOST=dind` für die PHP-Gates, `MAILPIT_API_URL=http://dind:8025/api/v1` für E2E. **Ein Verifikator hat dieses Gate
> als unfahrbar gemeldet, weil er `172.17.0.x`, `127.0.0.1:55432` und `--network host` probierte, aber nicht `dind`** — dieselbe
> Namensverwechslung, die bei Mailpit schon einmal eine grüne Suite als „umgebungsbedingt rot" verbucht hat.
>
> **Gate-Stand, zwei Quellen — die CI-Zahlen sind CI und nicht „aktuell":** **CI-Lauf `36552912340` (2026-09-29, alle vier
> Jobs grün):** E2E strikt **142 / 52 skipped / 0 failed** · Backend SQLite **1607 passed** (7892 Assertions) · Backend Postgres
> **1 skipped + 1606 passed** (7886 Assertions) · Frontend Vitest **42 Files / 405 passed** · `build` ✅ inkl. `check:i18n`
> (418 Nachrichten) · `lint` ✅. **Lokal gemessen (derselbe Tag, nach der Konto-Löschung):** die beiden Backend-Zahlen oben.
> **Frontend und E2E wurden lokal nicht nachgefahren** — dort steht weiter die CI-Zahl, und wer sie als frisch liest, irrt.
> 
> **Verbleibend:** Go-Live (wartet auf Benutzer-Freigabe) + **17 Positionen** in der Batch-Tabelle (5–44). **Offen sind 13 davon:** 5, 6, 8, 9, 12, 14, 22, 38, 40, 41, 42, 43, 44. **Geschlossen, aber stehengeblieben sind 4:**
> 17, 21, 23, 25 — sie bleiben stehen, weil **lebende Querverweise** daran hängen (Begründung: „Offen bei Übergabe").
> 13 offen + 4 stehengeblieben = 17 Zeilen; **aus der Datei nachgezählt**, nicht aus der Prosa.
> **Position 10 ist am 2026-09-30 per §4 entfernt** — drei Verifikationsrunden, Verdict **`APPROVED`**, kein critical/high. Ihre Befunde sind **nicht** ins Board gewandert, sondern in `features/auth/01-auth-and-roles.md` (F1-Wächter, F3-Akteur, F5-Invariante, F6-Zustandstest, F4s **offene** Produktfrage) und `features/badges-qr.md` (Content-Stream-Defekt, `/Length`-Extraktor); beides vom Sweep-Agenten **am Code verifiziert**, nicht geglaubt.
> **Der Sweep hat dabei eine Nutzerentscheidung gefunden, die ich beim Schreiben der Zeile verloren hatte:** die Verzögerung der `/konto`-Manifest-Eintragung stand ausschließlich dort. Zurück als **43**.
> **Position 10 ist am 2026-09-29 per §4 entfernt** (Konto-Löschung, dritte Verifikations-Runde: `APPROVED`, kein critical/high). Ihr **F1/F3/F4/F5/F6 stehen in `features/auth/01-auth-and-roles.md`**, die Badge-Befunde in `features/badges-qr.md` — gegen den Code geprüft, nicht geglaubt (§3).
> **40** ist am 2026-09-29 **neu** aufgenommen, aus demselben Vorgang wie **39**: der Verifikation
> der Konto-Löschung (damalige Position 10, inzwischen per §4 entfernt — F2 war deren Frontend-Befund).
> **40** widerlegt die Begründung, mit der die Konto-Löschung als sauber gemeldet war, und trägt eine
> Reihenfolge, deren Umkehr drei Specs rot macht.
> **39** (PHPUnit-Hermetizität, `APP_URL`) ist am 2026-09-30 per §4 entfernt — implementiert (`a03e7a6`),
> verifiziert **`APPROVED`**, kein critical/high; ihr Low (unterminierter Docblock) in `72ab410` behoben,
> der eine eigene Regression bekommen hat (`AppUrlHermeticityTest.php:295-326`). Ihre
> Dauerentscheidung („die Suite ist eine **eigene Umgebung** und liest die Entwickler-`.env` nicht") steht
> im **Code**, den §4 nicht schneidet: `backend/phpunit.xml:53-110`, `AppUrlHermeticityTest.php:17-90`,
> `TestCase.php:405-453`. Die **Rest-Exposition** trägt **42**; in `features/` steht die Entscheidung
> **nicht** (gemessen, `grep` nach `hermeti`/`APP_URL`: einziger Treffer `features/02-domain-model.md:310`
> — F2-Fallback, anderer Gegenstand).
> **Position 13 ist am 2026-10-01 per §4 entfernt** — `withCookie()` transportiert im API-Test **nichts**,
> Auth-Tests waren aus dem falschen Grund grün. Geschlossen nach **der Definition, die die Zeile selbst nannte**:
> Verifikationsrunde **`APPROVED`**, **kein Befund offen** (die schliessende Runde fand überhaupt keinen, nicht
> einmal `low`), und **gepusht** — der Zustand existiert damit und nicht nur lokal (`31edc45..26f0487`;
> `git log --oneline origin/main..HEAD` ist leer, **geprüft**). **Der Verdict selbst ist nicht im Repo und wurde
> nicht nachgeprüft** — nachgewiesen ist, dass der gepushte Stand der verifizierte Stand ist.
> **Was die Zeile trug, steht in Dateien, die dieser Schnitt nicht anfasst**, jede Stelle am Code geprüft statt
> geglaubt (§3): die **Kanalregel** samt Warum und gemessener Tabelle in `backend/tests/TestCase.php:524-574`
> (der Docblock benennt beide gebrochenen Schreibweisen — `prepareCookiesForJsonRequest()` ohne
> `withCredentials()`, und `withCookie()`+`withCredentials()` als Chiffre, weil die `api`-Gruppe kein
> `EncryptCookies` bekommt — und den `JWT::$token`-Singleton als die stille 200-Quelle, die den Falsch-Positiv
> trug) · der **STRICT-Schalter** und sein ausdrückliches **Nicht**-Default in `backend/AGENTS.md:91-122`
> (`JWT_AUTH_STATE_STRICT=1`, „der Normalfall misst das Produkt, und die Ausnahme-Liste soll nicht stillschweigend
> wachsen können") · die **vier blinden Wächter** — vier verbotene **Aufrufe** *und* die vier
> `protected`-**Transport-Properties** dahinter — in `backend/tests/Feature/ForbiddenJwtCookieChannelTest.php:14-80`,
> mit der Begründung im Klartext: ein toter Kanal macht die Suite nicht rot, er macht sie *grün aus einem Grund,
> den niemand notiert hat* · die **Dauerlektion** in `AGENTS.md` §7 (`:351-357`, `:362-365`): fünf Runden,
> **kein einziger Verhaltensfehler** — gescheitert sind die *Aussagen über* den Code. „Wem ein Befund gehört"
> steht in `AGENTS.md:165`.
> **Nur Board-eigen war die Zahlenlehre in ihrer schärfsten Formulierung** („ein Commit, der über den Baum
> schreibt, auf dem er sitzt, ist keine Ausnahme, sondern die Regel") — die tragende Regel steht in
> `AGENTS.md` §2 (`:70`, „Snapshot, kein Zustand"). **Ein Nebenbefund ist offen und *nicht* ins Board gewandert,
> weil er eine `features/`-Zeile betrifft und §4 diese Datei nicht schneidet:** `features/venue-master-data.md:360-361`
> trägt die **veralteten** Gate-Zahlen `1534/1533` noch im Text (echt: `1732/1731`) — `AGENTS.md:70` **nennt** die
> Stelle, der Satz selbst ist aber nicht korrigiert.
> **Der Sweep hat eine Nutzerentscheidung gefunden, die die Zeile allein trug:** §7 Auflage 3 hat in Position 13
> ihren **ersten Anwendungsfall verloren** — der Nutzer hat die Rückgabe überschritten. Zurück als **44**.
> **7** (Nightly) ist mit dem Wave-C-Sweep mit entfernt — geschlossen, verifiziert, ohne Querverweis; ihre Zahlen
> stehen in den Absätzen darüber und im Wave-C-Abschnitt.
> **Position 22 trägt zwar `**[x]`
> VERIFIZIERT`**, ist aber per Verifikatorentscheid **korrekt offen gelassen** — der Marker gilt dem Verifikationslauf,
> nicht der Position; ein mechanischer `[x]`-Sweep würde genau die offene Position löschen.
>
> **Zweiter §4-Durchgang 2026-09-29:** 11, 15, 16 entfernt — jede Zeile **gegen den Code geprüft**, nicht
> geglaubt (§3): der Store-Pfad-Guard existiert und ist verdrahtet (`frontend/scripts/ui-review-captures.test.ts:44`,
> in `pnpm test:run` via `frontend/vitest.config.ts:33`), die Marker-Tabelle deckt alle drei Spec-Namen und wird
> **in der richtigen Richtung** getestet (`namespace-isolation.spec.ts:317-336`), und der Logo-Reset nimmt den
> Mutex (`tests/screenshots/helpers/dataset.ts:402`). Was die Zeilen trugen, steht im Git und im Abschluss.
> **Dritter §4-Durchgang 2026-09-29 (Welle C):** 30–37 entfernt, **neu aufgenommen: 38**. Welle C ist durch — implementiert
> (`2e9ea93`) → verifiziert `CHANGES REQUIRED` (2 × `high`) → behoben (`47a3a4c`) → re-verifiziert **`APPROVED`** (kein
> critical/high) → die zwei verbliebenen Lows behoben (`6900ef7`). **Bevor die Zeilen fielen, ist jeder der vier neuen
> Befunde gegen HEAD geprüft, nicht geglaubt** (§3): **einer** ist echt offen und als **38** wieder eingetreten, **drei**
> sind in den Code gewandert, den §4 nicht schneidet. Der Verbleib steht im Welle-C-Abschnitt unten. Das **bekannte Rot**
> aus D26/D27 steht **nicht** mehr nur hier, sondern dauerhaft in `features/05-e2e-test-image.md` (`077d51d`).
> **Abschluss dieser Session:** unten im Board.
> 
> **Archiv entfernt (2026-09-28, auf deine Entscheidung):** die abgeschlossene Session-Historie ist weg — §4 verlangt nur offene Punkte, also den aktuellen Plan. Sie steht im Git: Commit-Range `263290f..<dieser Commit>`, Datei `AGENTS.todo.md`. **Was der Schnitt dabei gerettet hat, steht im Abschluss** — die `Mandant`-Route-Bindung war als offener Punkt in **keiner** Position geführt und ist jetzt `AGENTS.md` §10 **A7**.

---

## ✅ Welle C — Zombie-Fix-Nacharbeit (2026-09-29, **abgeschlossen**)

> **Umfang:** die acht Positionen **30, 31, 32, 33, 34, 35, 36, 37** — davon **32(b)** als **D26** weitergeführt. Alle S1, alle im selben Strang
> (`frontend/tests/e2e/child-lifetime.spec.ts`, `frontend/tests/e2e/ownership-probe/run-child.ts`,
> `deployment/Dockerfile.e2e`).
>
> **Verlauf: implementiert (`2e9ea93`) → verifiziert `CHANGES REQUIRED` (2 × `high`) → behoben (`47a3a4c`) →
> re-verifiziert `APPROVED`, ohne critical/high → die zwei verbliebenen Lows behoben (`6900ef7`).**
> Die Positionen sind per §4 **entfernt**; dieser Abschnitt bleibt als Abschluss, weil er den einzigen Ort
> darstellt, an dem die vier Befunde aus der Implementierung ihren Verbleib haben.

**Was Welle C wert war — nicht die acht Zeilen, sondern das Prüfverfahren.** Jede Position wurde **gegen HEAD
geprüft, nicht geglaubt** (§3), und **mehrere** trafen den Code nicht:
30(M1/M3) · 31 · 33(L1) · 35(a/b) · 36 · 37 = **CONFIRMED+fixed** · 33(L2) = **ALREADY-SATISFIED**, nichts
geändert · 33(L3) = **DOES-NOT-HOLD-AS-DESCRIBED** (die BusyBox-Zitatstelle existiert an HEAD nicht mehr,
gelöscht in `c119f1d`) — **keine Arbeit erfunden**, das ist der eigentliche Wert · 33(L4) = Board-Text veraltet,
**aber der tiefere Punkt des Boards stimmte**: `pgrp === pid` beweist „Gruppenleiter", nicht „unser Prozess" →
`isSameProcess` (pid/pgrp/**comm** gegen die bei Kill-Zeit gespeicherte Zeile).

**Blockierend waren 2 `high`, beide in der Prüfapparatur selbst — die Klasse, für die dieses Board existiert:**
- **H1** `run-child.test.ts:233` — die „fail loudly"-Verzweigung war auf Linux eine **Tautologie**
  (`'procfs must be readable'` gegen sich selbst). **Per Mutation bewiesen:** `freshParentTable()` → `return
  NO_TABLE` ließ die Suite bei **29/29 grün**. Die Pin nagelte also **nichts** fest, und der Testkommentar
  beschrieb exakt das Fehlverhalten, das er zeigte. Behoben in `47a3a4c` — **ohne** Plattform-Toleranz-Gate,
  weil ein leserloser Host genau der Defekt der Ein-Token-Regression war und ein Skip ihn wieder verdeckt hätte.
- **H2** `child-lifetime.spec.ts:387` — `readFileSync('/proc/1/comm')` ungeprüft; macOS hat kein procfs →
  **ENOENT**, der Test *bricht*, statt grün zu werden. Widersprach dem eigenen Docblock `:352-356`. Behoben
  in `47a3a4c` (beide Reads jetzt `existsSync`-gewertet).

**Gate-Baseline, auf der das Verdikt steht** (unabhängig nachgefahren, Zahlen bestätigt): `lint` clean
`--max-warnings 0` · Vitest **434/43** nach dem Fix, am Ende der Welle **433/43** (die Differenz ist die in
`47a3a4c` entfernte **Dublette**, kein Verlust) · `build` ✅ inkl. `check:i18n` 418 · `ownership` **8** ·
`namespace-isolation` **56** · `admin-venue` **2 passed / 2 skipped** · `child-lifetime` **3 passed /
4 skipped / 1 failed = genau das D26-Rot, mit derselben Meldung** (PID 1 comm: `opencode`), keine Assertion
abgeschwächt · **Security: neutral** — keine Befunde, und keine erfunden. **Der Nightly `36552912340`
(142 passed / 52 skipped / 0 failed) ist die Baseline von VOR `--init`** — er belegt die Login-Drossel-Frage,
**nicht** den Reaper.

**D26 (Reaper als PID 1) ist `UNPROVEN-BUT-FAIL-CLOSED`, und die Zwei-Hälften-Spaltung ist der Grund, warum
dieser Abschluss nicht einfach „grün" sagt:** outcome-proven ≠ mechanism-declared. Fehlender Reaper = rot
(gemessen: Waisenkind `Z`, `ppid=1`, PID 1 = `opencode` reapt nicht), also fail-closed — genau die Richtung,
die §3 verlangt. Der Test ist aber grün, sobald **irgendein** Reaper existiert: er beweist den **Ausgang**,
nicht die **Ursache**. **Dauerhaft festgehalten in `features/05-e2e-test-image.md`** (`077d51d`) — §3 verlangt
ein wissentlich roter Testbereich als akzeptiertes Risiko in `features/`, und §4 schneidet **diese** Datei weg;
eine Notiz nur hier wäre bei jedem §4-Durchgang still verschwunden. **Das ist genau der Fehler, den
`AGENTS.md` §10 A7 beschreibt** — er ist hier beinahe passiert und wurde nur durch den Hinweis des
Re-Verifikators gefangen.

### Die vier neuen Befunde und ihr Verbleib — **jeder gegen HEAD geprüft, keiner stillschweigend weg**

| Befund | Verbleib | Begründung — am Code geprüft, nicht behauptet |
|---|---|---|
| Snapshot-Rück-Signalisierung und Gruppensignalisierung ungeschützt gegen Pid-Recycling | **KEINE neue Position** — steht jetzt im Code | `run-child.ts:1031-1048` sagt genau das und **nennt den Preis**: „This is NOT the only place in the file that can signal a stranger." Der Kommentar unterscheidet ausdrücklich, dass die Bedingung **Sicherheit** kauft, sondern eine **Schranke** — ein recycelter Snapshot-Pid ist *eine* bereits protokollierte Identität, höchstens zweimal signalisiert, der Fresh-Walk dagegen ein ganzer fremder Teilbaum. Eine Position würde verlangen, das zu schließen — und das ist eine **Umgestaltung** des Sweeps, kein Defekt. **§4 schneidet den Code nicht**, die Aussage überlebt den Schnitt. |
| `isSameProcess` vergleicht `comm` + `pgrp`, **keinen** Kernel-Generationszähler | **KEINE neue Position** — steht jetzt im Code, und ist nicht schließbar | `run-child.ts:681-691` sagt beides: der Kernel **bietet keinen** Generationszähler, und „`comm` is a name, not a uid" — ein Fremder mit gleichem Programm in gleicher Gruppe **passiert** durch. Der Docblock nennt die Restrichtung (Fehltreffer kostet **Abdeckung**, keinen fremden Kill). **Nicht schließbar** heißt: eine Position ohne Ziel wäre ein Board-Eintrag, den niemand abarbeiten kann. |
| `DatabaseSeeder` setzt `teams_enabled => false` → `ownership.spec.ts` ist auf einer frischen lokalen DB rot, bis ein Lauf es einschaltet | **WIRD GETRACHT — neue Position 38** | Die einzige Kette, deren **Ursache** außerhalb des Codes liegt, den §4 nicht schneidet: der Seeder. Drei Glieder am Code geprüft. Vollständig in der Zeile 38. |
| Pass-2-Fresh-Walk wird **nie** ausgeführt — der Fix entfernt eine Landmine, stellt aber keine echten SIGKILLs wieder her | **KEINE neue Position** — steht jetzt im Code, mit Messung | `run-child.ts:1074-1093` trägt die Überschrift „What this fix is NOT: restored SIGKILLs" und die **Messung** darüber: `premiseHeld=false` auf dem instrumentierten Lauf, „A skipped walk issues no signals whether its table is empty or full". Der Punkt ist damit **begraben, nicht erledigt** — und genau das war der Gegenstand der Ein-Token-Regression dieser Welle (Position 34, inzwischen entfernt): eine Behauptung, die aussah wie Arbeit. |



> **Stand 2026-09-27, Ende der Go-Live-freien Runde.** Die Umsetzungspositionen sind
> abgearbeitet und verifiziert (Lauf `36337776954`, alle vier Jobs grün) und wurden
> nach §4 **entfernt** — sie stehen nicht abgehakt hier. Was bleibt, sind **ausschließlich
> Entscheidungen, die dem Build-Agenten nicht zustehen**, plus das Go-Live.
> Jede neue Position ist §5-konform zu delegieren (Implementer ≠ Verifikator) und fordert
> Tests nach §3 DoD.

| # | Offene Position | Aufwand | Warum offen |
|---|---|---|---|
| **5** | **Ein *partieller* Re-Capture (`-g <route>`) löscht die übrigen Captures — §7 Schritt 4 ist auch für neu erfasste Routen unmöglich** (medium, 2026-09-28) | S1 | **Gleiche Fehlerklasse wie der **gelöste** frühere „E2E-Lauf löscht die Screenshots", an der zweiten Stelle.** (Zeilennummern um 2026-08-28 neu vergeben: die damalige Position 5 wurde geschlossen und entfernt, ihre Nummer kam für diesen neuen Befund wieder frei — die Selbstreferenz ist bewusst aufgelöst, weil „Position 5" hier **diese** Zeile meint und nicht die gemeinte.) Gemessen: **95 → 4 PNG** nach einem Lauf mit `-g <route>`. Der Screenshot-Harness **leert sein eigenes Verzeichnis** vor jedem Lauf — und weil die Captures aller Routen **darin** liegen, nimmt ein gezielter Lauf alle anderen mit. **Warum das getrennt von jenem E2E-Lösch-Befund zählt:** der ist behoben, indem die beiden Läufe **verschiedene** `outputDir` bekommen. Das hier ist **dieselbe Ursache im selben Harness** und braucht dieselbe Art Lösung — nur innerhalb des Screenshot-Laufs, zwischen vollem und partiellem Lauf. **Konkrete Folge für das Verfahren:** §7 Schritt 4 verlangt ausdrücklich „`vision`-Subagent vergleicht **old vs new**". Nach einem Re-Capture gibt es kein „old" mehr — **auch nicht für die Routen, die man gerade neu aufgenommen hat.** Der Fix-Loop des Design-QA-Verfahrens ist damit in **beiden** Richtungen blockiert. **Braucht eine Design-Entscheidung**, die über die drei beauftragten Positionen hinausgeht: z. B. einen Zeitstempel-Sibling-Baum (`ui-screenshots/<run>/…`) statt eines flachen Verzeichnisses, oder ein Overlay, das vorhandene Dateien **nicht** löscht. **Bewusst nicht entschieden**, weil die Antwort die Verzeichnisstruktur des Harness ändert und damit jeden bestehenden Screenshot-Pfad. |
| **6** | **Die Screenshot-Seeds leaken — drei identische Läufe ergeben 35 / 45 / 66 Sections** (medium, 2026-09-28) | S1 | **Befund, der erst durch die Section-Captures sichtbar wurde** — vorher hat niemand hingesehen, weil es nur 60 Full-Page-Bilder gab, deren Zahl **konstant** war. Gemessen über drei identische Läufe: die **Seitenhöhen wachsen** (`home`/mobile **3586 → 3814 → 5112** CSS px), weil die Seeds **nicht aufräumen**: die DB hält **210 User** und **14 Badge-Templates**, `ensurePrimaryMandantAccreditation()` legt **pro Aufruf eine Kategorie** an. **Damit ist der Review-Batch über Läufe hinweg nicht stabil** — und die Section-Anzahl datenabhängig, also **keine feste Batch-Größe** vorausplanbar. **Zwei Konsequenzen, beide relevant:** (a) Der **erste** Vision-Loop dieser Sitzung lief gegen eine DB mit 210 Usern und 14 Templates — die „filled"-Zustände waren **reicher als beabsichtigt** und **nicht reproduzierbar**; ein „APPROVED" aus diesem Lauf gilt für **diesen** Datenstand, nicht für einen frischen. (b) Dasselbe Phänomen erklärt den **nicht reproduzierbaren** `a11y`-Fehlschlag: die **Zeilenzahl** der Kategorien entscheidet, wie viele Tab-Drücke der Test braucht — also hing der Fehlschlag an der **Reihenfolge**, in der andere Tests seeds abgesetzt hatten, und nicht an Timing. **Fixrichtung:** die Seeds vor dem Capture in einen **bekannten** Zustand setzen (Purge wie beim E2E-Hygiene) und die Bandzahl **protokollieren**, damit ein Batch nachvollziehbar bleibt. **`[x]`/`[ ]` STAND 2026-09-28 — der Befund ist bestätigt, die Kopplung war aber aus zwei Treibern zusammengesetzt, und einer ist weg.** Nicht aus Vermutung, sondern weil die Bandzahl jetzt **getrennt** gemessen werden kann: nach dem Zurückholen von **54 geleakten Teams + 53 Venues** (der damaligen F1-Behebung — Position 15 ist per §4-Schnitt **2026-09-29** entfernt, die Messung „107 Zeilen zurückgeholt" steht im `Abschluss`-Abschnitt) fiel `admin-mandant-detail` von **23** auf **4** Bänder, `home` von **5/8** auf **0/2**, die Gesamtzahl von **51/52 auf 24**. **Und die Gegenrichtung hält** — ein Screenshot-Lauf **nach** einem E2E-Lauf blieb bei **24**, wo sie vorher **51 → 52** gekippt ist. Das ist der direkte Beleg, dass der Team-Treiber **derselbe** war. **`[ ]` Offen bleibt genau ein Treiber: `users`.** `admin-users` steht bei **4** Bändern und **759** Usern, ~15 pro Lauf — es gibt **keine DELETE-Route für User**, und die ist Board-Position 10 (Konto-Löschung). **Der Screenshot-Harness ist damit nicht die Stelle, an der das behoben wird**; die Purge-Route ist es. Und die **Bandzahl bleibt bis dahin ein Messwert des Datenbestands**, nicht eine Zusage über das Verfahren — das ist die Formulierung in §7, und sie ist gemessen. |
| **8** | **Der Vision-Loop prüft den Editor, nicht das gerenderte PDF — dompdf-Fehler sind dort prinzipiell unsichtbar** (medium, 2026-09-28) | S1 | **Entschieden 2026-09-28: das gerenderte Ausweis-PDF kommt in den Loop.** Die Lücke ist **strukturell**, nicht sporadisch, und die Kette ist gemessen: `BadgeExportService:80` rendert **eine A6-Karte pro Antrag** in einem PDF und hat **keine** Postcondition im Backend (`grep` nach `pdf-to-png`/`has_alpha`/`corner_alpha` in `backend/app/`: **null** Treffer). Der Vision-Loop screenshotet den **Editor** — HTML/CSS, Browser. `pdf-to-png-vision.sh` prüft **ein** PDF, aber **nur wenn jemand es von Hand aufruft**. **Dazwischen liegt die Lücke:** ein Template kann im Editor **perfekt** aussehen und trotzdem 500 Ausweise **falsch** drucken. **Belegt, nicht behauptet:** genau das ist heute **zweimal** passiert — die `object-fit`-Streckung und die QR-Verzerrung waren **beide** im Editor unsichtbar, weil der Browser `object-fit` beherrscht und dompdf nicht. **Ein Editor-Screenshot kann einen dompdf-Fehler prinzipiell nicht zeigen.** **Vertrag für die Umsetzung:** (a) die Screenshot-Spec holt das PDF über den **echten** Export-Weg (`GET /api/admin/accreditations/{id}/badges/export` mit Session-Cookie) — nicht über einen Renderer-Aufruf im Test, sonst prüft der Loop etwas anderes als die Produktion; (b) rastert es mit `scripts/pdf-to-png-vision.sh` (dessen Postcondition inzwischen auf **Opazität** prüft); (c) legt die resultierenden PNGs **neben** den Editor-Screenshots ab, damit beide in **einem** Batch beim `vision`-Subagenten liegen; (d) der Auftrag an den Vision-Agenten sagt ausdrücklich, dass er **zwei Ansichten derselben Vorlage** vergleicht — Editor gegen Druck. **Warum (a) so wichtig ist:** ein im Test direkt aufgerufener `renderPdf()` wäre ein **anderer** Pfad als der Export, und genau der Unterschied, den man prüfen will, wäre ausgeschlossen. **Grenze, die nicht zu schönreden ist:** der Loop prüft **eine** Vorlage, der Export **viele** — er findet Klassen von Fehlern, keine Einzelfälle im Massenlauf. Für den Rest bleibt die **Schwarzseiten**-Frage die offene — sie ist in `AGENTS.md` §7 unter **PDF-VISION** dauerhaft festgehalten (Postcondition „Tinte vorhanden", `INK_SIGMA_MIN=0.001`: eine leere **oder schwarze** Seite gilt nicht mehr als erfüllt). **Hinweis zur Nummerierung:** dieser Satz verwies früher auf „Position 7 (Schwarzseite)" — das war eine Position aus der **alten** Nummerierung vor dem §4-Schnitt 2026-09-29; die heutige Position 7 ist der Nightly-Lauf und hat damit nichts zu tun. Der Zeiger ist aufgelöst, weil er auf eine Position zeigen würde, die es in diesem Sinn nicht (mehr) gibt. |
| **9** | **Druck: `name` läuft aus seiner Box und überlappt `category` — Überlauf muss umbrechen, nicht abschneiden** (medium, 2026-09-28) | S1 | **NUTZERENTSCHEIDUNG 2026-09-28: „nie abschneiden, muss dann mit Zeilenumbruch umgehen können ohne das ganze Layout zu brechen."** **Gemessen:** 14 pt in 40 × 8 mm, zweizeilig, im **Druck** — im Editor ist es unauffällig. **Die Ursache ist eine dokumentierte Asymmetrie, kein Zufall:** `BadgeCanvas.tsx:154` → `badgeCanvasFontSizeCss` kappt die Schrift per `min()` gegen **eine** Ein-Zeilen-Breite (`cqw`) **und** eine **zweizeilen-sichere** Höhe (`cqh`), Boden 2 px (FE4-F1, genau dafür gebaut). `BadgeRenderService::renderField:394` setzt `font-size:%dpt` **wörtlich** aus dem Template, einzige Anpassung `max(1, …)` — **kein Auto-Fit**. **Wichtig, und es begründet die Entscheidung:** der Editor kann das **nicht** ehrlich machen — eine Vorlage hat **keine Person**, er zeigt `SAMPLE_TEXT.name = „Max Mustermann"`. Er ist strukturell **optimistisch**. **Also gehört die Robustheit in den Druck**, und zwar als **derselbe Vertrag**: eine Zeile, sonst Umbruch, und die Schrift so klein, dass der **umbrochene** Text in die Box passt. **Die Umsetzung ist nicht CSS:** dompdf kennt keine Container-Einheiten, also misst das Backend die Textbreite **selbst** (Font-Metriken). **Nicht** `overflow:hidden` + Ellipse — das ist abgelehnt, weil ein abgeschnittener Name auf einem Ausweis die Person **nicht eindeutig** zuordnet, und gedruckt ist gedruckt. **Rest, der nicht wegdefiniert werden kann:** unterhalb der Untergrenze passt auch der umbrochene Text nicht in 8 mm, und ein `position:absolute`-Feld kann dann nur noch **überlappen**. Das ist eine **Grenze der Geometrie**, keine offene Frage — sie wird gemessen und benannt, nicht weggetestet. **Die Umsetzung ist möglich, und zwar ohne Schätzungen — gemessen am Code, nicht entworfen:** `Dompdf\FontMetrics::getTextWidth(string $text, $font, float $size, …)` (`:294`) delegiert an `$this->canvas->get_text_width(…)` — **denselben Canvas, mit dem gerendert wird**. `BadgeRenderService:217` instanziiert `new Dompdf` **ohne Optionen**, es gibt **keine** veroeffentlichte `config/dompdf.php`, und `DejaVuSans.ttf` **+ `.ufm`** liegen gebuendelt bei. **Folge:** die Messung benutzt **genau die Metriken, mit denen gedruckt wird** — Vorschau und Druck stimmen also **konstruktiv** überein, nicht zufällig. Das ist der Grund, warum die Asymmetrie zum Editor kein Problem ist, sondern eine Fehlstelle: der Editor nähert sich über CSS-`min()`, der Druck gar nicht — und ab jetzt kann **beides** dieselbe Zusage erfüllen. **Zwei Fallen, die der Auftrag ausdrücklich nennen muss:** - **`sans-serif` ist eine Falle.** Das CSS sagt `font-family: DejaVu Sans, sans-serif`; gemessen werden darf **nur die von dompdf aufgelöste** Familie, sonst misst man eine andere Schrift als die gedruckte. `FontMetrics::getFont()` **wirft** bei unbekannter Familie — das ist der gewünschte Ausgang: **ein nicht auflösbares Template soll laut sein**, nicht still eine plausible falsche Zahl liefern. - **`getTextWidth` hat einen `static $cache`,** in dem die Textlänge **nicht** Teil des Schlüssels ist (nur `$text` als zweiter Level — der Schlüssel selbst ist `canvasClass/font/size/spacing`). Für unsere Länge unkritisch, aber es ist eine **modulweite** Falle und gehört als Kommentar an die Aufrufstelle. **Algorithmus, aus der Entscheidung abgeleitet:** Zeilenumbruch auf die Boxbreite (Wörter, **danach** zeichenweise — sonst zerbricht „Müller-Schmidt" als Einzelwort) · passen die Zeilen in die Boxhöhe, bleibt die eingestellte Schriftgrösse · sonst **schrumpfen**, bis der **umbrochene** Text passt · unterhalb der Untergrenze überlappt es — die dokumentierte Grenze. **Kein `overflow:hidden`, keine Ellipse, kein `text-overflow`** — abgelehnt. |
| **12** | **Ein Checkout mit einem Store am alten Pfad (`test-results/ui-review/`) wird weder umgezogen noch gewarnt** (low, 2026-09-28) | S1 | Der Store ist per `mv` **von Hand** umgezogen (einmalig, in einem gitignorierten Verzeichnis). **Folge:** jeder Checkout, der noch einen Store von einem älteren Commit trägt, behält eine **veraltete Kopie** am alten Pfad — und **nichts** warnt, **nichts** migriert, **nichts** räumt sie ab. Wer danach `test-artifacts/ui-review/` nicht kennt und den alten Pfad öffnet, sieht einen **vollständigen, plausibel aussehenden, aber veralteten** Review-Batch. **Das ist die gefährlichste Form von Altlast**: nicht ein Fehler, sondern ein **richtig aussehender falscher** Befund. **Gehört behoben** — und zwar **nicht** durch Aufräumen (ein gitignorierter Ordner ist vergessen, sobald niemand mehr hinschaut), sondern durch einen **Hinweis mit Ausstieg**: meldet der Harness einen nichtleeren alten Pfad, mit dem aktuellen als Vergleich. Billig, und es verhindert, dass jemand einen Batch prüft, der nicht existiert. |
| **14** | **`Google Wallet` (P6)** | — | Externer Schritt: API-Zugang/Issuer-Setup bei Google. Von uns nicht leistbar. |
| **17** | **[x] VERIFIZIERT 2026-09-29 — per Rubrik APPROVED (kein critical/high). Fixed mit Mutations-Nachweis (2500-ms-Delay: ohne Postcondition rot, mit grün; 400-ms-Zahl korrigiert — networkidle absorbiert). Gates: test:run 405, lint clean, build OK, E2E 140 passed/50 skipped/0 failed (**damaliger Stand**; der Nightly `36552912340` vom 2026-09-29 fährt inzwischen **142/52/0**), Screenshots 75 passed, Bänder 24/24/24, Gegenrichtung grün. E2E-%-Delta: nur users +15…+30 (gemessen, als die Konto-Löschung noch offen war; die inzwischen gelieferte DELETE-Route ist `ownership.ts:183`, die **Nachmessung** des Delta steht aus). M5 VOID (CACHE_STORE=array, kein persistenter Limiter). Follow-ups: **25** (26–29 sind per §4-Schnitt 2026-09-29 entfernt — alle vier verifiziert geschlossen).** Der Capture hat keine Daten-Postcondition — ein 400-ms-Request wird als gültiges Artefakt gespeichert** (high, 2026-09-28) | S1 | `ui-screenshots.spec.ts:173` via `helpers/session.ts:20-23`. **Der Verifikator hat die Ursache gemessen, nicht die Erklärung übernommen.** `waitForAppSettled` ist `networkidle` + **300 ms**; nach einem Klick ist `networkidle` **schon erfüllt** und kehrt sofort zurück — das 300-ms-Budget ist die **einzige** Reserve, und die Daten kommen nach **102–163 ms**. **Gemessene Reserve: 159–220 ms.** Erzeugt per Verzögerung von `/api/admin/users` um 400 ms, **Dataset unverändert**: `h=950, bands=0`, und der **grüne Lauf** speichert den **Lade-Spinner** unter der Überschrift. **§7 Abnahme 1 ist damit nicht unstabil, sondern unbewacht.** Und **strukturell asymmetrisch:** Desktop navigiert per Klick, Mobile per Dokument-Laden — dieselbe 400-ms-Verzögerung wird dort absorbiert. **Die Behauptung, das seien Daten, ist widerlegt: ein fester Datensatz kann keine 20-zeilige Liste verschwinden lassen.** |
| **21** | **[x] VERIFIZIERT 2026-09-29 — Diagnose korrigiert (M4, 2026-09-29): kein Gruppen-Defekt, sondern Zombie — PID 1 reapet im CI-Container nie, kill(pid,0) auf Zombies erfolgreich. Fix: STAT-Prüfung Z/X = nicht-ausführend (fail-closed) + Deszendenten-Sweep. **Die CI-Bestätigung, die hier früher ausstand, liegt seit 2026-09-29 vor: Run `36537851878`, WATCH-EXIT=0.** Der Sweep ist nach `frontend/tests/e2e/ownership-probe/run-child.ts` gewandert und wird von `run-child.test.ts` getestet. **Die Reaper-Hälfte darüber ist eine eigene Entscheidung (D26/D27) und steht in `features/05-e2e-test-image.md` — nicht in dieser Zeile.**** Ein verklemmter Kind-Lauf überlebt als Waisenkind und schreibt weiter in die DB** (medium, 2026-09-28) | S1 | `ownership.spec.ts:87-90` — `execFileSync` **ohne** `timeout`. Hängt der Kind-Run, stirbt der Elterntest an seinem 300-s-`setTimeout`, **der Kind-Prozess läuft weiter** und schreibt für den Rest der Suite in die Datenbank. **Gut:** `workers: 1`, `fullyParallel: false`, `retries: 0`, `timeout: 60000`, **kein Port**, `maxFailures` und `retries` des Elternlaufs **durchdringen nicht**. |
| **22** | **[x] VERIFIZIERT 2026-09-29 — korrekt offen gelassen (users +15, Rest 0→0; admin-venue +1/+1 → Follow-up 25).** Ledger-Hook vorhanden, aber leer — fünf Specs verlassen sich weiter auf den seriellen Sweep** (medium, 2026-09-28) | S1 | `admin-mandant-switch`, `admin-category`, `admin-event`, `admin-venue`, `approvals` erzeugen UI-Zeilen und löschen sie **über die UI selbst**. **Ein Mittelfehlschlag lässt sie dem Sweep** — genau die F1-Form, die das Ledger beenden sollte. Die Grenze ist ehrlich benannt: die ID steht in keiner Create-Antwort. **Behebelbar** über einen Lookup nach exaktem, worker-eindeutigem Namen — was einen Parameter braucht, und der setzt die TS-Parser-Frage aus Position 23 voraus. |
| **23** | **[x] VERIFIZIERT 2026-09-29 — fixed (Kind-Env-Pin, Mutation rot).** Der verschachtelte Lauf erbt die ganze `process.env` und damit die Messschalter selbst** (medium, 2026-09-28) | S1 | `ownership.spec.ts:90` reicht `{ ...process.env, CI: '' }` durch — der Kind-Lauf braucht nur `E2E_BASE_URL`. **Strukturell** erreicht `E2E_OWNERSHIP=off` auch das Kind, und dessen Teardown wäre neutriert, wodurch Assertion (3) des Treibers fiele. **Heute nur maskiert**, weil `e2e-per-spec-leaks.mjs:110` den Treiber per `--grep-invert` ausschliesst. **Die Gültigkeit eines Tests hängt damit an einem Schalter, den der Treiber nicht kontrolliert.** |
| **25** | **[x] VERIFIZIERT 2026-09-29 — Docblock + zurückgenommene Negativprobe (Diff auf namespace-isolation leer).** M1: admin-venue-Entscheidung steht nirgends im Repo (medium, 2026-09-29) | S1 | Verifikatorbefund zum Implementierer-Diff: `admin-venue.spec.ts:17-21` trägt leere Ledger-Hooks, UI-erzeugtes Team+Venue (+1/+1 je Lauf, gemessen 2026-09-29) ist reclaimbar aber unregistriert — korrekt nicht gemacht (Auftragserweiterung über das damalige Band 17–24 hinaus; 18–20 und 24 sind seither per §4-Schnitt entfernt), aber nirgends dokumentiert. Fix: ~5-Zeilen-Docblock; `admin-venue.spec.ts` in `UI_CREATE_SITES` aufzunehmen machte das Gate korrekt rot. Natürlicher nächster Kandidat für die Registrierung im **Namens-Lookup**, mit dem `admin-mandant.spec.ts` inzwischen räumt. |
| **38** | **`ownership.spec.ts` legt sein Team ohne `teams_enabled`-Vorbedingung an — auf einer frischen DB ist der Test rot, bis ein fremder Spec sie eingeschaltet hat** (medium, 2026-09-29) | S1 | **Die einzige Kette aus den vier Befunden der Welle C, deren Ursache **außerhalb** des Codes liegt, den §4 nicht schneidet — der Seeder. Deshalb bleibt sie als Position, statt in einen Docblock zu wandern.** Drei Glieder, alle am Code geprüft: `backend/database/seeders/DatabaseSeeder.php:59` und `:81` legen **beide** Mandanten mit `teams_enabled => false` an (`firstOrCreate` schaltet nichts nach) · `TeamController::assertTeamsEnabled()` (`:284-288`) beantwortet **jedes** `POST /api/admin/mandants/{id}/teams` mit **422** · `ownership.spec.ts:481-574` („a row the teardown CANNOT delete takes the run down with it") ruft genau diese Route (`:524`), **ohne vorher zu prüfen** — anders als `admin-data.ts:422`/`:1146`, die in `ensurePrimaryMandantHasTeam()` vorher auf `teams_enabled: true` setzen. **Es geht nicht still falsch, sondern rot:** ohne Team greift der 409 nicht, `reclaimOwnedRows()` löscht die Venue, und `expect(refused).not.toBeNull()` (`:547`) fällt. **Warum es trotzdem grün aussieht — und das ist der Befund:** `global-setup.ts` schaltet **nichts** frei, es räumt nur das Logo; die Reihenfolge trägt. `admin-mandant.spec.ts:41` ruft den Team-Ensure auf und läuft in der vollen Suite vor `ownership.spec.ts`, also hinterlässt es das Flag. **Der Test ist damit grün aus geerbter Reihenfolge, nicht aus Konstruktion** — dieselbe Fehlerform wie der `withCookie()`-Befund (Position 13) und der Ledger-Befund dieser Session: grün, weil ein Zustand nebenbei gesetzt war. **Belegte Exposition, ohne Lauf abgeleitet:** `ownership.spec.ts:68` trägt `@regression` + `@feature:e2e-hygiene`, `admin-mandant.spec.ts:41` trägt `@smoke` + `@feature:admin:mandant` — `npx playwright test --grep @feature:e2e-hygiene` zieht den Schalter also **nicht**, und das ist auf einer frischen DB genau der rote Aufruf. **Fixrichtung:** die Vorbedingung an die **eigene** Stelle des Specs, mit demselben `PUT`-Schalter, den `admin-data.ts` schon hat — als **Helper**, nicht kopiert (zwei Kopien sind zwei Wahrheiten). **Nicht** in `global-setup.ts`: der ist ausdrücklich *best-effort* und schluckt Fehler (`:45-47`), und ein dort verschluckter Fehler macht die 422 stumm — das wäre die schlechtere Form. **Gefahr beim Umdrehen:** das Flag global zu setzen würde Teams **dauerhaft** aktivieren und damit `features/02-domain-model.md` (Opt-in pro Mandant) in einem Test-Shadow aufweichen; deshalb ist der Spec-Helfer die richtige Form und nicht die bequemere. |
| **40** | **Alle vier owner-scoped Antrags-/Unterantrags-Registrierungen im E2E-Ledger sind tot — `applications` und `subApplications` werden suite-weit nie zurückgegeben** (medium, 2026-09-29) | S2 | **Gefunden beim Refutieren von F2, nicht beim Beheben.** Die Bedingung liest ein Feld, das die Resource nicht liefert: `admin-data.ts:763` `if (own.accreditation_id === accreditationId)`, und `ApplicationResource.php:22-29` gibt `id, accreditation, status, priority, reason, created_at` zurück — die Id liegt **verschachtelt** unter `own.accreditation.id`. `undefined === 52` ⇒ `false` ⇒ `rememberOwnedByUser('applications', …)` (`:764`) **läuft nie**. Dasselbe an `:848`, `:924` und `:954` (`own.sub_accreditation_id`, `SubApplicationResource.php:24-32`). **Gemessen** (Wegwerf-Probe, danach gelöscht): `own.accreditation_id=undefined`, `own.accreditation?.id=52`, `(own.accreditation?.id === accreditation.id) => true`, **Ledger `applications=0`**; `account-deletion.spec.ts` **4 passed**. **Folge für den Besitznachweis (D23):** der einzige **lebende** owner-scoped Eintrag ist `userMedia` (`admin-data.ts:837`), weil er die Id aus der Create-Antwort liest. **Und die Begründung des ersten Commits ist damit widerlegt:** „Reste in der DB gemessen: 0" stimmt, aber aus dem **falschen Grund** — die Anträge werden nicht über das Ledger zurückgegeben, sie **reiten auf der `users`-Kaskade**, weil jeder betroffene Helper auch `rememberOwnedUserAccount` aufruft. Gemessen nach allen Proben: `users=1 (e2e=0), applications=0, sub_applications=0, user_media=0`. **Damit sind zwei Quell-Kommentare falsch** — `admin-data.ts:917-920` („the registration is real work, not a placeholder") und `ownership.ts:129-133` („accreditations below is not optional bookkeeping") behaupten Arbeit, die nicht stattfindet: die §3-Formel. **Der naheliegende Fix ist eine Falle, gemessen:** Registrierungen wiederherzählen legt den 422-auf-entschieden-Problemzweig frei (`ApplicationController.php:68`, `SubApplicationController.php:60`, *„Only pending (requested) applications can be withdrawn."*) und macht `approvals.spec.ts`, `wallet.spec.ts` und `badge.spec.ts` **rot**. Reihenfolge zwingend: erst der Helper-Fix (401 bei weggefallenem Konto ⇒ reclaimable, jeder andere Status wirft), dann die Klassifizierung der entschiedenen Zeile, dann die vier Registrierungen. **Umgekehrt ist die Reihenfolge der eine Zug, der drei Specs rot macht.** |
| **41** | **`BadgeTest` hat keinen **beabsichtigten** Wächter auf den Content-Stream einer Ausweiskarte — nur einen Zufallstreffer** (medium, 2026-09-29) | S2 | **Gemeldet vom Implementierer von H-1, der ausdrücklich nicht angefasst hat — und er nennt auch den Grund, warum er es nicht konnte.** Mit restauriertem `rtrim()`-Extraktor fällt die **erste** Assertion in `BadgeTest.php:512` — `assertStringContainsString('Jane Doe', $text)`, also eine **Feldtext**-Assertion; PHPUnit bricht dort ab, die Draw-Count-Assertion `:521` wird **nie erreicht**. (`$text` ist Länge 0, der Draw-Zähler liefert 0 statt 2 — `:521` *würde* ebenfalls fallen, ist aber nicht die auslösende.) **Diese Zeile stand erst mit der gegenteiligen Behauptung hier** („der Draw-Count, nicht etwa eine Feldtext-Assertion") und ist am Verifikator-Befund korrigiert: ich hatte die zweite Assertion zitiert, weil sie die thematisch passendere ist. **Der Zufallstreffer ist damit ein Feldtext-Treffer** — und er hält nur, weil das letzte Byte des echten Content-Streams `0x0d` ist, und das ist (L-3, an einem echten Export gemessen) das Low-Byte des **Adler-32-Trailers**: nachgerechnet, Trailer `0x1B8E5B0D` = neu berechnetes Adler-32. Eine Prüfsumme, kein Inhalt. **Also nicht: das PDF ist grün falsch, sondern der Wächter hängt an einer Fixture-Kombination aus GD-Build und Portrait-Bytes.** Das ist genau die `/I<n>`-Form, die `features/badges-qr.md:355-373` bereits als **nicht nagelbar** dokumentiert: dompdfs Alpha-Regel (`Cpdf.php:6255`) zerlegt ein Paletten-PNG in Maske+Bild, außer der Bit-Tiefe ist exakt 4, und den wählt der GD-Quantisierer — CI: 4, hier: 1. **Der Unterschied zu Position 10 ist genau der, den H-1 aufgedeckt hat:** dort stand ein Wächter, der **nicht wehren konnte**; hier steht **gar keiner**, und das ist ehrlicher, aber nicht besser. **Fixrichtung ist offen und wird hier nicht erfunden** — entweder ein Wächter, der gegen die *Anzahl der Zeichnungen in der SMask-Trennung* statt gegen einen `/I<n>`-Zähler prüft, oder ein ausdrücklicher Verzicht mit der Begründung im Docblock. **Was die Position trägt, ist die Lücke, nicht die Lösung.** |
| **42** | **Die gemessene Rest-Exposition der Hermetizität lebt nur in einem Kommentar in `phpunit.xml` — sie gehört aufs Board** (medium, 2026-09-29) | S2 | **Der Verifikator hat die Eskalation angemahnt, und zu Recht: §4 verlangt offene Punkte im Board oder dauerhaft in `features/` — nicht 20 Zeilen über der Zeile, die sie beschreibt, wo der Leser von Position 39 nicht hinschaut.** Die Eskalation („eine Entscheidung, die dem Build-Agenten gehört") hat das Board nie erreicht. **Gemessen:** `phpunit.xml` pinnt **21** Werte, in `config/` sind **146 von 167** `env()`-Keys ungepinnt; **24 plausible live** gesetzt ⇒ **26 Fehlschläge** über **9** Keys: `TRUSTED_HOSTS` 5 · `APP_PREVIOUS_KEYS` 5 · `REQUIRE_ORIGIN_HEADER` 3 · `TRUSTED_PROXIES` 3 · `JWT_CROSS_SITE_COOKIE` 3 · `SESSION_SECURE_COOKIE` 2 · `SESSION_ENCRYPT` 2 · `MEDIA_ROOT` 2 · `MANDANTS_FALLBACK_MANDANT` 1 · `WALLET_PASS_TYPE_ID` 1 (der letzte behauptet den **ausgelieferten Default** `pass.accriditation.test` gegen die **aufgelöste** Config — dieselbe Anti-Pattern-Form wie der Badge-QR-Zähler). **Der Verifikator hat 6 der 26 selbst nachgemessen** (3 × `TRUSTED_HOSTS`, 3 × `REQUIRE_ORIGIN_HEADER`); die übrigen 20 sind die Messung des Implementierers. **Ungepflegt geblieben und mitentschieden:** `phpunit.xml` zeigt weiter auf `127.0.0.1:1025`, wo nichts lauscht — der nächste Test ohne `Mail::fake()` baut die Abhängigkeit neu auf. Strukturell wäre `MAIL_MAILER=array`, das berührt `MandantMailerTest` und ist **Design, nicht Reparatur**. **Nicht** der Zweck, alle 146 zu pinnen — der Zweck ist, die gemessene Zahl **nicht** an eine Config-Datei zu delegieren. |
| **43** | **`/konto` (Selbstbedienung Konto-Löschung) steht außerhalb des Design-QA-Loops — Manifest-Eintragung ist eine offene Nutzerentscheidung, die beim §4-Schnitt von Position 10 mitverschwunden wäre** (medium, 2026-09-30) | S1 | **Wiedereröffnet, weil Position 10 sie beim §4-Schnitt mitgenommen hätte.** Die Seite hat **keinen** Eintrag in `tests/screenshots/ui-review.config.ts` — sie liegt damit außerhalb von §7 Schritt 1 und damit außerhalb des Fix-Loops (Schritt 4). **NUTZERENTSCHEIDUNG 2026-09-29: „Später, nach Position 5"** — und **Position 5 ist weiter offen**. **Warum die Verzögerung nicht kosmetisch ist:** §7 Schritt 4 verlangt ausdrücklich, dass der `vision`-Subagent **old vs new** vergleicht. Nach einem gezielten Re-Capture (`-g <route>`) gibt es kein „old" mehr — **auch nicht für die gerade neu aufgenommene Route**, weil ein partieller Lauf alle anderen Captures mitnimmt (Position 5, gemessen 95 → 4 PNG). **Eintragen, bevor 5 behoben ist, hieße also: zwei weitere Routen ohne Vergleichsbild.** Und die Bandzahl stiege um 4 (filled/empty × desktop/mobile), was die in §7 geforderte Abnahme „drei Läufe ergeben dieselbe Zahl" **an einer weiteren Route** messbar macht. **Der Verlust war real:** die Entscheidung stand ausschließlich in der Zeile von Position 10, ist weder in `features/` noch in `frontend/` noch in `features/05-e2e-test-image.md` aufgetaucht, und der §4-Sweep hat sie benannt, statt sie stillschweigend zu übernehmen — genau das ist der Grund, warum §4 einen Agenten verlangt und nicht den Build-Agenten. **Reihenfolge ist hier die Position, nicht der Aufwand.** |
| **44** | **§7 Auflage 3 hat ihren ersten Anwendungsfall verloren, und die Regel steht trotzdem unverändert da — wer prüft den Präzedenzfall?** (medium, 2026-10-01) | S1 | **Als Folge des §4-Schnitts von 13 aufgenommen, und aus demselben Grund wie 43:** die Zeile, die diese Entscheidung trug, ist entfernt — mit ihr der einzige Ort, an dem stand, dass die Regel je gebrochen wurde. **Was passiert ist, wörtlich aus dem Nachtrag der Zeile 13 (2026-10-01):** §7 Auflage 3 verlangt Rückgabe nach **zwei** Prosa-Runden; in Position 13 waren Runde 4 und Runde 5 genau zwei Prosa-Runden — **der Nutzer hat übersteuert** („gerne jetzt mit dem Standard Modell weiter arbeiten") und die Schleife lief weiter. **Die Regel hat damit ihren ersten — und bis heute einzigen — Anwendungsfall verloren und steht unverändert in `AGENTS.md:345-349`.** Das ist die Fehlerform, die dieses Board trägt: **ein Vertrag, den niemand prüft** (§3) — hier schärfer, weil der Bruch *entschieden* war und die Regel danach einfach weiterging, als wäre nie etwas gewesen. **Nicht die Abschaffung ist die Frage, die Frage ist, was die Regel mit einem Nutzer-Override tun soll** — und die gehört dem Nutzer, nicht dem Build-Agenten (§6: offene Klärungsfragen interaktiv, nicht im Board parken). **Drei Antworten, bewusst nicht entschieden:** (a) **unverändert** — die Auflagen bleiben, der Bruch war ein einmaliger Sonderfall; (b) **Präzisierung** — Auflage 3 braucht einen Satz darüber, was ein Override bedeutet (einmalig? Begründung in der Commit-Message des Fix-Laufs?); (c) **Abschaffung** — die Auflage entfällt, weil ihre einzige Anwendung abgelehnt wurde. **Belegt, nicht behauptet:** die Formulierung „wird diese Position geschlossen, gehört Auflage 3 auf die Probe" stand **ausschliesslich** in Zeile 13 — `grep` nach `Auflage 3`/`Prosa` findet in `features/` **null** Treffer, in `AGENTS.md` nur `:345`, `:347`, `:351`, `:355` (die Regel selbst und ihre Begründung, **ohne** den Bruch). **Was die Regel misst, steht in `AGENTS.md:351-357` und ist gemessen:** eine Grenze, die an `CHANGES REQUIRED` hängt, misst den Zustand des Verfahrens, nicht den des Codes — fünf Runden, kein einziger Verhaltensfehler. **Das ist kein Argument gegen sie, sondern ihr Ertrag:** sie hat Position 13 beendet, statt sie fortzuschreiben. **Offen ist ausschliesslich der Präzedenzfall** — und nicht, ob die Position 13 geschlossen werden durfte: sie durfte, und der Schnitt hat sie korrekt geschlossen. |
### Nicht in diesem Batch
- **Go-Live** (~20 Positionen: Pre-Prod-Domain, DNS, Caddy, Secrets, Deploy, Backup,
  Prod-Smoke) — liegt per Anweisung unten.
- **`P5-F4` Queue-Integration** — braucht Queue-Worker, ist Go-Live-Infrastruktur.

---

## 🧭 F5 hat seine Bedeutung gedreht — ein mandantenübergreifendes Konto ist **nicht erreichbar**

**Am 2026-09-29 gemessen, nicht geglaubt (§3).** Ich hatte den Verifikator gefragt, was mit einem
Konto passieren soll, das in zwei Mandanten Rollen hält — und die Antwort war „frag das Produkt",
nicht „frag die Nutzer". Der Code sagt: **das Produkt lässt das nicht zu.**

**Es gibt genau drei Stellen im ganzen Backend, die eine `role_user`-Zeile schreiben** — und in
allen dreien ist `role_user.mandant_id` konsistent mit dem Home-Mandant des Kontos:

| Stelle | `users.mandant_id` | `role_user.mandant_id` |
|---|---|---|
| `AuthController.php:120` (Registrierung) | current | `$mandant->id` — **derselbe** |
| `Admin/UserController.php:159` (`updateRoles`) | current | `$mandantId` (current) — **erst nach** `abort_unless(…forMandant($mandantId)->exists(), 404)` |
| `DatabaseSeeder.php:49` (Bootstrap) | `null` (global) | `null` |

Das Henne-Ei ist der Kern: `updateRoles()` verlangt die Mitgliedschaft im Zielmandanten, **bevor**
es dort schreibt. Ein Nutzer kann sich also über die API **nie eine erste Zuweisung in einem
zweiten Mandanten** holen. `users.mandant_id` ist der dokumentierte *„owning ('home') mandant —
the uniqueness anchor"* (`0001_01_01_000000_create_users_table.php:17-19`) mit
`unique(['mandant_id','email'])`; NULL markiert ein **globales** Konto (`:53-56`) — also genau
das Bootstrap-`super_admin`. **Mandant = Domain, ein Konto pro Domain, gleiche Mailadresse je
Domain erlaubt** — die Formulierung des Nutzers, am Code belegt.

**Was daran trotzdem offen bleibt, und warum es kein Produktentscheid ist:** der Verifikator hat
den Zustand für seine Messung per direktem Eloquent-Write **selbst gebaut**. Damit steht F5 nicht
als „Produktverhalten, das entschieden werden muss", sondern als **Invariante, die zu nageln ist**:
alle drei Pfade erhalten `role_user.mandant_id == users.mandant_id`, und **F1s Wächter** ist die
Stelle, an der ein hypothetischer Verstoß auffliegt — als defense in depth, nicht als
Angriffsfläche. Die dauerhafte Fassung (drei Schreibstellen + Invariante) wandert nach
`features/auth/01-auth-and-roles.md`; §4 schneidet diese Datei, nicht `features/`.

**Und das ist der eigentliche Ertrag:** die Verifikator-Einstufung „medium, unentschiedene
Kreuz-Mandanten-Wirkung" hätte eine **Scheinfrage** an den Nutzer erzeugt. Erst der Diff gegen den
Code hat sie als Scheinfrage entlarvt.

---

## 🔬 Umgebungsmessungen 2026-09-29 — drei Fakten, die lokal verifizieren **unmöglich** machen

**Hier steht das, weil ein §4-Durchgang diese Datei auf offene Punkte zuschneidet und eine
Notiz nur hier bei jedem Durchgang still verschwindet** — dieselbe Fehlerform wie A7. Die
CI-Jobs laufen grün; das hier ist **kein** Code-Defekt und **kein** Verifikationsersatz.

**1. `php8.5-gd` fehlte — 244 Tests konnten lokal gar nicht laufen.** `php artisan test` meldete
`1358 passed / 248 failed`, was wie ein Verwaltungschaos aussah und **keines war**: die Fasten
sind 248 Umgebungsfehler einer fehlenden PHP-Extension. Nach `sudo apt-get install php8.5-gd`
(JPEG + PNG bestätigt): **`1629 passed`, 3 failed, 1 skipped**. **Wer hier künftig eine
kleine Suite fährt und `248 failed` sieht, prüfe `php -m | grep gd`, bevor er es als Befund
behandelt** — §4 verbietet das Etikett „pre-existing", und die Diagnose, es sei ein Code-
Problem, wäre falsch gewesen.

**2. ~~Mailpit ist aus dieser Sandbox nicht erreichbar~~ — FALSCH, am 2026-09-29 korrigiert.** Ich habe
gemessen: `127.0.0.1:1025`/`8025` → `Connection refused`, Container-IP `172.17.0.3` → `Connection
timed out`, `--network host` wirkungslos — und daraus **„strukturell unerreichbar"** geschlossen und
als dauerhafte Umgebungsgrenze festgehalten. **Das war ein Namensproblem, kein Netzproblem.**
`getent hosts dind` → `172.24.0.2`, `dind:8025` **OFFEN**, ebenso `dind:1025` und `dind:5432`. Die
Ports des Compose-Stacks landen auf dem **`dind`-Container**, nicht auf `localhost`: der
`--env-file deployment/dev.env`-Weg bindet sie dorthin, und die Sandbox sieht `localhost` nur als
anderen Namespace. **Konsequenz, die mich trifft:** die **zwei** Mail-Fehlschläge der Suite waren
**nie** umgebungsbedingt — sie waren über `MAIL_HOST=dind` / `MAILPIT_API_URL=http://dind:8025/api/v1`
lösbar, und ich habe sie als „bekannte Umgebungsgrenze" verbucht. **Ein Befund, den man nicht
reproduzieren kann, ist nicht minderwertig — man muss nur zugeben, dass man ihn nicht kann.** Die
Messung, die ich brauchte, stand im Bericht des **Backend**-Agenten derselben Sitzung
(`DB_HOST=dind`), ich habe sie nur nicht mit Mailpit verbunden. **`docker compose` fehlte** ebenfalls
(Plugin nachinstalliert); `ps`/`pkill` fehlen auf diesem Host, Prozesse über `/proc/*/cmdline`.

**3. Ein Fehler, der **weder** Mailpit noch GD ist — und der bleibt.** `BadgeTest > export pdf
contains template field text and photo` scheitert an einer dompdf-Content-Stream-Assertion
(`/I1 Do` fehlt). **Er fällt am Eltern-Commit `adf5a9f` identisch** — also nicht durch Welle C
verursacht —, und er ist **keiner** der drei erwarteten Mail-Fehlschläge. §4 verlangt Fix **oder**
Dokumentation als akzeptiertes Risiko in `features/`, **nicht** das Etikett „pre-existing".
**Ursache ungeklärt** — GD war zum Messzeitpunkt bereits installiert, das schließt die
einfachste Erklärung aus, ohne die dompdf-Erklärung zu bestätigen.

**Und die Zahl, die daraus folgt — sie widerlegt die Überschrift dieses Abschnitts.** Gemessen am
2026-09-29, vollständig, ohne Override des Codes:

```
APP_URL=https://accreditation.test   MAIL_HOST=dind   →   1651 passed, 0 failed, 1 skipped
APP_URL=https://accreditation.test   (ohne)          →   1649 passed, 2 failed   ← beide Mail
APP_URL=                             MAIL_HOST=dind   →   1132 passed, 519 failed ← nicht der Mail
APP_URL=http://localhost:5173        MAIL_HOST=dind   →   1131 passed, 520 failed
```

**Die Suite ist also nicht grün „trotz einer Umgebungsgrenze" — sie ist mit zwei korrigierten
Werten vollständig grün, und das war nie gemessen.** Die „2 Mail-Fehlschläge" waren derselbe
Namensfehler (58/58 grün mit `MAIL_HOST=dind`, gefiltert **und** in der vollen Suite). Der dritte,
`BadgeTest`/dompdf, war ein **echter** Defekt und ist in `21d4878` behoben. **Die Zahlen oben sind
eine Momentaufnahme vom 2026-09-29 vor der Konto-Löschung** — die Suite ist seither durch die
Position-10-Tests gewachsen; der heutige Stand steht in der Kopfzeile (**1669 / 1668**), nicht hier. **Die Überschrift
„Umgebungsmessungen" ist damit selbst irreführend** — sie steht noch, weil sie drei Befunde
trägt, von denen zwei keine Umgebungsbefunde waren; wer sie liest, hält 519 Fehlschläge für ein
Sandbox-Problem. **Position 39 ist die Konsequenz**, und sie ist ernster als der Mail-Fehler:
`APP_URL` muss **exakt** der `.env.example`-Wert sein, und **beide** naheliegenden Abweichungen
(`http://…` für ein Dev-Server-Setup, leer als „unbekannt") kosten **je ~520 Tests** — einmal über
`VerifyLink.php:25`, einmal über `EnsureSameOrigin`. **Ein Verifikator, der diese Suite fährt und
`APP_URL` nicht prüft, fährt entweder 519 grüne oder 519 rote Tests und nennt beides „die Suite".**

---

## 🎯 Entscheidungen (interaktiv geklärt 2026-08-13)

| # | Thema | Entscheidung |
|---|---|---|
| D1 | Stack | Laravel 13 + PHP 8.5 · React 19 + Vite + TS strict + Tailwind v4 + daisyUI v5 · neueste Deps aus Portal |
| D2 | DB | Postgres (Dev/Prod, docker-compose) + SQLite `:memory:` (Tests) |
| D3 | Multi-Tenant | „Mandant" = Brand-Muster aus Portal (Host-Header → MandantContext); eigene Domain pro Mandant; Hauptseite ist selbst ein Mandant; kein Theme, nur Logo/Header-Bilder + Legal-Texte |
| D4 | Hierarchie | Mandant = Verband · Team = Verein (optional, pro Mandant freischaltbar) · Kategorien erben vom Mandant, Team überschreibt |
| D5 | Anmelde-Scopes | Event/Spiel · Liga-weit · Saison (Verein) · Pro-Spiel |
| D6 | Account | Pro Mandant eigenes Konto (eigene Domain) |
| D7 | Rollen | super_admin, mandant_admin, team_admin, user, verifier (Ordner); Team-Admin sieht Verbands-Akkreditierungen eigener Personen read-only |
| D8 | Freigabe | Manuell + Automatismen (nach Fristende FCFS, nicht-gesperrt); immer Limit; Massenfreigabe (alle / erste X); Blacklist (Person + Domäne); VIP-Prio (Person/Domäne) |
| D9 | Sub-Akkreditierungen | Park-/Sitzkarte nur bei Haupt-Akkreditierung; eigenes Kontingent + auto/manuell; Überzeichnung → Ablehnung; VIP vorgereiht |
| D10 | Event | Titel, Datum, Ort (Default = Heimstätte Team, überschreibbar), Wettbewerb, Frist (Default, überschreibbar); Events auf Mandant- oder Team-Ebene (mit Teams nur Team-Ebene) |
| D11 | Ausweis | Feld-Editor (Pentaho-artig, „Luxus wenn möglich") + PDF-Export + CSV/Excel für Serienbrief |
| D12 | QR | Scan → öffentliche Prüfseite (Foto/Status); Ordner webbasiert online |
| D13 | Wallets | Apple + Google Wallet (PKPASS) |
| D14 | E-Mail | Voller Workflow (Aktivierung, Freigabe/Ablehnung, Frist-Reminder, Pass-Versand); SMTP je Mandant |
| D15 | Sprachen | DE + EN (Lingui) |
| D16 | Fotos | Porträt + Presse-ID + Anhänge (validiert) |
| D17 | Migrationen | Mit **Erstelldatum** nummerieren (Laravel-Format); bis zum 1. Prod-Deploy erweitern/frei anpassen, danach jede Änderung eigene Migration — in `backend/AGENTS.md` dokumentiert |
| D18 | Deployment/Proxy | **Caddy NUR remote** (Plan offen): serviert Frontend + `/api`-Proxy zum Backend auf einer Domain (React-Proxy-Muster wie Portal), Mandanten-Routing über Host-Header. **Lokal:** Herd-Backend `https://accreditation.test` + Vite-Dev-Server (Frontend, eigener Port, Proxy auf Backend) — **kein Caddy lokal**. |
| D19 | Badge-Editor-Interaktion (2026-09-27) | **Raster + konfigurierbare Labels** — kein Maus-Drag, keine Eckgriffe, keine magnetischen Guides. Raster bleibt **5 mm** (ohne Toggle). Der **Pfeiltasten-Nudge kommt zurück**: er ist keine Zieh-Interaktion, Tastaturbedienung war nie ausgeschlossen, und ohne ihn ist relative Feinverstellung („1 mm nach links“ bei 27,4 mm) unmöglich. Konfiguration läuft über `BadgePropertiesPanel`. |
| D20 | Venue-Schreibbreite `team_admin` (2026-09-27) | **Lesen und Anlegen mandantweit, Ändern nur eigene Orte.** Lesen/Anlegen muss mandantweit bleiben, weil der bestätigte Inline-Create im Team-Formular sonst dead-endet und `venues.manage` zeilengleich `categories.manage` folgt. **Ändern** (Umbenennen, Deaktivieren, Löschen) wird getrennt und folgt dem Kategorie-Muster: ein Verein darf den Ort eines Nachbarvereins nicht umbenennen, ohne ihn zu fragen. Gilt für **beide** Flächen (host-skaliert **und** mandant-adressiert). |
| D21 | Multi-Domain-Admin-UX (2026-09-27) | **Host-relativ bleibt der Default, plus Dropdown zum Domainwechsel** für `super_admin`. Ausdrücklich **keine** Mandant-Parametrisierung aller Admin-Routen (die wäre die große Variante und ist nicht beschlossen). Der stille Cross-Mandant-Write ist mit `a2c8e5f` unabhängig davon behoben. |
| D22 | Badge-Hintergrund (2026-09-27) | **OFFEN.** `background-color:#ffffff` auf `body`/`@page` wäre technisch der robustere Fix — dann entfiele die Nachbearbeitung komplett. Nimmt den Ausweisen aber den **transparenten** Hintergrund, der für Ausweisspiele auf Folie, Glas und im Siebdruck relevant ist. Produktfrage, nicht technische; die Alpha-Entfernung ist bis dahin Sache des Skripts `scripts/pdf-to-png-vision.sh`. |
| D23 | **E2E-Fixture-Besitz** (2026-09-28) | **Jeder E2E-Test registriert, was er angelegt hat, und löscht es selbst — auch wenn er halb scheitert.** Drei Fixtures erstellt, das vierte wirft: die drei müssen weg. **Kein Test hinterlässt eine `E2E %`-Zeile.** Ein Sweep über Namensmarker ist **Übergang**, nie Modell. Referenzimplementierung: `portal.reisinger.pictures`. |
| D25 | **Entscheidungen nie in `AGENTS.md`** (2026-09-29) | Agenten-Entscheidungen (D-Reihe) gehören **ausschließlich** in `AGENTS.todo.md`. `AGENTS.md` bleibt frei von Entscheidungs-Einträgen — die D24-Zeile in §5 wurde noch am selben Tag zurückgenommen. **Gilt nur künftig:** ältere Einträge (A-Register, D-Verweise in §7) bleiben stehen (entschieden 2026-09-29). |
| D26 | **Keine Prozesslecks** (2026-09-29) | Nutzerentscheid **2026-09-29**, wörtlich: **„no process leaks please."** (damals als Board-Position 32(b) geführt — die Position ist mit Welle C per §4-Durchgang 2026-09-29 entfernt, diese Entscheidung lebt als **D26** weiter). Ein verwaistes Kind muss **wirklich verschwinden**, nicht bloss nicht-ausführbar sein — also **strikte Lesart** statt Test-Toleranz. **Was gebaut wurde (`2e9ea93`):** `options: --init` am **e2e-Job-Container** in `ci.yml` — Docker-Daemon-eigenes `docker-init` (tini) als PID 1. **Bewusst KEIN `ENTRYPOINT` im Image:** bei einem Job-Container bestimmt der *Runner* PID 1, nicht das Image; ein `ENTRYPOINT`, den der Runner ersetzt, wäre eine Zeile ohne Aussage über das laufende System. **Stand der Erkenntnis — zwei Hälften, und das ist nicht dasselbe:** (a) **outcome-proven** — `docker create --init` → `PID1 comm=docker-init`, Waisenkind **GONE**; ohne → `state=Z` (gemessen, Docker 29.8.1); (b) **mechanism-declared** — dass GitHub `options:` für einen Job-Container auswertet, steht in der Syntax-Referenz (nur `--network`/`--entrypoint` ausgeschlossen), im Runner-Quellcode **nicht** nachlesbar. **Nur ein echter CI-Lauf schliesst (b).** Die bestehenden STAT-Prädikate (`isExecuting`, `isZombieState`) **bleiben**: ein `Z` ist jetzt die **Abwesenheitskontrolle** für den Reaper, nicht sein Ersatz. **Nicht zu verwechseln** mit „Toleranz abbauen": die Entscheidung **verschärft** die Zusage. Begründung: §7 „Fixture-Besitz" und die ganze Board-Kette lehren, dass ein grüner Lauf, der geleckt hat, eine Lüge ist — dasselbe gilt für Prozesse. |
| D27 | **Bekanntes Rot: `child-lifetime.spec.ts` ist ohne Reaper absichtlich rot** (medium, 2026-09-29) | Aus D26 folgt eine **dauerhaft rote** Teststelle auf jedem Host ohne PID-1-Reaper — u. a. in **jedem** Entwickler-Container, in diesem Sandbox likewise (gemessen: PID 1 = `opencode`, Waisenkind `Z`, `ppid=1`). **Das ist fail-closed und damit richtig** (§3: ein rotes Gate ist besser als eine grüne Lüge), **aber es muss bekannt sein** — §3/§4 verlangen, dass eine wissentlich rote Standard-Suite-Stelle als akzeptiertes Risiko festgehalten wird, sonst begegnet sie dem Nächsten unvorhergesagt. **Zweite, unbequemere Folge:** der **CI-Push-Gate** ruht jetzt vollständig auf der **unbewiesenen** Hälfte (b) von D26 — wäre `--init` dort inert, geht der **`e2e`-Job** bei jedem Push rot (nicht alle vier Gates; die anderen drei bleiben grün). Auch das die richtige Richtung, aber ein selbst zugefügtes Rot, das nichts vorhersagt. **Dauerhaft festgehalten in `features/05-e2e-test-image.md`** („Reaper als PID 1 — und der daraus folgende bekannte Rote") — §3 verlangt ein wissentlich roter Testbereich als akzeptiertes Risiko in `features/`, und §4 schneidet **diese** Datei weg, eine Notiz nur hier wäre bei jedem §4-Durchgang still verschwunden. **Grenze, die bleibt:** der Test ist grün, sobald **irgendein** Reaper existiert — er beweist damit den **Ausgang**, nicht die **Ursache** (b). Das einzige, was beides verknüpft, ist die Deklarations-Pin im Vitest (`options: --init` in `ci.yml`), mutationsgeprüft. |

### 🗑️ Verworfen (nicht erneut implementieren)

| Ansatz | Warum verworfen | Wodurch ersetzt |
|---|---|---|
| **Globaler Namens-Sweep über `E2E %` als *primäres* Aufräummodell** (2026-09-28) | Erkennt nur, was es **zufällig** wiederfindet. Gemessen: **54 Teams + 53 Venues** blieben liegen, weil `teamNames: ['E2E Heimverein ']` keinen einzigen davon traf. Der `DELETE` **409te**, der Status wurde **nicht** geprüft, und `admin-data.ts:1143-1148` steckte in `try{…}catch{console.warn}` — **genau der F1-Defekt.** Nebenwirkung, die schwerer wog als der Müll: die Bandzahl des UI-Reviews zählte **fremde Daten** (`36 → 48 → 51 → 52`). | D23 — Besitz-basiert, jeder Test räumt **seine** Fixtures ab |
| **Marker-Deckung als Test „passt zu *irgendeinem* Marker?"** (2026-09-28) | **Vakuos**, und das ist der bemerkenswerte Teil: `mandantNames: ['E2E ']` passt auf **jeden** E2E-Namen, der Test blieb also **grün mit dem F1-Bug drin**. Er hätte bestanden, ohne dass F1 behoben wäre. | Je **Art** (team/venue/category/…) eine **explizite** Deckung; die Sammel-Marke bleibt nur dort, wo die Spalte zu genau **einer** Swept-Collection gehört |
| **„Warnung, aber nie rot" als Purge-Gate** (2026-09-28, vom Fix-Agenten als Rückfalloption angeboten) | Nimmt genau die Sichtbarkeit zurück, die die Änderung hergestellt hat. Ein Purge, der verschluckt, **ist** der Fehler — er folgt ihm dann wieder. | Jedes `DELETE` prüft den Status; eine fehlgeschlagene Rückräumung lässt den Lauf **scheitern** (Sicherheitsnetz hinter D23) |


---

## 📋 Phasen & offene TODOs

### P7 — Polish + Deploy 🟡 **AUF HALT — Go-Live wartet auf Benutzer-Freigabe**
> **Einziger verbleibender Block:** Alle Umsetzungsphasen P1–P6 + UI-Polish + P7-Hardening sind
> abgeschlossen (verifiziert, APPROVED). P7 wird erst nach expliziter Freigabe des Benutzers umgesetzt.
>
> **Stand 2026-09-27:** Der Halt ist nicht mehr nur „wartet auf Freigabe", sondern eine
> **ausdrückliche Anweisung**: Go-Live liegt, zuerst kommen die Go-Live-freien TODOs aus dem
> Abschnitt „Nächster Batch" oben (**Liste und Zahl in der Kopfzeile** — hier stand früher eine Zahl, die der
> §4-Schnitt überholt hat, ohne dass es jemand nachgezählt hätte; S1–S3, alle delegierbar ohne Server/DNS/Secrets).
> Diese ~20 Positionen hier bleiben unangetastet, bis der Benutzer sie ausdrücklich wieder
> freigibt.
> Operativer Plan: siehe §🚀 Go-Live-Plan (unten). Caddy-SOLL: `features/03-caddy-brand-files.md`.

- [ ] Go-Live gemäß §🚀 Go-Live-Plan (Pre-Prod → Prod → Long-running)

---

## 🚀 Go-Live-Plan (2026-08-25)

> Zielbild: identisches/similar Infrastruktur-Muster wie `portal.reisinger.pictures`
> (globale `~/dev/caddyfile/Caddyfile`, Snippets `security_headers`/`compress`/`spa`,
> FastCGI `/api*` → Backend), erweitert um **per-Mandant austauschbare Brand-Ressourcen**
> (Logo/Favicon/Webmanifest pro Subdomain) via `brand_overrides`-Snippet
> (SOLL: `features/03-caddy-brand-files.md`). Verzeichnis-Keying: `/srv/websites/accreditation.<slug>`.

### Phase A — Pre-Prod-Deploy

**Infrastruktur**
- [ ] Pre-Prod-Subdomain festlegen (z. B. `preprod.accreditation.reisinger.pictures`) + DNS
- [ ] Site-Block im globalen `~/dev/caddyfile/Caddyfile` nach Portal-Muster (`security_headers`, `compress`, `/api*` fastcgi → `accreditation_backend:9000`, `spa`, `brand_overrides`)
- [ ] Server-Voraussetzungen: `/srv/websites/accreditation.<slug>`-Verzeichnisse (Frontend-Dist), Docker-Netzwerk für Caddy ↔ Backend, Volumes für DB + Media (private Disk)
- [ ] `caddy validate` vor Reload (Docker), Deploy-Mechanik wie Referenz (`sync.sh`)

**Umgebung & Config**
- [ ] `.env.preprod`: `APP_KEY`, `JWT_SECRET`, DB-Creds (Postgres-Container), Mail (Mailpit), `APP_ENV=staging`, `APP_URL` + Mandant-Domain-Hosts
- [ ] Frontend-Build für Pre-Prod (Vite `dist`) + Deploy-Pfad

**Verifikation vor Prod (Gates)**
- [x] **Postgres-Portabilitäts-Gate — BESTANDEN 2026-09-27** (war fälschlich offen): alle
  5 Prüfpositionen PASS auf echtem **PostgreSQL 17.10**, komplette PHPUnit-Suite dort grün.
  Geprüft: `LIKE … ESCAPE '\'`, COALESCE-Ausdrucksindex, `NULLS LAST`,
  `smtp_config` (text + `encrypted:json`), E-Mail-Index. **Was der Lauf zusätzlich gefunden
  hat:** die SQLite-`->change()`-Falle bei Ausdrucksindizes (dokumentiert in
  `features/02-domain-model.md`, Commit `ce2dfc6`) — auf macOS prinzipiell unsichtbar, weil
  nur SQLite Ausdrucksspalten beim Table-Rebuild verliert. **Bewusst offen geblieben:**
  E2E-Suite gegen Postgres (Baum war mitten im Venue-Umbau) und `EXPLAIN ANALYZE` (Index-*Form*
  ist verifiziert, nicht die Geschwindigkeit) → Positionen 8/9 im „Nächster Batch".
- [ ] **Brand-Override-Gate:** `brand_overrides` live testen — Mandant A mit eigenem Logo, Mandant B auf React-Fallback; Austausch (Upload Self-Service `POST /api/mandant/logo` → Datei im Dist-Ordner ersetzen/ergänzen) ohne Reload nachvollziehen
- [ ] Full E2E `@regression` grün gegen Pre-Prod (inkl. `@smoke`, Badge-PDF, QR-Verify, PKPASS)

### Phase B — Prod-Deploy

- [ ] Backup/Rollback-Basis: DB-Dump + alte Dist-Ordner vor jedem Deploy
- [ ] Prod-DNS für alle initialen Mandant-Domains + TLS (Caddy ACME automatisch)
- [ ] Site-Blöcke pro Mandant-Domain im globalen Caddyfile (Muster aus Phase A) — `caddy validate` + Reload
- [ ] `.env.production` auf Server (Secrets NUR serverseitig): `APP_KEY`, `JWT_SECRET`, DB, SMTP je Mandant (`smtp_config` JSON), SameSite=None-Cookie (BE-R6 bereits implementiert)
- [ ] `docker compose -f deployment/docker-compose.yml up -d` (Backend + Postgres), `php artisan migrate --force`, Storage-Link, `config:cache route:cache`
- [ ] Frontend-Dist je Mandant deployen (Fallback-Dist + optionale Overrides)
- [ ] Smoke gegen Prod: `@smoke`-E2E + manueller Check Login/Guest/QR/PDF/Wallet
- [ ] Monitoring/Basics: Log-Zugriff, Mail-Zustellung, Rate-Limiter-Verhalten in Prod

### Phase C — Long-running / Post-Go-Live

> Abgeschlossene Punkte entfernt: P3e-B3 (LikeSearch), P3e-B5 (e2e-up.sh), P3b-F2 (domain-model.md), P2b-F5 (domain-model.md), P1c (Profile-E2E), P3e-B4 (indexAll Endpoint), BE-R8 (domain-model.md), Vite-Proxy (Middleware), P2c-F4 (useAdminTeams `2e35df1`), P4-F4 (QR z-order `431ec99`).

---



---

## 🏁 Abschluss Session 2026-09-28

**Verdict: `CHANGES REQUIRED`.** Vier `high`, drei `medium`, vier `low` — Positionen 17–24. Der
Wiederherstellungspunkt des Besitzmodells liegt auf `d63b7b3`, und **der Stand ist bewusst nicht
freigegeben**: eine Abnahme war als nicht erfüllt gemeldet, und der Verifikator hat sie **weder
bestätigt noch entkräftet**, sondern die **Mechanik** bewiesen.

### Der Befund, der die Session zusammenfasst

**Ein Tippfehler im Plan schaltet das Ledger für diese Art dauerhaft ab, und alle Gates bleiben
grün.** Der Verifikator hat **ein Zeichen** getippt (`/api/admin/mandants` → `/api/admin/mandat`)
und gemessen: **+1 zusätzliche Leckzeile pro Lauf**, `admin-mandant.spec.ts` **3 passed**,
`namespace-isolation` **29 passed**, der Ledger-Walk **3 passed**. Ursache ist benannt und war
**Absicht**: die 404-Toleranz für jede Art — eine falsche URL ist von einer bereits zurückgeholten
Zeile nicht unterscheidbar.

Das ist **die F1-Form, verlagert aus dem Teardown in den Plan.** Und es ist dieselbe Form ein
drittes Mal in dieser Sitzung aufgetaucht, in drei verschiedenen Gestalten: `withCookie()` ohne
`withCredentials()` (ein Test grün aus dem falschen Grund), `DELETE` ohne Statusprüfung (ein Purge
ohne Wirkung), jetzt der Plan (ein Ledger ohne Wirkung). **Ein grüner Lauf, der geleckt hat, ist
eine Lüge** — und gegen die schützt kein Messwert, sondern nur eine Postcondition, die aussprechen
kann, was fehlt.

### Und der zweite, der fast unterging

**Der Capture hatte keine Daten-Postcondition.** Gemessene Reserve: **159–220 ms**. Mit einer
künstlichen Verzögerung von 400 ms — **Dataset unverändert** — speichert ein **grüner** Lauf den
**Lade-Spinner** als gültiges Artefakt (`0 Bänder, 950 px`). Der Verifikator hat die Behauptung,
das seien Daten, **widerlegt**: ein fester Datensatz kann keine 20-zeilige Liste verschwinden
lassen. **§7 Abnahme 1 war nicht unstabil, sondern unbewacht** — und die Begründung war nicht
falsch, nur unvollständig: Last ist der **Auslöser**, nicht die **Ursache**.

### Was entschieden und festgeschrieben wurde

- **D23 — E2E-Fixture-Besitz:** jeder Test registriert, was er angelegt hat, und löscht es selbst, auch wenn er halb scheitert. Ein Sweep über Namensmarker ist Übergang, nie Modell.
- **Drei bewusste Abweichungen vom Portal** (`portal.reisinger.pictures`): dessen `deleteResources` verschluckt jeden Fehler in `console.warn` — **das ist F1 in der Vorlage**; dessen `throw` **vor** dem `push` lässt Zeilen unregistriert; und **nichts** prüft dort, dass eine Spec mit Fixtures auch `afterEach` hat — bei uns scannt `namespace-isolation.spec.ts` bereits alle Specs.
- **F1 behoben, gemessen:** 107 Zeilen zurückgeholt (54 Teams, 53 Venues), danach nach jedem vollen E2E-Lauf **0**. Die **Bandzahl fiel 51/52 → 24**, weil sie vorher eine fremde Datenmenge zählte.
- **§7 Fixture-Besitz**, **§5(4)** (ein Verifikator darf uncommittete Arbeit nicht zerstören), **§10 A5/A6/A7**.
- **Die Board-Tabelle war von mir selbst zerschossen** — ich hatte mehrzeiligen Text in Markdown-Tabellenzeilen eingesetzt. Inhaltlich geprüft (59185 Zeichen normalisiert, `Inhalt identisch: True`), aber die Prüfung, die mich darauf aufmerksam machte, suchte zuerst zweimal nach dem **falschen** Muster und meldete „FEHLT", während alles korrekt drinstand.

### Was der Board jetzt nicht mehr enthält — §4-Schnitt

Auf deine Entscheidung hin ist die abgeschlossene Session-Historie entfernt; §4 verlangt **„nur
die offene Punkte (aktueller Plan)"**. Entfallen sind die Abschnitte **Session 2026-08-19**,
**Session 2026-08-19b**, der Inhalt von **„Open Follow-ups (verifiziert, aber offen)"** (leer),
**Workflow (delegieren + verifizieren)** (duziert `AGENTS.md` §5), **Offene Punkte / Risiken**
(alle Punkte `[x]`, der eine offene — Google Wallet — ist Position 14), **Whole-Repo Code Review
2026-08-20**, **Dependency-Update 2026-08-23**, **Session 2026-08-25** und **-08-25b**,
**Full-Repo-Review 2026-08-26**, **PDF-visuelle-Verifikation**, **Session 2026-09-19** und
**Whole-Project Code Review 2026-09-26**. **Alles davon liegt im Git** — Commit-Range
`263290f..<dieser Commit>`, Datei `AGENTS.todo.md`.

**Vor dem Schnitt geprüft, nicht geraten.** Drei Dinge hätten dabei fallen können, und keines ist
gefallen: die **Venue-Schreibbreite für `team_admin`** steht als **D20** (die Session-Notiz sagte
noch „Nicht selbst entscheiden" — veraltet); die **`Mandant`-/`MandantDomain`-Route-Bindung** war
als offener Punkt in keiner Position geführt und steht jetzt als **`AGENTS.md` §10 A7**; der
**§6-Parallelitätsbefund** aus Session 09-19 steht als **§6** in `AGENTS.md`. **Der Schnitt hat
also einen offenen Punkt gerettet** — der wäre sonst still verschwunden, und Position 24(a) ist
genau der Befund, der beschreibt, wie so etwas aussieht, wenn es niemand bemerkt.

### Offen bei Übergabe

**14 Positionen** (5–38; der §4-Schnitt 2026-09-29 hat 8 geschlossene Zeilen aus 17–29 entfernt, der zweite
Durchgang am selben Tag die drei ausserhalb des Bandes liegenden 11/15/16 — alle drei gegen den Code geprüft,
nicht nach dem `[x]`-Marker geschlossen — der **dritte** die acht Zeilen 30–37 der Welle C, jede ebenfalls gegen
den Code geprüft, und **eine neue (38)** dafür aufgenommen; zuletzt die geschlossene **7**). **17, 21, 22, 23 und 25 sind verifiziert und freigegeben (2026-09-29, keine critical/high); 18–20, 24 und 26–29 sind per §4-Schnitt entfernt, weil verifiziert geschlossen; 30–37 sind mit Welle C entfernt (re-verifiziert **`APPROVED`**, ohne critical/high), und 7 ist ebenfalls entfernt (geschlossen, verifiziert, ohne lebenden Querverweis). CI GRÜN (Run 36537851878, WATCH-EXIT=0) — Zombie-These im echten Container belegt; Welle B ebenso grün (Run 36539885849, alle 4 Jobs); Nightly 36552912340 grün (striktes Profil, 142/52/0).** Geschlossen seit dem 2026-09-29 (per §4 entfernt, **nicht** vergessen): die Konto-Löschung, vormals Position 10, war als Ursache der `users`-Lücke geführt (**+15…+30** pro vollem Lauf, gemessen 2026-09-29, bewusst **nicht** geglättet — sie lieferte die DELETE-Route `ownership.ts:183`, ohne die das Ledger diese Art nicht besitzen kann; die **Nachmessung** des Delta steht noch aus). Offen: 22 (Teilaspekt admin-venue → 25); 38 (frische DB → 422 auf dem Team-POST, `ownership.spec.ts` grün nur aus ererbter Reihenfolge). **Die früher hier genannten 30–35 (Zombie-Fix-Follow-ups inkl. Ein-Token-Regression) sind alle abgearbeitet** — die Ein-Token-Regression ist behoben und mutationsgeprüft, ihr Docblock sagt jetzt ausdrücklich, was sie **nicht** ist. **Diese Liste ist der Nachtrag zum 2026-09-28-Session-Plan, nicht der Plan selbst** — die vollständige offene Menge steht in der Kopfzeile. **Der §4-Schnitt hat 17/21/22/23/25 bewusst NICHT entfernt**, obwohl sie verifiziert sind: sie tragen lebende Querverweise. **21** die korrigierte Zombie-Diagnose samt der inzwischen **vorliegenden** CI-Bestätigung (die früher auf eine Welle-C-Position verwies); **22** die „korrekt offen gelassene“ Restposition samt der TS-Parser-Voraussetzung aus **23**; **25** den unregistrierten admin-venue-Rest, auf den „22 (→ 25)“ zeigt; **17** die einzigen Verifikationszahlen der Welle A (test:run 405 · E2E 140 passed/50 skipped/0 failed · Screenshots 75 · Bänder 24/24/24) und den Negativbefund „M5 VOID (CACHE_STORE=array, kein persistenter Limiter)“.

**Eine Messung war unentschieden — und ist am 2026-09-29 entschieden:** der Verifikator bekam **zwei vergleichbare
Arme** (Parent `b433fd8` **103 passed / 2 failed**, HEAD `263290f` **119 passed / 8 failed**, gleiche
Worker, Cache geleert, gleiche Fremdlast) — **6 Netto-Ausfälle derselben Klasse**, alle
`toHaveURL`-Timeout auf `/login`. Das ist die **Login-Drossel** (40/min, Zähler im DB-Cache), und
die Änderung fügt `loginAdminApi()` **pro Test mit nichtleerem Ledger** hinzu — also genau auf das
Budget, das die UI-Specs brauchen. **Ob die 6 von der Änderung kommen oder von der Drossel unter
Fremdlast, war nicht entscheidbar:** der Messarm **ohne** Hintergrundlast war unmöglich, weil die
Maschine neu gestartet wurde und **Docker's Socket verschwand** — Postgres unerreichbar, ab da kein
E2E- und kein Screenshot-Lauf möglich. **Geliefert hat ihn der Nightly `36552912340`** (frischer
Runner, striktes Profil, **142 passed / 52 skipped / 0 failed**, **null** Login-Timeouts):
**die 6 Ausfälle waren die Drossel unter Fremdlast, nicht die Änderung.** Und: die **39–40 Ausfälle**, die der Implementierer meldete,
konnte der Verifikator **unter keiner Bedingung reproduzieren**; was er in dieser Grössenordnung
antraf, war ein **totes `php artisan serve`** mit 502 auf jedem API-Call — **kein Code-Fehler,
sondern eine umgegebene, die sich als Code-Fehler ausgab.**
