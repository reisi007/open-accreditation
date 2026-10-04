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
> **Verbleibend:** Go-Live (wartet auf Benutzer-Freigabe) + **4 Positionen** in der Batch-Tabelle (**17, 21, 23, 25** — alle vier stehengeblieben, weil lebende Querverweise daran hängen; in der Tabelle ist damit **keine** offene Zeile mehr). **Positionen 45 (Mail-Zustellung: Queue/DLQ) und 46 (Scheduler) sind am 2026-10-04 per §4 entfernt** — beide Ströme implementiert und verifiziert (Verdicts 12, 13, 14 `APPROVED`, kein `critical`/`high`; CI-Lauf `37183737789` zu `219c398`, alle vier Jobs grün). **GATE 4 GRÜN (Lauf `37034641045`, SHA `ab4c0c7`, 2026-10-02, **alle vier Jobs**):** SQLite **1771 passed** (9047 Assertions) · Postgres **1 skipped + 1770 passed** (9041) (= **dieselben** 1771) · Vitest **52 Dateien / 552 Tests** · E2E **157 passed** · `check:i18n` **436** · Pint/Lint/Build clean. **Gate 3 war auf `267c767` rot** (Lauf `37023069080`: `QrTokenV2Test`, „actual size 4 matches expected size 3" — nur Postgres) und ist auf `ab4c0c7` **behoben, nicht etikettiert**: die Behauptung `assertCount(3, explode('.'))` war nie eine Format-Invariante, sondern eine Eigenschaft der Id-Sequenz. **Sieben Positionen — 8, 9, 12, 22, 38, 41, 42 — sind am 2026-10-02 per §4 entfernt:** alle vier Wellen sind verifiziert (Verdict `APPROVED`, kein `critical`/`high`), ihr Dauerinhalt steht in `features/`, im Code und in den Wellen-Abschnitten. **Geschlossen, aber stehengeblieben sind 4:**
> 17, 21, 23, 25 — sie bleiben stehen, weil **lebende Querverweise** daran hängen (Begründung: „Offen bei Übergabe").
> **Arithmetik, aus der Datei gezählt (Stand 2026-10-04, nach dem §4-Schnitt):** **4 Zeilen** in der Batch-Tabelle = **0 offen** + **4 stehengeblieben** (17, 21, 23, 25 — lebende Querverweise). **Die sieben Positionen 8, 9, 12, 22, 38, 41, 42 sind entfernt** (verifiziert `APPROVED`), mit ihnen **45** (dieselbe Zeile, jetzt geschlossen). **Position 46** (kein Scheduler) stand nie in dieser Tabelle, sondern unter P6, und ist mit 45 entfernt. **Position 47** (Sub-`resend`-Button, P6-Block, Runden 24/25 `APPROVED`) ist am 2026-10-04 ebenfalls per §4 entfernt.
> **Positionen 48–51 (2026-10-04, Skills-Marker-Erstmarker `AGENTS.skills.md`, Doku-only):** vier neue Positionen am Dateiende — 48 (Serial-Pin vs. Named Locks), 49 (Test-Budget/Akteur-Schlüssel), 50 (`packageManager`/`overrides`), 51 (CI-Pfadfilter). Nichts davon ist implementiert; die GHCR-Prüfung (beide Packages public) steht im Marker, nicht als Position.
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
> **Positionen 5, 6 und 43 sind am 2026-10-01 per §4 entfernt** — 5 und 6 in **einer** Verifikationsrunde mit Verdict
> **`APPROVED`** (Implementierung `c2abdde`, Doku `e42a4a6`); ihre Nachbefunde sind in `84c0bd0` behoben und der
> **letzte** (F6) in `247b520` behoben **und** verifiziert. **Gemessene Gates der schliessenden Runde:**
> `pnpm test:run` **498 passed / 0 failed / 49 Dateien** · `pnpm build` inkl. `check:i18n` **exit 0** · `@smoke`
> **17 passed / 9 skipped** · `pnpm test:screenshots` **94 passed / 1 failed / 65 skipped**, vier
> aufeinanderfolgende Läufe bei **konstant 16 Bändern**.
>
> **Position 5 war bereits zu `51f21a0` geschlossen — diese Welle hat den Defekt NICHT behoben, sie hat eine
> veraltete Board-Zahl gefunden. Das ist das dritte Mal, dass dieses Board eine Zahl aus einem Baum trägt, den es
> nicht mehr gab** (nach zweien in Position 13), und die Form ist hier die schärfste: die Zeile war nicht nur
> überholt, sie behauptete eine **Wirkung**, die schon erbracht war. `git log -S'95 → 4 PNG'` führt die Zahl auf
> **`21f97c8`** (2026-09-28, 10:20) zurück — und an **diesem** Commit lag der Capture-Store **wörtlich** in
> Playwrights `outputDir` (`playwright.screenshots.config.ts:31` und `ui-review.config.ts:127`, beide
> `outputDir: 'test-results/ui-screenshots'`). **`51f21a0`** (15:34 **desselben Tages**, gut fünf Stunden später)
> zog ihn heraus, und **dess eigene** Commit-Message nennt denselben `-g`-Lauf als **`112 → 112`** plus ein
> SHA-256-Manifest über **346** Dateien. **Die Zeile beschrieb einen Baum, der seit fünf Stunden nicht mehr
> existierte — und niemand hat sie nachgezogen.** Wer sie je als Beweis für eine Reparatur gelesen hat, hat einem
> Commit eine Wirkung angeschrieben, die er nicht hatte.
>
> **Was diese Welle für 5 wirklich geleistet hat, ist etwas anderes — und es ist das durable:** sie hat das
> **Verhalten** des Stores als Spec festgenagelt, was der **Pfad** allein nie bewiesen hat. Der Pfad schützt gegen
> den Wischvorgang und sagt nichts darüber, was `archiveExisting()` und der Überschuss-Sweep tun — und genau dort
> löscht eine gut gemeinte Änderung die falsche Sache. Der Mechanismus ist **nicht-destruktiv** bewiesen:
> Playwrights rekursiver `outputDir`-Wipe erreicht **beliebige** Verzeichnisse darunter (**6 geseedete, ihm
> unbekannte Dateien → 0**).
>
> **Wo die tragende Substanz jetzt liegt — jede Stelle am Code geprüft, nicht geglaubt (§3), und alle in Dateien,
> die dieser Schnitt nicht fasst:**
> · **Pfadentscheidung und die drei gemessenen Anordnungen** — `frontend/tests/screenshots/helpers/store-paths.ts:12-23`:
>   Store im `outputDir` → partieller Lauf **126 → 11 PNG** · Store als *Sibling* drin → `--output=test-results`
>   noch **192 → 0**, weil der Runner **vor** `globalSetup` wischt · Store ganz aus `test-results/` → nichts zu
>   löschen. **Der Schutz ist der Pfad, nicht ein Wächter** — und beide Pfade sind an *einer* Stelle deklariert,
>   damit sie nicht auseinanderlaufen können; der Wächter ist Tripwire (`capture-store.ts:145`).
> · **Die `prev/`-Regel „eine Generation“ und der Überschuss-Sweep** — `capture-store.ts:51-65` (Regel und Serie),
>   `:324-331` (`archiveExisting`), `:359-368` (`storePngSeries` räumt den Überschuss). Als **Verhalten** behauptet,
>   nicht als Pfad: `tests/screenshots/store-partial-recapture.spec.ts` (vier Tests × zwei Projekte), mutationsgeprüft
>   — `archiveExisting` löscht statt zu archivieren → **4 rot**.
> · **Das `Reproduzierbar:`-Urteil, jetzt mit der Lauf-Key-Bedingung** — `scripts/ui-review-captures.mjs:243-263`
>   (die drei Bedingungen und beide gemessenen Vakuumsformen), `:272-279` (`isReproducible`), `:291-309` (`whyNot`
>   mit **einer** Klausel pro unmet Bedingung, die die Schlüssel **nennt**).
> · **`/konto` im Manifest** — `tests/screenshots/ui-review.config.ts:522-523`, beide States × beide Viewports,
>   getrennt über die **Anzahl** (`1 Antrag` vs `0 Anträge`), nicht über eine Überschrift, die in *beiden* States
>   dasteht.
> · **Der `contentCount`-Vertrag** — `capture-store.ts:222-236`: warum `null` ein **Wert** ist und keine Lücke.
>
> **Die Bandzahl, gemessen statt behauptet: `16/16/16/16/16`** über **fünf** aufeinanderfolgende volle Läufe, mit
> `Reproduzierbar: ja` ab dem zweiten. Damit sind **sämtliche in den Zeilen genannten Zahlen überholt** — `35/45/66`,
> `23→4` / `0/2`, `51/52→24` und das „**4** Bänder bei **759** Usern". Der letzte offene Treiber ist **gemessen weg**:
> `admin-users` steht bei **2 Bändern bei 7 Usern**, und ein `@smoke`-Lauf lässt `users` auf **8** steigen
> (7 Review + 1 Admin) — **das Ledger gibt zurück, was es erzeugt.** (Position 17 führt diese Nachmessung noch als
> **ausstehend**; sie ist mit dieser Zeile erledigt — die Aussage dort ist jetzt veraltet.)
>
> **Die zwei Befunde der Verifikation, die jetzt geschlossen sind — und beide sind die Form, die künftige Arbeit
> wiederholen wird:**
> · **Ein Kriterium, das die Lücke als Erfolg liest.** `hasComparison` genügte; gemessen meldete ein `-g`-Teillauf
>   **„Reproduzierbar: ja"** bei **4 verglichenen und 60 unverglichenen** Zeilen. Und die Reparatur schloss nur die
>   halbe Form: nach `-g home` standen **60 Zeilen `run-106324` + 4 Zeilen `run-108270`, 0 ohne Vorgänger,
>   0 geändert → ebenfalls „ja"** — ein Batch aus **zwei** Läufen, von dem die 60 Zeilen die Zahlen des *letzten*
>   Laufs tragen. `runKeys.length === 1` ist die Bedingung, und die `nein`-Klausel **nennt die Schlüssel**. Der
>   Satz, dass ein Teillauf eine Lücke erzeugt, stand ab `e42a4a6` in `AGENTS.md:405` — aber nur als **„JEDE Zeile
>   hat einen Vorgänger"**; die **Ein-Generation**-Hälfte steht im Code und **nirgends sonst** (Lücke, gemeldet).
> · **Ein Test, der die Klebzeile nicht behauptete.** `contentCountFrom()` war korrekt in eine reine, voll getestete
>   Funktion verschoben — aber die **eine** Zeile, die entscheidet, ob der gemessene Wert oder eine Konstante ins
>   `<name>.meta.json` landet, war von **nichts** behauptet. Gemessen: den historischen `return 1` wieder einzusetzen
>   lässt die volle Suite **byteidentisch** bei 94/1/65 grün — bei **falscher Zahl im Review-Artefakt**. Behoben in
>   `247b520` durch eine Behauptung **innerhalb der Aufnahme** (`ui-screenshots.spec.ts:400-410`), die das
>   **geschriebene** Sidecar liest: Mutation → **90/5/65**, also vier zusätzlich rot. *Ein Test, der nicht
>   fehlschlagen kann, IST der Defekt* — das ist Position 17 in einer anderen Verkleidung.
>
> **Der Nutzerentscheid, den 43 trug („Später, nach Position 5"), ist damit verbraucht, nicht verloren:** er war
> eine **Reihenfolgeentscheidung gegen eine offene Position**, und es gibt keine offene Position 5 mehr — die Seite
> ist im Manifest, `old vs new` funktioniert. Er wird **nicht** als neue Zeile zurückgebracht: eine Zeile, deren
> Bedingung erfüllt ist, wäre ein Board-Eintrag, den niemand abarbeiten kann (dieselbe Form wie „nicht schließbar"
> in der Welle-C-Tabelle). **Neu aufgenommen wurde in dieser Welle nichts.**
>
> **7** (Nightly) ist mit dem Wave-C-Sweep mit entfernt — geschlossen, verifiziert, ohne Querverweis; ihre Zahlen
> stehen in den Absätzen darüber und im Wave-C-Abschnitt.
> **Position 22 war per Verifikatorentscheid zunächst korrekt offen gelassen** — der `[x]`-Marker galt dem
> Verifikationslauf, nicht der Position; ein mechanischer `[x]`-Sweep hätte genau die offene Position gelöscht.
> **Mit dem §4-Durchgang 2026-10-02 ist sie verifiziert und entfernt** (zusammen mit 8, 9, 12, 38, 41, 42).
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

## 🗓️ Umsetzungsplan — alle offenen Positionen in Wellen (Nutzerentscheid 2026-10-01)

> **„In Wellen müssen alle umgesetzt werden."** Sieben der acht offenen Positionen sind damit
> beauftragt; **14** bleibt ausgenommen, weil sie ein **externer** Schritt ist (Google-Wallet-
> Issuer-Zugang) — sie bleibt als offene Position stehen, damit sichtbar bleibt, dass P6 an genau
> einem Stück hängt, das wir nicht liefern können (Nutzerentscheid 2026-10-01). **Zwei Ströme
> parallel, disjunkte Ziel-Dateien.**

| Welle | Strom A | Strom B | Trennungslinie |
|---|---|---|---|
| **1** | **42** — `phpunit.xml`-Pins | **12** + **38** — Screenshot-/E2E-Harness | `backend/` gegen `frontend/` |
| **2** | **9** — Druck misst Textbreite selbst | ~~**8**~~ — **siehe unten: vermutlich `ALREADY-SATISFIED`** | Backend-Renderer gegen Screenshot-Spec |
| **3** | **41** — Content-Stream-Wächter | **22** — Ledger-Namens-Lookup | `BadgeTest.php` gegen E2E-Ledger |

**Stand der Wellen:** Welle 1 **implementiert, committet und verifiziert** (`3b9ecd8`, `c46ba63`, `7d2fe56`).

**Welle-1-Verdikt (2026-10-02): `APPROVED`, kein `critical`/`high`.** Geprüft gegen den committeten Stand (`576aa40`); die Welle-1-Dateien sind bis `04e8ee5` byte-identisch geblieben. Beide Ströme sind **am Code** belegt, nicht an der Absicht (§3): Strom A **5 Mutationen** (Pin entfernen → 1–2 rot je Key, `verbatim` entfernen → typisierender Fehler, `MAIL_MAILER` zurück auf `smtp` → rot), Strom B **6 Mutationen** (Reporter abmelden → rot, `walked.files === 0`-Bedingung entfernen → **5 rot**, Legacy-Literal divergieren → 2 rot, Idempotenz-Guard entfernen → rot, `ensureTeamsEnabled`-Aufruf entfernen → 2 rot). Position 38 zusätzlich am **echten Stack** (scoped E2E, `--workers=1`): unmutiert **2 passed**, mit `teams_enabled=false` **2 failed** an der benannten Assertion. Die zehn Pins sind auf **beiden** Engines grün (Postgres `dind`, 118 passed/413 Assertions für die betroffenen Klassen) — die §2-Regel „beide Engines" damit für Welle 1 **neu gemessen**.

**Die zwei `low`-Befunde sind in `4c308f3` behoben** — kommentar-only. **F1** (`phpunit.xml`): die Zahlen „162 gelesen / 21 gepinnt / 147 ungepinnt" waren falsch **und** intern inkonsistent (`21+147≠162`); neu gemessen und mit **Methode** genannt (**159** distinkte `env()`-Keys per PHP-Tokenizer, **31** `<env>`-Namen, **25** Überlappung, **134** ungepinnt), als **Snapshot** gekennzeichnet. **F2** (`ci.yml`): der mailpit-Kommentar sagte „phpunit.xml sendet via SMTP" — seit Position 42 ist der **Default** socketlos (`array`), aber `MandantMailerService::send()` → `Mail::mailer('smtp')` (`:54`) geht am Pin vorbei, also bleibt Mailpit benutzt und begründet. **Ein Follow-up bleibt offen:** `phpunit.xml` trägt im selben Block noch die **Referenzzahl `1732 passed`** aus dem Hostile-`.env`-Messlauf; der aktuelle SQLite-Stand ist **1778**. Sie ist als Referenz jenes Laufs formuliert — der **Fix-Verifikator hat das bestätigt** (`4c308f3`: Verdikt **`APPROVED`**, die Zahlen **159/31/25/134** unabhängig per eigenem PHP-Tokenizer nachgemessen, die 6 Nicht-config-Pins bestätigt, kommentar-only über genau zwei Dateien). Die `1732` wird deshalb **bewusst akzeptiert** (low, kein Fix): sie ist als datierter Referenzlauf gekennzeichnet, und die Fehlertabelle dieses Kommentars stammt aus einem **instrumentierten Volllauf**, der in einem gefilterten Lauf nicht reproduzierbar ist.

**Gate nach dem Nachtrag (Qr-/Badge-Reparatur + Doku), SHA `6aab126`, Lauf `37038133242`: grün, alle vier Jobs.** SQLite **1778 passed** (9149 Assertions) · Postgres **1777 passed + 1 skipped** (9143 Assertions) · Vitest **52 Dateien** · E2E **157 passed** · `check:i18n` **436**. `1778 + 0 = 1777 + 1` — beide Engines zählen dieselben 1778. **Dieser Lauf trägt auch `04e8ee5`** (BadgeTest, dieselbe `explode('.')`-Fehlerklasse wie `ab4c0c7`): dessen eigener Lauf war von einem späteren Push **cancelled** worden, der Inhalt ist damit trotzdem CI-gemessen.

**Welle-2-Verdikt (2026-10-02): `APPROVED`, kein `critical`/`high`.** Geprüft gegen `HEAD` (die Commits `799e330` und `3c1fbe3` sind Vorfahren), Baum vor/nach allen Mutationen byteidentisch restauriert (sha256 je Datei).
- **Position 9** ist per **6 Mutationen** belegt, und die Commit-Zahlen wurden **reproduziert**: kein Schrumpfen → **4** rot · kein Umbruch → **7** · `sans-serif` **messen** statt `DejaVu` drucken → **7** · stiller Fallback statt `BadgeTextFontUnresolvedException` → **2** · `overflow:hidden` zurück ins Textfeld → **1** · `photo` durch den Auto-Fit → **2**. Filter: `BadgeTextAutoFitTest` **15 passed/107**, `Badge|AppUrlHermeticity` **203 passed/1308**, 0 rot. Der Vertrag hält: Font wird **in Listenreihenfolge** aufgelöst (nie `sans-serif`), Messung ist derselbe `FontMetrics`/Canvas wie der Druck, Umbruch Wörter→Bindestrich→zeichenweise, darunter Schrumpfung mit Bisection, darunter dokumentiertes Überlappen — **kein** `overflow:hidden`/Ellipse (`:128-131` pinnt die Abwesenheit).
- **Position 8** ist Klausel für Klausel am Code belegt: (a) echter Export-Weg `POST …/badges/export` mit **401-Beweis vor** dem Login (Route in `auth:api`-Gruppe, `globalSetup` authentifiziert nicht), (b) Rasterung über `scripts/pdf-to-png-vision.sh`, (c) PNGs neben dem Editor-Capture (`compareWith: EDITOR_ROUTE`), (d) `printVisionNote()` wörtlich „TWO VIEWS OF THE SAME BADGE TEMPLATE", plus Seitenanzahl-Postcondition. **Offen bleibt allein der Lauf gegen den echten Store** — und dort blockiert auf diesem Host **`magick`/IM7** schon Klausel (b) (Exit 3, belegt), weshalb auch (c) und die Seitenzahl-Postcondition **nicht ausgeführt** sind: ein **benanntes Werkzeug-Gate**, kein Defekt.
- **Drei `low`-Befunde, alle Prosa** (kein Verhalten): zwei Docblock-Ungenauigkeiten in `BadgeTextFitter.php` (der `$cache`-Grenzwert „50 characters or more" ist off-by-one gegen `!isset($text[50])`, das heißt gecacht bis **49**; der Geometrie-Satz „the box cannot print 14 pt" ist für **eine** kurze Zeile falsch — der Grund fürs Schrumpfen ist Breitenüberlauf → Umbruch → zu hoch, und der Test formuliert es korrekt), plus eine **Board-Text-Korrektur**: die Position-9-Zeile behauptet, `FontMetrics::getFont()` **werfe** bei unbekannter Familie — **gemessen gibt es `null` zurück**, und der gelieferte Code `BadgeTextFontUnresolvedException` ist genau dafür da (Test grün). Der Code ist richtig, der Board-Text war es nicht.

**Welle-3-Verdikt (2026-10-02): `APPROVED`, kein `critical`/`high`.** Geprüft gegen `HEAD` (`267c767`, `9056fdb`, `3d52cea`, `04e8ee5` sind Vorfahren).
- **Position 41** (Strom A): der Wächter ist **beabsichtigt** — `cardPictureStructure()` zählt `… Do`-Operationen, klassifiziert via `/SMask` und prüft fünf Regeln, **kein** `/I<n>`-Zähler. Drei unabhängige Mutationen **beißen**: `/SMask`-Regex neutralisiert → **4 rot**, Maske-Gezeichnet-Regel → **1 rot**, Draw-Count-Regel → **1 rot** (der Negativtest fängt sie). Der Feldtext ist **echt** (positive Assertion auf `Jane Doe` → PASS). `BadgeTest` **57 passed/676** auf **beiden** Engines (§2 neu gemessen).
- **Position 22** (Strom B): der Zähl-Vergleich ist ein **echter Zähler** — Beweis durch Gegenprobe: `registrationsIn` durch einen Boolean ersetzt **und** eine von zwei Registrierungen entfernt → **grün** (der ursprüngliche M1-Befund reproduziert); mit dem Zähl-Vergleich dieselbe Entfernung → **rot** („creates 2 categories row(s) … but that test registers 1"). Vier der fünf Specs laufen zur Laufzeit grün (`admin-category`, `admin-event`, `admin-venue`, `admin-mandant`); `approvals` (`blacklists`) blieb **ungemessen** — Mailpit fehlt (`ECONNREFUSED 127.0.0.1:8025`, Umgebungs-Gate). `created-row-lookup` **18 passed**.
- **Ein `low`:** der erklärende Docblock in `BadgeTest.php:463-469` ist **invertiert** (er sagt, die Feldtext-Assertion habe „ohne den Namen auf der Karte" gehalten — gemessen hält sie **mit** dem Namen und **fällt** unter dem `rtrim()`-Extractor). Der Messwert (`0x0d`/Adler-32) stimmt, nur die Schlussfolgerung nicht; der Code ist nicht betroffen.

### 🚦 Das Gate zwischen den Wellen — „CI muss grün werden" (Nutzerentscheid 2026-10-02)

**Explizit geplant und ausgeführt, nicht nebenbei.** Volle Suiten laufen **in CI**, nicht lokal —
der GitHub-Runner ist ephemer und hat eigenen RAM, dieser Host nicht (`Agents.headless.md` §3a).

| Schritt | Was |
|---|---|
| 1 | Implementierung committen, **pushen** (explizite Pfade, nie `git add -A`) |
| 2 | `gh run list` **einmal** lesen, bis der Lauf für **diesen** SHA existiert |
| 3 | Ergebnis **mit SHA** nennen — nie „läuft in CI", nie „wird schon passen" |
| 4 | **Rot ⇒ Vorrang vor aller neuer Arbeit** (§5(6)f): analysieren, isoliert fixen, grün pushen, erst dann die nächste Welle |

**Ein Block gilt für „fertig", nicht für „verifiziert."** Fertig = implementiert, getestet,
committet. Verifiziert = von jemand **anderem** geprüft. Der Unterschied ist der ganze Punkt: die
volle Suite ist lokal der Grund, eine Welle nicht fertigzumachen — sie ist der Grund, zu pushen und
die CI antworten zu lassen.

**Grenze, die nicht zu schönreden ist:** CI beweist nicht, was unter **Fremdlast** passiert
(`Agents.headless.md` §5), und sie ersetzt den strikten Nightly nicht — sie ist der verzeihende
Standardlauf. Für Flakiness bleibt `playwright.regression.config.ts` (`retries: 0`,
`maxFailures: 1`) maßgeblich.

**Erster Gate-Durchlauf, 2026-10-02: GRÜN, SHA `7f6f071`.** Lauf `37000171539`, alle vier Jobs:

| Gate | Ergebnis |
|---|---|
| Backend SQLite | **1741 passed**, 8741 Assertions |
| Backend Postgres | **1740 passed**, 8735 Assertions, **1 skipped** |
| Vitest | **51 Dateien** passed |
| E2E (volle Suite, verzeihend) | **157 passed / 57 skipped / 0 failed**, 2.2 min |
| `build` inkl. `check:i18n` | ✅ 436 Nachrichten |
| Pint, Lint | clean |

**`1741 + 0 = 1740 + 1` — beide Engines zählen dieselben 1741 Tests, und die Differenz ist genau der dokumentierte `AllocationAtomicityTest`-Skip.** Die §2-Regel „beide Engines, deckungsgleich" ist damit **neu gemessen**, nicht aus einer Board-Zahl übernommen. (Der `AGENTS.md`-Snapshot `1732/1731` war durch Welle 1 überholt: +9 Tests aus den neuen Pins, −1 Skip durch den entfallenen Mailpit-Probe.)

**Die `ERROR`-Zeilen im Postgres-Log sind kein Befund:** sie stammen aus dem Schritt `Stop containers` (Tear-down), nicht aus dem Testlauf. Wer sie liest, hält einen grünen Job für rot.

**Was dieser Lauf beweist und was nicht:** grün gegen **einen** Push auf **einem** Runner. Nicht geprüft ist das Verhalten unter **Fremdlast** (`Agents.headless.md` §5), und der Lauf ersetzt den strikten Nightly nicht — er ist das verzeihende Profil (`retries: 2`, `maxFailures: 10`).

**Gate 2, 2026-10-02, SHA `9056fdb`, Lauf `37005447856`: grün, alle vier Jobs.** SQLite **1741 passed** · Postgres **1740 passed + 1 skipped** (= dieselben 1741) · Vitest **52 Dateien** (die 51 plus `created-row-lookup.test.ts`) · E2E **157 passed**, 3.0 min · `check:i18n` **436**. **Der E2E-Zähler ist unverändert bei 157** — obwohl Position 22 Zeilen in den Ledger überführt hat: das ist das erwartete Bild, denn die Specs legen dieselben Zeilen an, sie räumen sie nur jetzt selbst ab. **Das Zählen bleibt derselbe Job, das Aufräumen ist nicht mehr Glückssache.**

**Was dieser Lauf nicht geprüft hat, und das bleibt ein offenes Gate:** Position 22 verlegt, **wann** Zeilen sterben — nicht ob. Die §7-Abnahme „drei Läufe ergeben dieselbe Bandzahl" ist damit **nicht** bestätigt und muss von dem Lauf neu gemessen werden, der den Screenshot-Harness fährt. Der ausdrückliche Nachweis ist stattdessen der kumulative: **zwei `E2E_PURGE=off`-Läufe hintereinander hinterlassen 0 Zeilen auf jeder Entitätsart**, wo derselbe Ablauf vorher `+1 teams`, `+1 venues`, `+1 blacklists`, `+1 sub_accreditations` **pro Lauf** liegen ließ.

**Gate 3, 2026-10-02, SHA `267c767`, Lauf `37023069080`: ROT — und der Fehlschlag war ein echter Fund, kein Regressionsfehler.**

| Job | Ergebnis |
|---|---|
| Frontend (Lint, Build, Vitest) | **success** |
| E2E (Playwright) | **success** |
| Backend (PHPUnit + Pint) | **success** |
| **Backend (PHPUnit vs. Postgres)** | **failure** — `1 failed, 1 skipped, 1747 passed` |

**Der eine rote Test: `QrTokenV2Test > minted token is the tenant bound v2 format`, `Failed asserting that actual size 4 matches expected size 3`, an `QrTokenV2Test.php:117`. Nur Postgres; SQLite grün.**

**Die Ursache, gemessen statt vermutet.** `QrTokenService::encode():246` baut den Token als `applicationId.'.'.$signature.'.'.$mandantId`, und `$signature` kommt aus `hash_hmac(…, true)` — **rohe Binärdaten, 32 Bytes** (`:253`). Ein `0x2E`-Byte darin ist **nicht** ausgeschlossen. Am Signing-Key dieses Repos über ids 1..20000 gemessen: **11,87 % der ids erzeugen eine Signatur mit `0x2E`.** In 1..40 sind es `2, 8, 14, 27, 35, 40`. Der Test macht `explode('.', $decoded)` und behauptet **genau drei** Segmente — eine Eigenschaft, die das Format **nie** hatte.

**Das Produkt ist korrekt, und es weiss das selbst.** `parse():163` nimmt den **ersten** Separator (`strpos`) für die Application-Id, `:193` den **letzten** (`strrpos`) für die Mandant-Id — **von aussen nach innen**. Der eigene Kommentar `:178-181` sagt genau warum: *„The raw HMAC is binary and may itself contain a '.' byte, so the segments are taken from the outside in."* **`QrTokenService` wird deshalb nicht angefasst.**

**Warum es JETZT feuerte — und das ist der eigentliche Befund:** `QrTokenV2Test::approvedApplication():853` legt eine echte `Application` an, ihre Id kommt also aus der Autoincrement-Sequenz. `BadgeTest` legt ebenfalls eine an; Position 41 (`267c767`) hat dort **8 Tests ergänzt** und damit die Sequenz **verschoben** — die Application des Tests landete auf einer der verhängnisvollen ids. Auf SQLite lief die Sequenz anders, deshalb ein Engine grün und die andere rot. **Position 41 hat den Fehler nicht verursacht, sondern freigelegt:** ein latenter Testfehler, der unter dem alten Regime als „flaky" etikettiert worden wäre, und zwar von dem, der den nächsten ~1-in-9-Fehlschlag erwischt.

**Das ist das Argument für die CI-Lokalisierung in einem Vorfall.** §4 verbietet das Etikett „pre-existing": der Fehler wird behoben, nicht dokumentiert.

**Gate 4, 2026-10-02, SHA `ab4c0c7`, Lauf `37034641045`: grün, alle vier Jobs. Der Gate-3-Fehler ist damit behoben und geschlossen — nicht etikettiert.**

| Gate | Ergebnis |
|---|---|
| Backend SQLite | **1771 passed**, 9047 Assertions |
| Backend Postgres | **1770 passed**, 9041 Assertions, **1 skipped** |
| Vitest | **52 Dateien** passed |
| E2E (volle Suite, verzeihend) | **157 passed**, 3.0 min |
| `build` inkl. `check:i18n` | ✅ 436 Nachrichten |

**`1771 + 0 = 1770 + 1` — beide Engines zählen dieselben 1771 Tests, die Differenz ist der dokumentierte `AllocationAtomicityTest`-Skip.** Der Postgres-Test, der Gate 3 rot machte, läuft jetzt auf **beiden** Engines. Der Fix ist `ab4c0c7`: `splitV2Payload()` spiegelt `QrTokenService::parse()` (outside-in), die Segment-Anzahl wird als Relation behauptet statt als Konstante, sieben Id-Paare sind über einen Datenprovider als **dotted** gepinnt, und drei Tamper-Tests haben einen Control bekommen (der unveränderte Payload muss verifizieren) — sonst wären sie aus dem falschen Grund grün. `QrTokenService` selbst wurde **nicht** angefasst: es war korrekt, und der eigene Kommentar `:178-181` sagt warum.

**Der Lauf trug drei Commits — `799e330` (Position 9), `cb44bce` (Board-Doku Gate 3) und `ab4c0c7` (Qr-Fix) —, also *einen* Baum und nicht einen Commit.** Der Vorschlag, den Qr-Fix getrennt zu pushen, war damit hinfällig: CI antwortet auf den Baum. Gemessen wurde zuerst lokal (SQLite `--filter QrTokenV2Test` **35/311**, Postgres `dind` **35/311**, Pint clean) und dann in diesem Lauf.

### 🔧 Vorübergehende Anweisung 2026-10-02: `deepseek-v4.1-flash` für neue Subagenten

**Ausdrückliche Nutzergenehmigung, und ausdrücklich „temporär".** `AGENTS.md` §5 verlangt vor jedem Einsatz den **Uhren-Check** (nicht aus dem Kopf). Gemessen am 2026-10-02, 16:00 UTC:

```
2026-10-02 16:00 UTC  jetzt=OFF  naechste Peak=2026-10-05 01:00 UTC  Rest=57.00h  -> ERLAUBT
```

**57 Stunden off-peak** (das Peking-Wochenende läuft Fr 16:00 UTC → Mo 01:00 UTC), also ist die Ein-Stunden-Regel weit erfüllt. Off-Peak-Preis **$0.15 / $0.60** pro M, Quelle `models`-Abfrage (nicht aus dem Changelog zitiert). **Der Check läuft vor jedem weiteren Start neu** — die Regel ist eine Uhr-Regel, und das Fensterende ist das, was zählt.

**Der Zusatz „temporär" ist hier wichtig und wird deshalb festgehalten:** eine Anweisung ohne Endbedingung wird zur Policy, die niemand beschlossen hat. Gültig **bis auf Widerruf**; wird sie widerrufen, steht hier das Datum. **Die Modellwahl der bereits laufenden Subagenten wird nicht mitten in der Arbeit umgestellt** — ein Kontextwechsel mitten im Fix wäre teurer als der Preisunterschied.

**NACHTRAG 2026-10-02 (später, Nutzerentscheid): Eskalationsmodell entfallen — Subagenten laufen auf `space-bunny-free`.** Ein Subagent-Lauf brach mit *„An active OpenCode Go subscription is required to use Go models"* ab; die Go-Subscription ist nicht mehr vorhanden. Der Nutzer hat daraufhin angewiesen, **wieder Space Bunny Free** zu nehmen. Zwei Folgen, beide Folgen der Regel und nicht neue Erfindungen:

- **Die Uhren-Regel (§5) ist hier gegenstandslos**, solange kein Go-Modell eingesetzt wird — sie ist eine Preisregel für dieses Modell. Sie greift erst wieder, wenn eines beauftragt wird.
- **Die abschließende Verifikationsrunde läuft nicht auf einem Eskalationsmodell** — sie fährt jetzt dasselbe Modell wie die Arbeit, die sie abschließt. Genau das wollte §5; vorher stand die Regel gegen eine Umgebung, in der Build-Agent und Eskalationsmodell dasselbe waren.
- **Verfahrensfolge des Abrisses:** ein abgebrochener Subagent wird nach der **Restart-Regel** (§5) mit `sessionID` + „weiter" **fortgesetzt, nicht neu beauftragt** — seine In-Flight-Änderungen liegen im Baum und sind der Fortsetzungspunkt. Genau so wurde Strom A (Position 45/46) nach dem Modellabriss fortgesetzt, nicht neu gebaut.

 `frontend/tests/screenshots/badge-print.spec.ts` + `helpers/badge-print.ts` erfüllen den Vertrag der Zeile **Klausel für Klausel**: (a) PDF über den **echten** Export-Weg (`POST …/badges/export`, nie `renderPdf()`), (b) Rasterung über `scripts/pdf-to-png-vision.sh`, (c) PNGs **neben** dem Editor-Capture (`compareWith: EDITOR_ROUTE`), (d) `printVisionNote()` sagt wörtlich „TWO VIEWS OF THE SAME BADGE TEMPLATE". Dazu zwei Dinge, die die Zeile nicht verlangt hat: ein **401-Beweis vor dem Login** (der Export ist session-gegatet, nicht ambient offen) und die **Seitenanzahl-Postcondition, die das Backend nicht hat** (`buffers.length !== expectedPages` wirft) — genau die Lücke, die §7 nennt. Geliefert in `3c1fbe3`, also in einer **früheren** Welle. **Es wird keine Arbeit erfunden:** die Position gilt als `ALREADY-SATISFIED`, sobald die Verifikationsrunde das bestätigt; ein Lauf gegen den echten Store ist der einzige offene Rest, und der Store ist gitignored und auf diesem Host nicht vorhanden — das ist ein **benanntes Gate**, kein Claim.

**NACHTRAG 2026-10-02: der Lauf ist gefahren, und er hat den Vertrag bis auf einen Schritt belegt.** Stack hochgefahren (beide Container via `docker start`, `artisan serve :8000`, `pnpm dev :5173`), dann `npx playwright test -c playwright.screenshots.config.ts -g "printed badge" --workers=1`. Ergebnis:

| Klausel | Befund |
|---|---|
| (a) echter Export-Weg, session-gegatet | **belegt** — `assertExportRefusedWithoutSession` → **401** lief durch, und die Assertion liegt **vor** dem Capture. Danach Login, dann der Export. Der rote Test kam **später** |
| (a) der Export liefert ein echtes PDF | **belegt** — `frontend/test-artifacts/ui-review/filled/desktop/admin-badge-print.pdf`, **16023 Bytes**, `%PDF-1.7`, **2 Seiten**, **5 Bilder** |
| (c) PNGs neben dem Editor-Capture | **nicht erreicht** (siehe unten) |
| (d) Vision-Auftrag | **nicht erreicht** |
| **Position 12 wirkt in Produktion** | **belegt** — `prev/admin-badge-print.pdf` existiert: `archiveExisting()` hat die Vor-generation gesichert |

**Der Abbruch ist ein Werkzeug-Gate, kein Defekt.** `pdf-to-png-vision.sh` fährt **Fallback A** (`gs -sDEVICE=png16m`, `:310`) korrekt an, rastert deckend und prüft den Alpha-Kanal — aber die **Tintenmenge ist ohne `magick` nicht messbar**, und genau dafür liefert das Skript **Exit 3** (dokumentiert `:176-182`). Das ist die Postcondition, die sich selbst schützt: eine Seite ohne gemessene Tinte gilt nicht als bestanden.

**Warum `magick` hier nicht installierbar ist — und das ist ein Befund über eine Annahme im Skript.** Der Host hat ImageMagick **6.9.11** (`convert`, `gs` vorhanden), und `magick` ist der **IM7**-Binärname. Debian 12 bietet nur IM6 an (`apt-cache policy imagemagick` → `8:6.9.11.60+dfs…`, Candidate identisch), `graphicsmagick-imagemagick-compat` liefert keinen `magick`. Das Skript sagt in `:181-182` ausdrücklich: *„ist das nicht erreichbar, weil dort immer ein magick im PATH steht oder ausdrücklich keiner — **ein Linux-Feld ohne ImageMagick installiert magick**"*. **Für Debian 12 stimmt dieser Satz nicht** — IM7 ist dort nicht installierbar. Das Skript wird **nicht** aufgeweicht, um das zu umgehen: `:57` sagt selbst, ein Skript, das ohne `magick` grün durchläuft, sei schlimmer als keines. Der Satz gehört **korrigiert** (Installationsweg präzisieren), nicht die Postcondition.

**Was das für Position 8 heißt:** Klausel (a) ist **gemessen**, (c) und (d) bleiben **offen** — das ist ein **benanntes Gate** (ImageMagick 7), kein „nicht testbar". Der Rest ist eine Werkzeugfrage, und sie ist eine Ebene unter der Position.

**Reihenfolge ist eine Betriebsregel, kein Vorschlag:** Implementieren → **committen** → Verifizieren
(`Agents.headless.md` §4.1). Ein Verifikator, der in einem Baum mit uncommitteter Arbeit mutiert,
hat keinen Rückweg — das ist hier bereits einmal passiert.

### Gemeinsame Verifikation bei parallelen Strömen (Nutzerentscheid 2026-10-01) — **was genau das bedeutet**

**Ein** Verifikator prüft **beide** Diffs zusammen — das ist die gewünschte gemeinsame Runde, und
sie kostet keinen zusätzlichen Lauf. **Die Gates laufen dabei nacheinander, nicht nebeneinander.**
Der Grund ist gemessen, nicht vermutet (`Agents.headless.md` §5): unter Last bläht jeder Test einer
Datei um **3–5×** auf (14 → 56 ms bei 24 Spinnern auf 18 Kernen), ein 10-s-Budget reißt, und
eine Reserve-Teststelle verlor 10 s, obwohl sie intrinsisch ~563 ms kostet. Zwei volle Suiten
**nebeneinander** kosten also nicht nur Zeit, sie kosten eine **grüne Ampel ohne Aussagekraft** —
das ist der schlechtere Deal. Gemeinsame Verifikation heißt hier: **ein Urteil über zwei Diffs,
zwei Messungen in Serie.** Das räumt die Spannung zwischen dem Nutzerentscheid und §6 auf, ohne
die Messung zu opfern.

### Zwei Zahlenfehler in Zeile 42, gefunden beim Nachzählen — **die Zeile wird nicht geglaubt**

Die Zeile sagt „**26 Fehlschläge** über **9** Keys“ und listet dann **10** Keys, deren Einzelwerte
sich auf **27** summieren (5+5+3+3+3+2+2+2+1+1). Beide Zahlen sind um eins daneben, und **die
beiden schlimmsten Fälle sind die um eins daneben** — ein Pin, den man für gemessen hält und der
nicht existiert, ist schlechter als ein fehlender. **Folge für den Auftrag:** der Implementer
**misst neu** und trägt die **gemessenen** Zahlen ein; **keine** der beiden Board-Zahlen wird
übernommen. Die **Absicht** ist eindeutig — die Entscheidung nennt alle 10 Keys —, nur die
Herleitung ist falsch. *(Genau die Fehlerform, die §3 verbietet: eine Zahl aus einem Baum, den es
so nicht gab.)*

### Zwei Entscheidungen, interaktiv geklärt 2026-10-01 — beide waren offen, keine wird erfunden

**1. Position 41 — Fixrichtung: ein Wächter gegen die SMask-Zeichnungsanzahl.** Das Board sagt
ausdrücklich „Fixrichtung ist offen und wird hier nicht erfunden", und nennt beide Kandidaten. Gewählt
ist der **erste**, weil er die *Positionsunabhängigkeit* herstellt: der heutige `/I<n>`-Zähler hängt
am GD-Build (dompdfs Alpha-Regel `Cpdf.php:6255` zerlegt ein Paletten-PNG in Maske+Bild, außer die
Bit-Tiefe ist exakt 4 — **CI: 4, hier: 1**), und der bestehende „Treffer" ist ein **Zufallstreffer**:
`assertStringContainsString('Jane Doe', $text)` hält nur, weil das letzte Byte des Content-Streams
`0x0d` ist, und das ist das Low-Byte des **Adler-32-Trailers** — eine Prüfsumme, kein Inhalt. Der
Zweite wäre ehrlicher, lässt aber die Lücke stehen; die Position trägt die **Lücke**, nicht die
Lösung, und ein Wächter gegen die SMask-Trennung schließt sie.

**2. `MAIL_MAILER=array` — jetzt umsetzen, zusammen mit Position 42.** `phpunit.xml:145-146` zeigt
auf `127.0.0.1:1025`, wo **nichts lauscht**; der Docblock `:139-144` benennt das selbst und nennt
`array` als strukturelle Schließung. Das war als „Design, nicht Reparatur" markiert, weil
`MailTest.php` / `MandantMailerTest` betroffen sind — **das ist eine Design-Entscheidung, und sie ist
jetzt gefallen.** Wirkung: ein vergessenes `Mail::fake()` öffnet **gar keinen** Socket, und der
Mailpit-Skip (`MailTest.php:516`) entfällt. **Der Preis, der dabei in Kauf genommen wird:** die
gemessene Mailpit-Abhängigkeit der Suite wird unsichtbar, statt sichtbar fehlschlagend zu sein —
die beiden Tests, die 2026-09-29 ohne `MAIL_HOST=dind` rot wurden (500 statt 201/201), sind dann
grün, **weil nichts mehr versendet wird**, nicht weil sie faken.

---

## 📎 P6 — Wallet-Pass als Mail-Anhang + Datei-Validität (Nutzerentscheid 2026-10-02)

**Entscheidung (interaktiv geklärt 2026-10-02):** Der Pass wird **als Anhang der Freigabe-Mail** geliefert (Apple `.pkpass` **und** die Google-Datei), **zusätzlich** zum bestehenden Download-Endpunkt. Ein automatisierter **Import** in Apple/Google Wallet ist E2E **nicht** testbar — geprüft wird stattdessen die **Gültigkeit der erzeugten Datei**. Der Nutzer hat ausdrücklich die Variante „Mail-Anhang + Datei-Validität" gewählt.

**Ausgangslage, am Code gemessen (§3):** Die Pässe werden heute **nur** über GET-Download-Endpunkte geliefert — `WalletController`: `/api/applications/{id}/wallet` → `.pkpass` (`application/vnd.apple.pkpass`), `/wallet/google` → JSON/JWT, `/api/sub-applications/{id}/wallet` → `.pkpass`. Es gibt **zwei Freigabe-Wege mit je genau EINER Mail**: manuell → `PassMail` (`AdminApplicationController:169-171`, Betreff „Dein Akkreditierungs-Ausweis"), automatisch → `ApplicationApprovedMail` (`AllocationService:266/606`). **An keiner der beiden hängt der Pass.** `WalletPassService` baut beide Formate bereits (inkl. kontrollierter Degradation ohne Credentials); `WalletPassServiceTest`/`WalletTest` prüfen die Struktur heute schon.

**TODOs (Implementer ≠ Verifikator, §5; Tests nach §3 DoD):**
1. **Anhang:** `PassMail` **und** `ApplicationApprovedMail` hängen den Apple-`.pkpass` **und** die Google-Datei an — Dateiname/Content-Type wie im Download-Endpunkt (`accreditation-{id}.pkpass`), damit der Vertrag zwischen Download und Anhang **einer** bleibt.
2. **Datei-Validität als PHPUnit-Test (kein Import):** `.pkpass` ist ein gültiges ZIP mit `pass.json`/`icon.png`/`icon@2x.png`/`manifest.json`, die `manifest.json`-Hashes stimmen, **ohne** Certs enthält es **keine** `signature`; die Google-Seite ist strukturell valide (`EventTicketObject` bzw. `savetowallet`-JWT mit `typ`/`aud`).
3. **Kein Import-E2E.** Die E2E prüft die **Gültigkeit** der gelieferten Datei, nicht die Wallet-Installation.
4. **Edge cases:** fehlende Credentials (degradierter Unsigned-Pass/Preview), `park`/`seat` (Sub-Pass), nicht-`approved` (kein Pass).
5. **Verhältnis zur Mail-Zustellung** (Position 45, am 2026-10-04 per §4 entfernt; Vertrag in `features/mail-delivery.md`): beide Freigabe-Mails laufen über `MandantMailerService`; die Anhänge dürfen die Queue-/DLQ-Umstellung (Anhänge in `payload`/Store) **nicht** präjudizieren. *(Eingehalten auch für die Sub-Mails 2026-10-04: derselbe Dispatch-Pfad `send(mandant, mailable)`, Anhänge im Mailable-Payload wie bei `PassMail` — kein eigener Store, kein eigener Mechanismus.)*

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
| **17** | **[x] VERIFIZIERT 2026-09-29 — per Rubrik APPROVED (kein critical/high). Fixed mit Mutations-Nachweis (2500-ms-Delay: ohne Postcondition rot, mit grün; 400-ms-Zahl korrigiert — networkidle absorbiert). Gates: test:run 405, lint clean, build OK, E2E 140 passed/50 skipped/0 failed (**damaliger Stand**; der Nightly `36552912340` vom 2026-09-29 fährt inzwischen **142/52/0**), Screenshots 75 passed, Bänder 24/24/24, Gegenrichtung grün. E2E-%-Delta: nur users +15…+30 (gemessen, als die Konto-Löschung noch offen war; die inzwischen gelieferte DELETE-Route ist `ownership.ts:183`; die **Nachmessung** ist mit der Welle 5/6/43 **erledigt**: `admin-users` **2 Bänder bei 7 Usern** (2026-10-01)). M5 VOID (CACHE_STORE=array, kein persistenter Limiter). Follow-ups: **25** (26–29 sind per §4-Schnitt 2026-09-29 entfernt — alle vier verifiziert geschlossen).** Der Capture hat keine Daten-Postcondition — ein 400-ms-Request wird als gültiges Artefakt gespeichert** (high, 2026-09-28) | S1 | `ui-screenshots.spec.ts:173` via `helpers/session.ts:20-23`. **Der Verifikator hat die Ursache gemessen, nicht die Erklärung übernommen.** `waitForAppSettled` ist `networkidle` + **300 ms**; nach einem Klick ist `networkidle` **schon erfüllt** und kehrt sofort zurück — das 300-ms-Budget ist die **einzige** Reserve, und die Daten kommen nach **102–163 ms**. **Gemessene Reserve: 159–220 ms.** Erzeugt per Verzögerung von `/api/admin/users` um 400 ms, **Dataset unverändert**: `h=950, bands=0`, und der **grüne Lauf** speichert den **Lade-Spinner** unter der Überschrift. **§7 Abnahme 1 ist damit nicht unstabil, sondern unbewacht.** Und **strukturell asymmetrisch:** Desktop navigiert per Klick, Mobile per Dokument-Laden — dieselbe 400-ms-Verzögerung wird dort absorbiert. **Die Behauptung, das seien Daten, ist widerlegt: ein fester Datensatz kann keine 20-zeilige Liste verschwinden lassen.** |
| **21** | **[x] VERIFIZIERT 2026-09-29 — Diagnose korrigiert (M4, 2026-09-29): kein Gruppen-Defekt, sondern Zombie — PID 1 reapet im CI-Container nie, kill(pid,0) auf Zombies erfolgreich. Fix: STAT-Prüfung Z/X = nicht-ausführend (fail-closed) + Deszendenten-Sweep. **Die CI-Bestätigung, die hier früher ausstand, liegt seit 2026-09-29 vor: Run `36537851878`, WATCH-EXIT=0.** Der Sweep ist nach `frontend/tests/e2e/ownership-probe/run-child.ts` gewandert und wird von `run-child.test.ts` getestet. **Die Reaper-Hälfte darüber ist eine eigene Entscheidung (D26/D27) und steht in `features/05-e2e-test-image.md` — nicht in dieser Zeile.**** Ein verklemmter Kind-Lauf überlebt als Waisenkind und schreibt weiter in die DB** (medium, 2026-09-28) | S1 | `ownership.spec.ts:87-90` — `execFileSync` **ohne** `timeout`. Hängt der Kind-Run, stirbt der Elterntest an seinem 300-s-`setTimeout`, **der Kind-Prozess läuft weiter** und schreibt für den Rest der Suite in die Datenbank. **Gut:** `workers: 1`, `fullyParallel: false`, `retries: 0`, `timeout: 60000`, **kein Port**, `maxFailures` und `retries` des Elternlaufs **durchdringen nicht**. |
| **23** | **[x] VERIFIZIERT 2026-09-29 — fixed (Kind-Env-Pin, Mutation rot).** Der verschachtelte Lauf erbt die ganze `process.env` und damit die Messschalter selbst** (medium, 2026-09-28) | S1 | `ownership.spec.ts:90` reicht `{ ...process.env, CI: '' }` durch — der Kind-Lauf braucht nur `E2E_BASE_URL`. **Strukturell** erreicht `E2E_OWNERSHIP=off` auch das Kind, und dessen Teardown wäre neutriert, wodurch Assertion (3) des Treibers fiele. **Heute nur maskiert**, weil `e2e-per-spec-leaks.mjs:110` den Treiber per `--grep-invert` ausschliesst. **Die Gültigkeit eines Tests hängt damit an einem Schalter, den der Treiber nicht kontrolliert.** |
| **25** | **[x] VERIFIZIERT 2026-09-29 — Docblock + zurückgenommene Negativprobe (Diff auf namespace-isolation leer).** M1: admin-venue-Entscheidung steht nirgends im Repo (medium, 2026-09-29) | S1 | Verifikatorbefund zum Implementierer-Diff: `admin-venue.spec.ts:17-21` trägt leere Ledger-Hooks, UI-erzeugtes Team+Venue (+1/+1 je Lauf, gemessen 2026-09-29) ist reclaimbar aber unregistriert — korrekt nicht gemacht (Auftragserweiterung über das damalige Band 17–24 hinaus; 18–20 und 24 sind seither per §4-Schnitt entfernt), aber nirgends dokumentiert. Fix: ~5-Zeilen-Docblock; `admin-venue.spec.ts` in `UI_CREATE_SITES` aufzunehmen machte das Gate korrekt rot. Natürlicher nächster Kandidat für die Registrierung im **Namens-Lookup**, mit dem `admin-mandant.spec.ts` inzwischen räumt. |

**ENTSCHEIDUNG 2026-10-02 (interaktiv geklärt): Mail ist Zustellung, nicht Best-Effort.** Zwei Ebenen getrennt entschieden:

**1. Die Premisse dieser Zeile war zu scharf formuliert (Stand `7d2fe56`, seit `70aa03d` historisch).** `MandantMailerService::send()` verschluckte **nicht still**: der `catch (Throwable)` (`:73-79`) rief `Log::warning('Mandant mail dispatch failed')` mit `mandant_id`, Mailable-Klasse und Fehlertext. *(Seit `70aa03d` ist auch das Geschichte: `send()` dispatcht nur (`MandantMailerService.php:64-67`), der `catch (Throwable)` ist weg — belegt im Service-Docblock selbst. Die Warnung selbst ist nicht entfernt, sondern gewandert: `Log::warning('Mandant mail dispatch failed', …)` steht heute in `SendMandantMail::failed()` (`backend/app/Jobs/SendMandantMail.php:270`, DLQ-Hook) — ein offener Follow-up (Wächter) wäre dort zu schreiben, nicht in `send()`. „132 Sendungen werfen" ist seitdem nicht neu gemessen.)* Der Docblock nannte das ausdrücklich als Policy („a broken mail relay must never break the apply/approval flow") — das Zitat steht seit `70aa03d` in **keinem** Docblock mehr (Service-Docblock umgeschrieben; einziger Treffer repo-weit ist diese Zeile). **Was daran wirklich fehlte, ist ein Wächter, nicht das Logging** — und das Loch ist gewandert, nicht geschlossen: `MandantMailerTest:108` mit `assertTrue(true)` existiert nicht mehr (Test entfernt, nur historische Erwähnung im Klassen-Docblock `:24-25`); `grep "Mandant mail dispatch failed"` über `backend/tests/` liefert **0 Treffer** — kein Test pinnt die Dispatch-Failure-Warnung (`SendMandantMailTest:426` spied nur `Log::info`). Falsch waren also die **Zeugen**, nicht die Schlussfolgerung: ein Verhalten ohne Wächter. **„132 Sendungen werfen" ist seit `70aa03d` nicht neu gemessen** — es bleibt Messung an `7d2fe56`, nicht Gegenwart.

**2. Der Nutzer hat entschieden: Mail ist Zustellung.** Eine nicht zugestellte Freigabe/Absage darf **nicht** durch einen grünen Lauf gehen — `AllocationService:266/328/606/644` persistieren den Status und der Antrag erfährt nichts.

**Warum entkoppelt und nicht „hard fail, Status rollt zurück" (interaktiv entschieden):** die harte Kopplung wäre die stärkste Zusage — „approved heißt notifiziert" — und der kleinste Eingriff, aber sie **blockiert die Akkreditierung komplett**, sobald ein Mailserver ausfällt. Bei einer Frist-Plattform ist das ein schlimmerer Ausgang als die jetzige Lücke. Die Entkopplung trennt die beiden: der Status und der Zustellauftrag entstehen **gemeinsam**, die Zustellung passiert danach. Ergebnis: **approved impliziert „zugestellt oder nachweislich in Arbeit"**, und der Zustand ist auch dann korrekt, wenn SMTP gerade ausfällt.

**Umfang: ALLE Mandant-Mails, generell** (Nutzerentscheid 2026-10-02) — nicht nur die drei Freigabe-Sendungen. **Das löst den Einwand, der gegen den breiteren Umfang sprach, ausgerechnet auf:** mit einer Queue schreibt `SendReminders` einen Job, statt synchron zu werfen, also kann ein totes Relay den Cron **nicht** mehr abbrechen. Damit ist der Grund entfallen, der für die enge Variante gesprochen hätte, und zugleich ist das im Docblock dokumentierte Queue-Follow-up („Queue integration is a documented follow-up") **erledigt statt verschoben**.

**Was die Entscheidung kostet, ehrlich benannt:** ein `ShouldQueue`-Job pro Mailable mit `$tries`/`backoff()`, der Wechsel des `send()`-Vertrags, `after_commit`-Dispatch, die `mandant_id`-Spalte und die Admin-Oberfläche. **Und:** eine Zustellung, die „in Arbeit" bleibt, ohne jemanden, der sie sieht, ist dasselbe Problem eine Ebene tiefer — der Dead-Letter braucht deshalb eine **Sichtbarkeit**, sonst wird aus „stillschweigend verloren" nur „später sichtbar verloren".

### ♻️ KORREKTUR 2026-10-02: die geplante eigene Outbox-Tabelle ist **hinfällig** — Laravel Queues lösen es, und die Referenzimplementierung läuft bereits

**Diese Korrektur ist einer Frage des Nutzers geschuldet („haben wir das nicht im Portal mit einem run-Kommando gelöst?") und sie widerlegt meinen eigenen Plan, nicht eine Board-Zahl.** Geprüft gegen das Portal-Projekt, nicht aus dem Gedächtnis.

**1. Die Tabellen existieren bereits und liegen ungenutzt.** `0001_01_01_000002_create_jobs_table.php` legt `jobs` (`:14-22`, mit `attempts`, `available_at`, `reserved_at` — **Deckelung und Backoff sind eingebaut**) und `failed_jobs` (`:37-47`) an. **Null Schreiber, null Leser**, seit Projektbeginn. `config/queue.php:123-127` zeigt `failed` bereits auf `failed_jobs`. **Eine neue Tabelle ist nicht nötig** — mein Plan hätte eine zweite Wahrheit daneben gestellt.

**2. Der manuelle Requeue ist ein vorhandener Befehl.** `php artisan list` auf Laravel **13.33.0**: `queue:retry`, `queue:failed`, `queue:forget`, `queue:prune-failed`, `queue:work`. **Das ist die Dead-Letter-Queue samt manuellem Requeue** — der Nutzerentscheid wird von vorhandener Maschinerie bedient, nicht von einer neu gebauten.

**3. Worker und Scheduler sind Betrieb, nicht Code — und die Referenz hat die Antwort.** `portal.reisinger.pictures/deployment/backend-supervisor.sh` startet `queue:work --tries=3 --timeout=…` **und** `schedule:run` **alle 60 s**, endet mit `exec php-fpm -F` (`:99`). Sechs Details, deren Weglassen genau den Fehler wieder einführt, den wir vermeiden wollen:

| Zeile | Was | Warum das nicht optional ist |
|---|---|---|
| `:30-33` | `QUEUE_CONNECTION` muss `database` sein, sonst **Abbruch beim Start** | der Fehler wird **jetzt** laut, nicht Stunden später |
| `:35-38` | `DB_QUEUE_CONNECTION` == `DB_CONNECTION` | Queue und DB in derselben Transaktion — sonst ist `after_commit` bedeutungslos |
| `:40-47` | **`QUEUE_WORKER_TIMEOUT` < `DB_QUEUE_RETRY_AFTER`**, fail-closed | verhindert doppelte Reservierung eines hängenden Jobs — sonst erst unter Last sichtbar |
| `:52` | **stale PIDs beim Start löschen** | sonst lässt ein Container-Neustart eine alte PID gesund aussehen, während der neue Supervisor startet |
| `:54-75` | Worker in **Restart-Schleife** mit PID-Marker | ein toter Worker darf die Zustellung nicht stillstehen lassen |
| `:77-85` | `schedule:run` im **60-s-Takt**, Fehler geloggt, Schleife läuft weiter | ein fehlgeschlagener Lauf darf den nächsten nicht verhindern |

**Und zwei Dinge, die über „Skript starten" hinausgehen und mitkopiert werden müssen:**
- **Der Healthcheck verlangt FPM + Supervisor + Worker + Scheduler** als laufend. **Ein toter Worker macht den Container ungesund**, während der Supervisor ihn neu startet. Ohne das wäre ein stiller Zustellungsstillstand ein „gesundes" Deployment.
- **Fail-closed vor jedem Laravel-Kommando:** Migration/Seed/Admin laufen `… || exit 1`; ein Fehler dort verhindert Worker, Scheduler **und** FPM. Reihenfolge: **Compose-Start → Gate → Supervisor → FPM** (`01-deployment.md:50`).

**Warnung, die wir mitnehmen** (`29-production-operations-runbook.md:217`): dort steht ausdrücklich, man müsse auf das Skript **zurück**, sobald jemand „a compose file that starts a naked background `queue:work`" vorschlägt — **genau die Falle, in die mein Plan getappt wäre.** Dieselbe Datei hält fest, dass Binaries **im Image nachweisen** kein Live-Nachweis ist: *„an actual start of the stack remains an external release/operational step."*

**Was von meinem Plan damit ersatzlos wegfällt:** eigene Outbox-Tabelle · eigener Zustandsautomat (`pending → dead`) · eigene Retry-Policy mit Backoff · `max_attempts`/`attempts`-Zähler · eigener Scheduler-Deploy · Resend-Fallback-Zeilen. **`--tries=5` und `backoff()` machen das bereits** — und die 5 ist **unsere** Zahl (angeglichen an den
Job-Deckel `SendMandantMail::$tries`, siehe `deployment/backend-supervisor.sh`), nicht die `--tries=3`
der Portal-Referenz oben.

**Was blieb — drei Dinge, alle begründet, keines davon eine Queue (Analyse-Stand 2026-10-02; alle drei seitdem umgesetzt, hier historisch plus Erledigungs-Zeiger — gemessen am HEAD, nicht übernommen):**
1. **`mandant_id` auf `failed_jobs`.** Die Spalte fehlte (`uuid, connection, queue, payload, exception, failed_at`), und die Mandant-Zuordnung aus dem `payload`-Blob zu gewinnen wäre zerbrechlich. *(Erledigt: Migration `2026_10_02_180000_add_mandant_id_to_failed_jobs_table.php` (guarded, nullable + Index), Test `FailedJobsMandantIdMigrationTest`.)*
2. **UI + Autorisierung für `dead` und Requeue** (Super Admin global, `mandant_admin` nur eigener Mandant). `queue:retry` war ein **Shell-Befehl für Leute mit Deploy-Zugriff** — die Nutzerentscheidung nannte die App. *(Erledigt: `GET /api/admin/failed-mails` + `POST …/requeue` (`routes/api.php:405-407`, Gate `mails.dlq.manage`), `FailedMailController`, Frontend `FailedMailsPage.tsx` als Route `tote-briefe` — Strom B, `11e64fc`.)*
3. **`after_commit => true`** — **das ist die eine Zeile, die „Status und Zustellung in einer Transaktion" ausdrückt.** *(Erledigt: `'after_commit' => true` in `config/queue.php:57` (`:44` ist heute eine Kommentarzeile).)* Sie schließt zugleich die akzeptierte Lücke **A5**: `AllocationService.php:568-578` sagt wörtlich, sie brauche „an outbox table written INSIDE the transaction and dispatched after it" — mit `after_commit` ist der Kommentar **überholt, und zwar in die gute Richtung.**

**Übernommen wird mit Namensanpassung, nicht erfunden** — die PID-Pfade `portal-queue-*` kollidieren sonst mit einer Portal-Installation auf demselben Host.

### 🔒 DIE ENTSCHEIDUNG, die die Schleife beantwortet: Dead-Letter-Queue mit manuellem Requeue (Nutzerentscheid 2026-10-02)

**„No mail should be lost" ist die Zusage, und sie beantwortet die Loop-Frage strukturell statt per Konvention.**

Der Entwurf, den Position 45 offenließ, war: retry, bis es irgendwann klappt. **Das ist die Endlosschleife** — nicht als Bug, sondern als gewählte Regel, und sie ist in diesem Code erreichbar, weil ein **wiederkehrender Produzent** existiert: `SendReminders` schreibt bei jedem Schedule-Lauf erneut Zustellaufträge. Ein dauerhaft totes Relay erzeugt dann nicht einen Endlos-Retry, sondern **unbegrenzt neue Aufträge** für dieselbe logische Mail. Retries zu erhöhen wäre die falsche Richtung — das beschleunigt nur die Schleife.

**Die Regel, die das auflöst: Retries sind gedeckelt, und der Endzustand ist ein toter Brief, aus dem nur ein Mensch herausbewegt.**

| Zustand | Wer bewegt ihn | Bedingung |
|---|---|---|
| `pending` → Versuch | Worker | automatisch |
| `pending` → `pending` | Worker | **nur** solange `attempts < max_attempts` |
| `pending` → **`dead`** | Worker | `attempts` erschöpft — **terminal**, kein automatischer Weg zurück |
| `dead` → `pending` | **Mensch, manuell** | `mandant_admin` (nur eigener Mandant), `super_admin` (alle) |

**Der Endzustand ist terminal, und das ist der ganze Schutz.** Es gibt keinen Code-Pfad, der einen `dead`-Eintrag wieder in eine Schleife setzt — der einzige Ausgang ist der Admin-Aufruf. Die Endlosschleife ist damit nicht „begrenzt konfiguriert", sondern **strukturell unmöglich**, unabhängig von Mailanzahl und Retryzahl.

**Requeue setzt `attempts` nicht blind zurück, sonst ist die Schleife nur versteckt:** beim manuellen Requeue wird gezählt und protokolliert (`requeued_count`, letzter Requeue durch wen/wann), und der Eintrag bekommt ein **frisches** Backoff-Budget. Sonst könnte jemand einen `dead`-Eintrag in einer engen Schleife manuell anstoßen, ohne dass die Historie sichtbar wird.

**Und der Teil, der die Zusage erst scharf macht:** eine Dead-Letter-Queue ohne Oberfläche ist kein Verlustschutz, sondern ein **verschobener** Verlust. Damit „no mail should be lost" gilt, muss der tote Brief **gesehen** werden können — die Admin-Ansicht ist nicht Beiwerk dieses Features, sondern seine halbe Begründung.

**Mandanten-Isolation ist hier Pflicht, nicht Zusatz (Mehr-Mandanten-System, Security-Review des Verifikators — Skill `build-verify`, agents-skills):** ein `mandant_admin` darf **ausschließlich** die toten Brief **seines** Mandanten sehen und requeuen. Ein global sichtbares Postfach wäre ein Cross-Mandant-Leak über den Umweg einer Fehlerliste — und Fehlerlisten enthalten Empfängeradressen, also genau die Information, die das Review trennt.

### 🔬 Gemessener Boden vor dem Bauen — was überhaupt existiert (Analyse 2026-10-02)

> **Dieser Abschnitt ist der Zustand vom 2026-10-02, nicht der heutige — und er steht trotzdem hier, weil die Liste selbst der Fund ist.** Fast jede Zelle ist inzwischen **falsch**: Es gibt eine Queue, `app/Jobs/` existiert, `dispatch()`/`ShouldQueue` haben Treffer, `after_commit` steht auf **`true`**, `send()` ist ein Dispatch mit Fehlerpfad, und `queue:work`/`schedule:run` laufen über `deployment/backend-supervisor.sh` (Prod) und `scripts/dev-worker.sh` (Dev) — **nicht** im E2E-/CI-Stack, und das ist eine benannte Entscheidung. **Dauerhaft gültig ist die Form des Befunds, nicht sein Inhalt:** die Liste entstand, indem `grep` über **die damals bekannten Dateien** lief, und `portal.reisinger.pictures` stand nicht darin — genau dort lag das fertige Supervisor-Skript. **Eine Grep-Liste, die man sich selbst zusammstellt, findet immer nur, was man schon für möglich gehalten hat** (dieselbe Lehre in `backend/AGENTS.md`). Der heutige Vertrag steht in `features/mail-delivery.md`.

**Es gibt keine Queue. Nicht „wenig Queue" — keine.** Das ist der Befund, der die ganze Position 45 in eine andere Kategorie hebt.

| Gesucht | Ergebnis |
|---|---|
| `dispatch(` / `ShouldQueue` / `Bus::` / `Queue::` in `app/`, `routes/`, `bootstrap/`, `database/` | **null Treffer** |
| `app/Jobs/`, `app/Listeners/`, `app/Events/`, `app/Notifications/` | **existieren nicht** |
| `$tries` / `backoff()` / `retryUntil()` / `maxExceptions` | **null** (die 6 Treffer sind Prosa in `MediaPurgeRunner` und einem Test) |
| `Queue::failing()` / `Queue::failed()` / `EventServiceProvider` | **existieren nicht** |
| `queue:work` / `schedule:work` / `schedule:run` / supervisor / crontab | **null** in compose, `entrypoint.sh`, `Dockerfile`, CI, Prod-Plan |
| `sent_at` / Zustellstatus / Idempotenzschlüssel | **existiert nicht** — die einzigen `*_at`-Spalten sind `email_verified_at`, `activation_token_expires_at`, `failed_jobs.failed_at` |
| `jobs`-Tabelle | existiert (`0001_01_01_000002`, `:14-22`) mit **null Schreibern und null Lesern** |

`config/queue.php` ist vorhanden und **inert**: `:16` Default `database`, `:43` `retry_after` 90 s, `:44` `after_commit` **false**, `:123-127` `failed` → `failed_jobs`. **`phpunit.xml:117` pinnt `QUEUE_CONNECTION=sync`** — die Suite sähe einen Worker nie.

**Und das Entscheidende: `send()` gibt `void` zurück.** Kein Aufrufer kann Erfolg von Misserfolg unterscheiden — `AllocationService`, `SendReminders` und der Controller behandeln den Aufruf als Fire-and-forget. **Eine Queue lässt sich auf diesem Vertrag nicht legen**, ohne ihn zu ändern; das ist eine Voraussetzung, kein Detail.

**Der Swallow ist außerdem von drei Tests festgenagelt** — das ist die Form, die die Umsetzung angreift: `AllocationAtomicityTest.php:85-87` sagt ausdrücklich „a broken `MandantMailerService::send()` must never abort the loop", `MandantMailerTest.php:107-126` endet in `assertTrue(true)`, `SchemaHardeningTest.php:328-341` pinnt die `DecryptException`→null-Relay-Degradation. **Drei Tests behaupten die alte Policy.** Sie werden umgeschrieben, nicht umgangen — und jede Umschreibung ist eine bewusste Policy-Änderung, die im Commit-Message stehen muss.

#### Reihenfolge, interaktiv entschieden 2026-10-02: **kompletter Durchstich**

Worker · Scheduler (`schedule:run` für `allocation:run` **und** `reminders:send`) · `after_commit` · DLQ auf `failed_jobs` · Admin-Oberfläche — **zusammen, in einem Zug**. Die Alternative „erst die Mechanik, dann der Betrieb" ist **streng schlechter als der heutige Zustand**: die Tabelle würde in Transaktionen geschrieben und von niemandem geleert, während heute wenigstens `Log::warning` den Fehler aufzeichnet. **Eine Queue ohne Worker ist kein Verlustschutz, sondern verschobener Verlust in eine Tabelle, die niemand liest.**

**Migration-Policy (`backend/AGENTS.md`), am Repo geprüft: vor dem ersten Prod-Deploy** — die Go-Live-Phasen A/B/C sind vollständig ungeprüft. Also **bestehende Migration erweitern, keine neue Versionsnummer**: `0001_01_01_000002_create_jobs_table.php` ist der natürliche Ort (Queue-Tabellen leben dort zusammen). **Savepoint-Regel (§2) beachten:** ein Job-Dispatch innerhalb einer Transaktion, die danach fehlschlagen kann, braucht `after_commit` — **nicht** eine eigene Tabelle mit Savepoint-Logik. Genau dafür ist `after_commit` da.

#### Bestehende Idiome, die der Queue **wiederverwenden** soll statt sie neu zu erfinden

`MediaPurgeRunner` ist die einzige Retry-Implementierung im Repo und **keine** Queue — sie ist die Antwort des Codebase auf „wie verhindert man eine Schleife", synchron und im Request: `:56` `PURGE_ATTEMPTS = 3` · `:63` Backoff × Versuchsnummer · `:77` **aggregates** Budget `PURGE_TOTAL_BUDGET_SECONDS = 30` · `:26-29` **nur** `MediaRemovalFailedException` wird wiederholt, ein `QueryException` nicht · `:30-32` ein Fehler bricht nie den Rest ab. **Vier Teile, die 1:1 auf die DLQ passen:** gedeckelte Versuche · Backoff · Ausnahme-Typ-Filter statt `Throwable` · Arbeit isoliert fortsetzen.

Weitere: **Guard-Write mit wiederholter Vorbedingung** (`AllocationRules::markStatus():195-209` — `where('status','requested')` → der zweite Lauf findet 0) ist der einzige vorhandene „genau einmal"-Mechanismus; **deterministisch idempotente Berechnung** (`QrTokenService`, früher Return ohne Write); **Cache-Marker mit TTL** (`SendReminders:78`); **17 Composite-Unique-Constraints** als „kann nicht zweimal passieren"-Primitive; **`withoutOverlapping()`** als prozessübergreifende Sperre.

#### Sechs Produktfragen, die die Analyse offen lässt (keine Codefragen)

1. **`AuthController.php:127` (`ActivationMail`)** umgeht `MandantMailerService` komplett — Default-Mailer, **innerhalb** der Registrierungs-Transaktion (`:98-135`), **wirft** bei Fehler. „ALLE Mandant-Mails" (Nutzerentscheid) liest beide Richtungen. Es ist der einzige Pfad, dessen Scheitern heute eine sichtbare `201` kippt — seine Semantik ist eine andere als die der übrigen sechs.
2. **`resend`** (`AdminApplicationController:155-193`): dispatcht es einen Job oder umgeht er die Queue? Heute ohne Cap, ohne Marker, ohne Rate-Limit — und die manuelle `dead → pending`-Requeue wäre ein **zweiter, überlappender** Ausstieg für dieselbe Sache.
3. **Erinnerung:** einmal pro Tag über 4 Tage (heutige Implementierung, `:19-24`) oder einmal pro Fenster? Der Docblock nennt das Letztere selbst als Follow-up (`reminder_sent_at`) — **der Idempotenzschlüssel des Jobs ist der Ort, an dem das entschieden wird**, also vor dem Key-Design.
4. **Aufbewahrung** der `dead`-Briefe: unbegrenzt? `AGENTS.md` §10-A5 mahnt für das Anwendungslog bereits die harte `LOG_DAILY_FILES`-Grenze.
5. **Deckt „no mail lost" auch das ab, was nie *versucht* wurde?** Ein Request, der vor dem Mailer mit 500 abbricht (Registrierung), hinterlässt heute keine Spur.
6. **Idempotenz gegen Worker-Crash-vor-Ack:** heute gibt es keinen Zustand, den man befragen könnte. Das ist die **einzige** Form doppelter Zustellung, die eine Queue *neu einführt* — sie muss also mit born, nicht nachträglich.

**NUTZERENTSCHEIDUNGEN 2026-10-02 (interaktiv, beantworten die offenen Punkte):**

1. **`ActivationMail` läuft AUCH über die Queue.** Konsequentes „ALLE Mandant-Mails": die Registrierung antwortet **201** auch dann, wenn die Aktivierungs-Mail noch nicht raus ist — die Zustellung wird nachgeholt. Der bisherige **werfende** Pfad innerhalb der Registrierungs-Transaktion (`AuthController:127`) entfällt damit **bewusst**; seine Sondersemantik ist damit aufgegeben, nicht vergessen.
2. **Erinnerung: Laravel-Scheduler, KEINE OS-Crontab.** Der Scheduler **erzeugt** den Zustellauftrag (`reminders:send`, täglich registriert in `routes/console.php`), der **Standard-Worker** arbeitet ihn ab. Ausgeführt wird `schedule:run` im **60-s-Takt** durch das Supervisor-Skript (`backend-supervisor.sh` aus `portal.reisinger.pictures`: Neustart-Schleife, `queue:work` daneben, Healthcheck verlangt FPM + Supervisor + Worker + **Scheduler** als laufend). Die Frage „echter cron oder Laravel-Fähigkeit" ist damit **entschieden: Laravel**. Der per-Tag-Rhythmus bleibt (der Scheduler läuft einmal täglich); ein `reminder_sent_at`-Key ist dafür **nicht** nötig.
3. **Der manuelle Admin-Resend läuft über die Queue.** Der Nutzer beschreibt ihn als „quasi aus der Dead-Letter-Queue in die normale Queue verschieben" — derselbe Zustellpfad wie die Freigabe, die DLQ greift auch hier.
4. **Tote Briefe: UNBEGRENZT.** Keine automatische Löschung; sie bleiben, bis ein Mensch sie requeut oder entfernt — `queue:prune-failed` wird **nicht** eingerichtet. (Verhält sich damit **anders** als die Log-Grenze aus §10-A5, und das ist die Entscheidung, kein Versehen.)
5. **Technisch, nicht Produkt:** der Job braucht eine **Idempotenz-Wache gegen Worker-Crash-vor-Ack** (Guard-Write wie `AllocationRules::markStatus():195-209` — `where(...)` + 0 Treffer beim zweiten Lauf), sonst führt die Queue genau diese Doppelzustellung *neu* ein. Das ist die eine Zusage, die „mit born" muss, nicht nachträglich.
6. **„No mail lost" beginnt beim Auftrag, nicht beim Versuch:** mit der Queue entsteht der Zustellauftrag **in derselben Transaktion** wie der Statuswechsel (`after_commit`), also hat auch der Fall „Request bricht vor dem Mailer ab (500)" eine Spur — die Frage 5 ist damit **strukturell** beantwortet, nicht per Konvention.


**⚠️ Diese Zahlen sind am 2026-10-01 widerlegt und durften eine Entscheidung tragen — hier steht, was wirklich gemessen wurde.** Der Implementierer von Welle 1 Strom A hat bei **identischer Methode** (volle Suite, nur `backend/.env` variiert, Repo unverändert, `APP_URL=https://accreditation.test`) neu gemessen:

`TRUSTED_HOSTS` **5** · `APP_PREVIOUS_KEYS` **35** · `REQUIRE_ORIGIN_HEADER` **3** · `TRUSTED_PROXIES` **3** · `JWT_CROSS_SITE_COOKIE` **5** · `SESSION_SECURE_COOKIE` **1** · `SESSION_ENCRYPT` **5** · `MEDIA_ROOT` **1** · `WALLET_PASS_TYPE_ID` **1** · `MANDANTS_FALLBACK_MANDANT` **0–2**

**Summe 56, nicht 26** (59, wenn der Fallback doppelt gezählt wird) — über **10** Keys, nicht 9. Der größte Einzelposten ist `APP_PREVIOUS_KEYS` mit **35 statt 5**, und er ist **kein** „ein Key bricht die Keyrotation": Laravel wirft `RuntimeException: Unsupported cipher or incorrect key length` für **jeden** Wert, den `parseKey()` nicht parsen kann; es strippt ein `base64:`-Präfix, also kosten rohes `base64_encode()` (44 Zeichen) und `base64:`+30 Bytes **je 35**, `base64:`+32 Bytes dagegen **0**. `MANDANTS_FALLBACK_MANDANT` beisst nur bei kollidierendem Slug (`hauptverband` → 0, `verband-a` → 2). Auch die Grundgesamtheit stimmt nicht: vollständig geparst **162** Keys gelesen, 21 gepinnt, **147** ungepinnt — die „167" sind nicht reproduzierbar.

**Warum das mehr als ein Zahlendreher ist:** die 26 sind in die **Nutzerentscheidung** eingegangen („Schließt die gemessene Exposition (26 Fehlschläge)"), und die Aussage hinter ihnen — „der Eingriff ist klein" — ist daran nicht falsch geworden, aber die **Größe** dessen, was geschlossen wird, war um mehr als das Doppelte zu niedrig angesetzt. §3 verlangt, eine Position gegen das zu prüfen, was das Verfahren **erzeugt**; hier hat eine Zahl aus einem Baum, den es nicht mehr gab, zwei Runden lang eine Entscheidung getragen, und niemand hat sie nachgezogen. **Ungepflegt geblieben und mitentschieden:** `phpunit.xml` zeigt weiter auf `127.0.0.1:1025`, wo nichts lauscht — der nächste Test ohne `Mail::fake()` baut die Abhängigkeit neu auf. Strukturell wäre `MAIL_MAILER=array`, das berührt `MandantMailerTest` und ist **Design, nicht Reparatur**. **Nicht** der Zweck, alle 146 zu pinnen — der Zweck ist, die gemessene Zahl **nicht** an eine Config-Datei zu delegieren. **ENTSCHEIDUNG 2026-10-01, UMGESETZT in `7d2fe56`:** die **10 gemessenen** Keys pinnen, nicht alle 147 — `TRUSTED_HOSTS`, `APP_PREVIOUS_KEYS`, `REQUIRE_ORIGIN_HEADER`, `TRUSTED_PROXIES`, `JWT_CROSS_SITE_COOKIE`, `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, `MEDIA_ROOT`, `MANDANTS_FALLBACK_MANDANT`, `WALLET_PASS_TYPE_ID`. Schließt die **gemessene** Exposition (56 Fehlschläge, siehe Korrektur oben) mit kleinem Eingriff; die übrigen **147** bleiben ungepinnt und das steht als Absicht im `phpunit.xml`-Kommentar, nicht als Rest. Suite **1732 → 1741 passed / 0 failed**, 1 Skip → 0 Skips. Jeder der 10 Keys mit feindlichem `.env`-Wert danach weiterhin **1741 / 0**. **Widerspruch in der Entscheidung selbst, am 2026-10-01 behoben:** sie sprach von „9 gemessenen Keys" und nannte **10** — Absicht eindeutig, Arithmetik falsch; gepinnt wurde, was genannt war.

**`MAIL_MAILER=array` — ebenfalls umgesetzt (`7d2fe56`), mit einer Einschränkung, die die Begründung dieser Zeile relativiert.** Die Zusage war: ein vergessenes `Mail::fake()` könne **gar keinen** Socket öffnen. **Gemessen (Stand `7d2fe56`): das galt nur für den Default-Mailer.** `MandantMailerService` benannte `Mail::mailer('smtp')` explizit (`:54`) und umging den Pin damit vollständig — über die volle Suite **132** Sendungen auf diesem Weg, **jede** mit `TransportException` auf `127.0.0.1:1025`, **alle** von `send()` per `catch (Throwable)` geschluckt (Verteilung: `AllocationTest` 62, `MandantMailerTest` 25, `AllocationQrTokenQueryTest` 16, `AllocationAtomicityTest` 15, `AllocationQrTokenUpgradeTest` 8, `SubAccreditationRevocationTest` 6). *(Seit `70aa03d` historisch: `send()` dispatcht nur (`MandantMailerService.php:64-67`); der explizite `smtp`-Aufruf lebt in `deliver():79` und wirft dort — Retry → DLQ — statt geschluckt zu werden. Die 132 sind nicht neu gemessen.)* `array` schließt also den Weg, der **sichtbar** scheitern konnte (die zwei 500er) — und **nicht** die anderen.

**Das war ein neuer Befund, kein Rest dieser Position (Stand `7d2fe56`):** ein Mailer, der **damals** 132 Exceptions pro Suite-Lauf verschluckte — ein undokumentierter Fehlerpfad, den der Pin nicht adressierte. Festgehalten in `phpunit.xml:262` (Kommentarblock, historisch gescoped) und im Test-Docblock, in **beiden** Richtungen behauptet — `test_the_suite_default_mailer_cannot_open_a_socket()` wird rot, sobald jemand den Service ändert (`EsmtpTransport`-Assertion `PHPUnitEnvPinningTest.php:502-508`, gemessen Runde 16), statt den Docblock still falsch zu lassen. **Er wurde Position 45 (Batch-Tabelle, Zeile am 2026-10-04 per §4 entfernt); die Umsetzung ist `70aa03d` — der Contract steht in `features/mail-delivery.md`.**

### 🕳️ Benannter Rest, gefunden am 2026-10-03: der Idempotenz-Claim ist im **Dev-Stack** prozesslokal

**Der Wächter aus `8950f68` schützt Prod und bricht dort fail-closed ab, wenn `CACHE_STORE` kein **geteilter** Store ist — im dokumentierten Dev-/E2E-Stack greift er nicht, und der Store ist dort `array`.**

`backend-supervisor.sh` verweigert `array`/`file`/`storage`/`null` (durch `QueueSupervisorCacheStoreGuardTest` gepinnt, 10 Tests). `scripts/e2e-up.sh` **setzt `CACHE_STORE=array` unbedingt**, und `scripts/dev-worker.sh` startet daneben einen **echten** `queue:work`. Zwei Worker in zwei Prozessen mit je eigenem `array`-Store: **beide** `Cache::add($key, …)` gelingen, **beide** senden, **keine** `failed_jobs`-Zeile, **keine** Logzeile. Das ist genau das **stille** Scheitern, gegen das die Zusage „jede verhinderte Doppelzustellung ist sichtbar" gebaut wurde — im Dev-Stack ist sie faktisch unwirksam.

**Warum der Prod-Guard nicht einfach kopiert wird:** er würde den dokumentierten Dev-Stack **sofort abbrechen**, und ein fail-closed im Werkzeugkasten ist schlimmer als keiner. Der Weg ist ein **nicht-fataler Hinweis beim Start**, der den Zustand benennt statt ihn zu verschweigen.

**Bleibende Lücke, die kein Hinweis schließt:** Der **Dev-Stack hat einen einzigen Worker**, und `Cache::add` mit `array` ist prozesslokal — die Doppelzustellung braucht **zwei gleichzeitig laufende Worker**. Solange der Dev-Stack **einen** Worker fährt, ist das praktisch unerreichbar, und deshalb ist es ein Rest und kein Defekt. **Sobald jemand lokal einen zweiten Worker startet, ist es ein Defekt**, und genau dann warnt der Hinweis zu spät. Das ist die Form, in der ein „nur lokal"-Problem in Prod zurückkehrt.

**Ehrliche Einordnung:** Für einen **Entwickler-Stack** ist die Doppelzustellung zweier Worker ein akzeptiertes Risiko — Mailpit fängt die Zustellung ab, es geht nichts an einen echten Empfänger. Für **Prod** wäre dieselbe Konstellation unakzeptabel, und genau deshalb steht dort der fail-closed Wächter. **Der Rest ist die Lücke zwischen beiden, nicht die Konstellation selbst.**

### ⏳ Offener Rest aus **Strom B der Mail-Zustellung** (DLQ-Oberfläche): **Paginierung** der Dead-Letter-Liste (bewusst nicht gebaut)

**Hierher umgezogen beim §4-Schnitt vom 2026-10-04, weil es die einzige echte offene Aufgabe des Storms war** — Strom B selbst ist geschlossen und verifiziert. **Nicht entfernt**, nur an seinen Platz: es war TODO 6 und steht jetzt hier, plus dauerhaft in `features/mail-delivery.md §8`. *(Namenskollision, ausdrücklich: die Wellen-Tabelle oben benutzt „Strom A/B" für **Welle 1–3**, das sind andere Paare als die drei Ströme A/B/C der Mail-Zustellung.)*

`FailedMailController::index()` nimmt **keine** Parameter und macht ein `->get()` über die **ganze** `failed_jobs`-Tabelle, filtert danach in PHP. **Zwei Gründe, warum das jetzt schmerzt:** die Tabelle wächst per Entscheidung **unbegrenzt** (kein `queue:prune-failed`, `features/mail-delivery.md §6`), und die Abfragen sind **mandant-skaliert** — ein Verband mit viel Zustellung lädt die Briefe aller. **Die Form ist benannt** und nicht erfunden: `per_page`/`cursor` mit Resource-Collection-Metadaten.

**Der UI-Rest, der ohne Backend-Fix nicht weggeht:** `filterFailedMails()` filtert **clientseitig**, weil der Endpunkt nicht filtern kann — die Seite sagt das laut im Klartext und stellt die Liste nicht als „die Wahrheit" dar. Das ist eine **bewusste Form**, kein Versehen: ein Filter über alles, was der Server geschickt hat, ist nur so vollständig wie dessen Antwort.

### Nicht in diesem Batch
- **Wallet-Install (Apple/Google) — externer Issuer (Position 14, umgeschrieben 2026-10-02): aktuell NICHT geplant.** Der Lieferweg ist die **erzeugte Datei als Mail-Anhang** (scannbares Dokument); die inhaltliche Gültigkeit geht gegen **uns** (QR → Verify-Endpoint). Der **GAP** zur *installierbaren* Wallet — Apple verlangt ein von Apple ausgestelltes Pass-Type-ID-Zertifikat (iOS prüft die PKCS#7-Signatur gegen Apples WWDR-Kette), Google einen Issuer-Account + Service Account (`{issuerId}.{classId}.{objectId}`) — ist eine **bewusste Zusage-Grenze**, kein offener Task. Dauerfassung in `features/wallet-pkpass.md`.
- **Go-Live** (~20 Positionen: Pre-Prod-Domain, DNS, Caddy, Secrets, Deploy, Backup,
  Prod-Smoke) — liegt per Anweisung unten.
- **`P5-F4` Queue-Integration** — braucht Queue-Worker, ist Go-Live-Infrastruktur. **Seit 2026-10-02 entschieden und in Position 45 aufgegangen** (die am 2026-10-04 per §4 entfernt ist, der Vertrag steht in `features/mail-delivery.md`): Laravel Queues statt Eigenbau, `jobs`/`failed_jobs` existieren bereits, und die Betriebsseite (`backend-supervisor.sh` mit `queue:work` + `schedule:run`) wird aus `portal.reisinger.pictures` **übernommen**, nicht erfunden.

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
| D22 | Badge-Hintergrund (2026-09-27, **Stand: weiß** — Nutzerentscheidung 2026-09-28, implementiert + gepinnt) | Weiß auf dem Karten-Wurzelcontainer (`BadgeRenderService.php:305`, `.card`-div mit `PAGE_BACKGROUND_STYLE` — nicht `body`/`@page`: die Variante wäre einfacher gewesen, wurde aber zurückgestellt, siehe `features/badges-qr.md:743`). Belegt: `features/badges-qr.md:277` (SOLL), `BadgePageBackgroundTest` (grün). *(Korrigiert 2026-10-04: eine Board-Zeile behauptete fälschlich „transparent bleibt" — meine D22-Frage an den Nutzer war invertiert gestellt, die Antwort stand auf falscher Grundlage; nach korrekt gestellter Frage: weiß bleibt.)* |
| D23 | **E2E-Fixture-Besitz** (2026-09-28) | **Jeder E2E-Test registriert, was er angelegt hat, und löscht es selbst — auch wenn er halb scheitert.** Drei Fixtures erstellt, das vierte wirft: die drei müssen weg. **Kein Test hinterlässt eine `E2E %`-Zeile.** Ein Sweep über Namensmarker ist **Übergang**, nie Modell. Referenzimplementierung: `portal.reisinger.pictures`. |
| D25 | **Entscheidungen nie in `AGENTS.md`** (2026-09-29) | Agenten-Entscheidungen (D-Reihe) gehören **ausschließlich** in `AGENTS.todo.md`. `AGENTS.md` bleibt frei von Entscheidungs-Einträgen — die D24-Zeile in §5 wurde noch am selben Tag zurückgenommen. **Gilt nur künftig:** ältere Einträge (A-Register, D-Verweise in §7) bleiben stehen (entschieden 2026-09-29). |
| D26 | **Keine Prozesslecks** (2026-09-29) | Nutzerentscheid **2026-09-29**, wörtlich: **„no process leaks please."** (damals als Board-Position 32(b) geführt — die Position ist mit Welle C per §4-Durchgang 2026-09-29 entfernt, diese Entscheidung lebt als **D26** weiter). Ein verwaistes Kind muss **wirklich verschwinden**, nicht bloss nicht-ausführbar sein — also **strikte Lesart** statt Test-Toleranz. **Was gebaut wurde (`2e9ea93`):** `options: --init` am **e2e-Job-Container** in `ci.yml` — Docker-Daemon-eigenes `docker-init` (tini) als PID 1. **Bewusst KEIN `ENTRYPOINT` im Image:** bei einem Job-Container bestimmt der *Runner* PID 1, nicht das Image; ein `ENTRYPOINT`, den der Runner ersetzt, wäre eine Zeile ohne Aussage über das laufende System. **Stand der Erkenntnis — zwei Hälften, und das ist nicht dasselbe:** (a) **outcome-proven** — `docker create --init` → `PID1 comm=docker-init`, Waisenkind **GONE**; ohne → `state=Z` (gemessen, Docker 29.8.1); (b) **mechanism-declared** — dass GitHub `options:` für einen Job-Container auswertet, steht in der Syntax-Referenz (nur `--network`/`--entrypoint` ausgeschlossen), im Runner-Quellcode **nicht** nachlesbar. **Nur ein echter CI-Lauf schliesst (b).** Die bestehenden STAT-Prädikate (`isExecuting`, `isZombieState`) **bleiben**: ein `Z` ist jetzt die **Abwesenheitskontrolle** für den Reaper, nicht sein Ersatz. **Nicht zu verwechseln** mit „Toleranz abbauen": die Entscheidung **verschärft** die Zusage. Begründung: §7 „Fixture-Besitz" und die ganze Board-Kette lehren, dass ein grüner Lauf, der geleckt hat, eine Lüge ist — dasselbe gilt für Prozesse. |
| D27 | **Bekanntes Rot: `child-lifetime.spec.ts` ist ohne Reaper absichtlich rot** (medium, 2026-09-29) | Aus D26 folgt eine **dauerhaft rote** Teststelle auf jedem Host ohne PID-1-Reaper — u. a. in **jedem** Entwickler-Container, in diesem Sandbox likewise (gemessen: PID 1 = `opencode`, Waisenkind `Z`, `ppid=1`). **Das ist fail-closed und damit richtig** (§3: ein rotes Gate ist besser als eine grüne Lüge), **aber es muss bekannt sein** — §3/§4 verlangen, dass eine wissentlich rote Standard-Suite-Stelle als akzeptiertes Risiko festgehalten wird, sonst begegnet sie dem Nächsten unvorhergesagt. **Zweite, unbequemere Folge:** der **CI-Push-Gate** ruht jetzt vollständig auf der **unbewiesenen** Hälfte (b) von D26 — wäre `--init` dort inert, geht der **`e2e`-Job** bei jedem Push rot (nicht alle vier Gates; die anderen drei bleiben grün). Auch das die richtige Richtung, aber ein selbst zugefügtes Rot, das nichts vorhersagt. **Dauerhaft festgehalten in `features/05-e2e-test-image.md`** („Reaper als PID 1 — und der daraus folgende bekannte Rote") — §3 verlangt ein wissentlich roter Testbereich als akzeptiertes Risiko in `features/`, und §4 schneidet **diese** Datei weg, eine Notiz nur hier wäre bei jedem §4-Durchgang still verschwunden. **Grenze, die bleibt:** der Test ist grün, sobald **irgendein** Reaper existiert — er beweist damit den **Ausgang**, nicht die **Ursache** (b). Das einzige, was beides verknüpft, ist die Deklarations-Pin im Vitest (`options: --init` in `ci.yml`), mutationsgeprüft. |
| D28 | **Kein `vision`-Subagent im Design-QA-Loop** (2026-10-02) | Die §7-Prüfung liest die PNGs **selbst** — jedes konfigurierte Modell ist vision-fähig, und der Subagent `vision` existiert in `opencode.jsonc` **nicht mehr** (Rollen: `vision-creative`, `document`). **Nur** subjektive/feingranulare Bilder (Design-Aussage, Stimmung, Einzeldetail) eskalieren an `vision-creative` — Skill `vision-agents`. Betroffen waren die §5-Prosa (Build-Agent Punkt 5, mit entfernt), §7 Schritte 2/4, die PDF-VISION-Stellen und die Abgrenzung. **Verworfen: „auf `build-verify` umbiegen"** — der Skill hat keine Vision-Regel (Rollen-Tabelle: Orchestrator/Implementer/Verifikator); ein Verweis dorthin wäre falsch. **Verworfen: nur auf `vision-creative` umbenennen** — behauptet, der Subagent sei der Normalfall. |
| D29 | **Dangling-Referenzen werden mitrepariert** (2026-10-02) | Nach dem Auszug der §5-Flow-Prosa in den Skill `build-verify` (`2e4f8d8`) zeigen mehrere `§5`-Verweise ins Leere. Nutzerentscheid: **alle** reparieren, auch die, die nicht im Auftrag standen — hier `AGENTS.todo.md:523` („§5 Security-Review", vom Skill in der `nach-verify`-Tabelle abgedeckt). **Zielwahl:** Regel **inhaltlich**, nicht buchstabengetreu — `§5(4)` → Skill `build-verify` (Verifikator zerstört nie Implementierer-Arbeit, Always-on-Regel 3), „§5 Verifikations-Gate" → ebendort die Verdikt-Annahme-Regel. **Nicht** angefasst: Selbstverweise auf den **eigenen** §5 in `Agents.headless.md` (Fremdlast-Messungen) und der historische Vermerk „duziert `AGENTS.md` §5" — die treffen noch. |

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
- **§7 Fixture-Besitz**, **§5 Build-Agent Punkt 4 / Skill `build-verify`** (ein Verifikator darf uncommittete Arbeit nicht zerstören), **§10 A5/A6/A7**.
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
den Code geprüft, und **eine neue (38)** dafür aufgenommen; zuletzt die geschlossene **7**). **17, 21, 22, 23 und 25 sind verifiziert und freigegeben (2026-09-29, keine critical/high); 18–20, 24 und 26–29 sind per §4-Schnitt entfernt, weil verifiziert geschlossen; 30–37 sind mit Welle C entfernt (re-verifiziert **`APPROVED`**, ohne critical/high), und 7 ist ebenfalls entfernt (geschlossen, verifiziert, ohne lebenden Querverweis). CI GRÜN (Run 36537851878, WATCH-EXIT=0) — Zombie-These im echten Container belegt; Welle B ebenso grün (Run 36539885849, alle 4 Jobs); Nightly 36552912340 grün (striktes Profil, 142/52/0).** Geschlossen seit dem 2026-09-29 (per §4 entfernt, **nicht** vergessen): die Konto-Löschung, vormals Position 10, war als Ursache der `users`-Lücke geführt (**+15…+30** pro vollem Lauf, gemessen 2026-09-29, bewusst **nicht** geglättet — sie lieferte die DELETE-Route `ownership.ts:183`, ohne die das Ledger diese Art nicht besitzen kann; die **Nachmessung** des Delta ist mit der Welle 5/6/43 **erledigt** — `admin-users` **2 Bänder bei 7 Usern**, ein `@smoke`-Lauf hinterlässt `users = 8`). **Damals noch offen — mittlerweile verifiziert und mit dem §4-Durchgang 2026-10-02 entfernt:** 22 (Teilaspekt admin-venue → 25) und 38 (frische DB → 422 auf dem Team-POST, `ownership.spec.ts` grün nur aus ererbter Reihenfolge). **Die früher hier genannten 30–35 (Zombie-Fix-Follow-ups inkl. Ein-Token-Regression) sind alle abgearbeitet** — die Ein-Token-Regression ist behoben und mutationsgeprüft, ihr Docblock sagt jetzt ausdrücklich, was sie **nicht** ist. **Diese Liste ist der Nachtrag zum 2026-09-28-Session-Plan, nicht der Plan selbst** — die vollständige offene Menge steht in der Kopfzeile. **Der §4-Schnitt hat 17/21/22/23/25 bewusst NICHT entfernt**, obwohl sie verifiziert sind: sie tragen lebende Querverweise. **21** die korrigierte Zombie-Diagnose samt der inzwischen **vorliegenden** CI-Bestätigung (die früher auf eine Welle-C-Position verwies); **22** die „korrekt offen gelassene“ Restposition samt der TS-Parser-Voraussetzung aus **23**; **25** den unregistrierten admin-venue-Rest, auf den „22 (→ 25)“ zeigt; **17** die einzigen Verifikationszahlen der Welle A (test:run 405 · E2E 140 passed/50 skipped/0 failed · Screenshots 75 · Bänder 24/24/24) und den Negativbefund „M5 VOID (CACHE_STORE=array, kein persistenter Limiter)“.

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

---

## 🔒 Serial-E2E, Login-Quote und Skills-Nachtrag (Positionen 48–51, 2026-10-04)

> **Herkunft:** Erstmarker `AGENTS.skills.md` (`agents-skills-consumed: aad25d1`,
> geprüft 2026-10-04 — HEAD des Skills-Repos, keine Range zu bilden).
> **Nichts hiervon ist implementiert** — das sind Positionen, keine
> Arbeitsaufträge: `AGENTS.todo.md` ist ein Log, kein Auftrag; Abarbeitung nur
> auf ausdrücklichen Auftrag (`AGENTS.md:125-127`). GHCR-Verdikt (beide Packages
> public, anonymer Pull-Token erteilt + `gh api visibility=public`, 2026-10-04)
> steht im Marker und braucht keine Position.

### 48 — Serial-Pin (`--workers=1`) vs. Named Locks (Skill `playwright-parallel`)

**Widerspruch, gemessen statt vermutet.** `ci.yml:782-786` begründet
`--workers=1` in **beiden** Profilen mit „parallel workers teilen sich die CI-IP
und erzeugen 429" (`:813`, `:815`, `:817` pinnen den Wert). Die eigene Messung in
`AppServiceProvider.php:79-80` nennt **~17 Logins/min bei ~8 Workern** gegen ein
Budget von **40/min** (`:83`) — rund die Hälfte. Beide Zahlen stehen im Repo;
aufgelöst ist nichts.

**Warum die Begründung nicht trägt.** Der Limiter ist pro IP geschlüsselt
(`:85-86`, `->by('login:'.$request->ip())`), der Zähler liegt im
Backend-Prozess, nicht im Worker. Arbeit auf mehr Worker zu verteilen entlastet
ihn nicht; seriell zu fahren behebt ihn nicht — seriell senkt nur die Spitze
(Skill-Tabelle: Mutex vs. Rate).

**Was heute schon liegt.** Der E2E-Job setzt `CACHE_STORE=array`
(`ci.yml:617-624`, `:688`, geprüft `:695`) — mit der dokumentierten Folge, dass
der RateLimiter dort zustandslos ist; das Throttling-Verhalten deckt
`AuthThrottleTest` ab. Die Lock-Voraussetzungen liegen ebenfalls bereits:
`@playwright/test ^1.63.0` (`frontend/package.json:51`, Lock-Release) und
`fullyParallel: true` (`frontend/playwright.config.ts:24`).

**Burst-Mechanismus: unverifiziert.** Direkt gezählt, keine übernommene Zahl
(die frühere „38 Logins in einer Datei" war ein Miscount): meiste direkte
`loginAdminApi`-Aufrufe `ownership.spec.ts` mit **8** bei 13 Tests (`:175`,
`:281`, `:322`, `:354`, `:516`, `:638`, `:739`, `:912`, Import `:3`);
`approvals.spec.ts` **0** direkte (geht über `helpers/admin-data`,
`helpers/created-row`, `helpers/ownership`); meiste direkte
`POST /api/auth/login` **2** (`auth.spec.ts`, `admin-sub-resend.spec.ts`,
`account-deletion.spec.ts`); `helpers/admin-data.ts` trägt **14**
`loginAdminApi`-Aufrufstellen — die echten HTTP-Zahlen verstecken sich hinter
Helper-Indirektion und sind nur zur Laufzeit messbar. Keine Datei trägt eine
auffällige direkte Zahl.

**Inventar, gemessen 2026-10-04 (keine Shards, keine Locks):** `grep shard` über Configs + `ci.yml` = 0 (keine Shard-Matrix); CI pinnt `--workers=1` in allen drei Invocations (`ci.yml:813/815/817`); genau **ein** `mode: 'serial'`-Block im Repo (`ownership.spec.ts:90`, Probe-Treiber); **kein** Playwright-Named-Lock im Spec-Code (`lock:`-Treffer alle Prosa); Configs tragen bereits `fullyParallel: true` + beide Retry-Profile; `@playwright/test ^1.63.0` (Lock-Release verfügbar).

**Position (a):** serielle Läufe durch Named Locks ersetzen
(`test('…', { lock: '…' }, …)`), nach dem Migrationspfad des Skills: serielle
Baseline bei `retries: 0` einfrieren, dann `fullyParallel` + Lock. **(b)** steht
in 49 — bewusst zwei Positionen, nicht eine.

### 49 — IP-Quote braucht Test-Budget oder Test-Akteur-Schlüssel, nicht `workers`

Der Hebel gegen eine pro-IP-pro-Zeit-Quote ist ein Budget oder ein
Akteur-Schlüssel pro Test-Worker, kein Worker-Zähler: geteilte CI-IP heißt
geteiltes Bucket bei jeder Worker-Zahl (Skill-Falle „IP-based throttle via
worker count"). Die Limits sind in `AppServiceProvider.php:78-83` ausdrücklich
als **Development-Floors** dokumentiert (`local`/`testing` 40/30, Produktion
15/10 — `:83-84`; eigene Buckets für login/register `:85-88`, apply `:94-95`,
activate/public `:112-116` mit 300 vs. 60) — genau das macht eine Anhebung in
der Testumgebung legitim statt zu einer Produktänderung.

### 50 — `node-deps`: `packageManager`-Pin + `overrides` gegen die No-Pins-Regel

Skill `node-deps` (agents-skills) verbietet den `packageManager`-Pin in
`package.json` (`SKILL.md:20-25`) und feste Versionen in Manifest/Workspace
(`:8-10`); Updates laufen via pnpm ins Lockfile. Gemessen:
`frontend/package.json:6` trägt `"packageManager": "pnpm@11.23.0"`;
`frontend/pnpm-workspace.yaml:6-11` pinnt sechs `overrides` exakt; konform sind
die Ranges (`frontend/package.json:40-51`, exakt nur die eigene `version:
0.1.0`) und das Fehlen von `minimumReleaseAgeExclude`. Spannung, nicht nur
Formalität: CI liest den Pin via `package_json_file` (`ci.yml:476`, `:729`) —
die skill-konforme Form (`version: 11` im Workflow) braucht die Umstellung
beider Stellen.

Notiz 2026-10-04 (Skills-Range `aad25d14..6629ad2`): `node-deps` hat einen
Absatz „Boundary: manifests vs images" bekommen — Ranges bleiben in
`package.json`, aufgelöste Versionen gehören in Images/Build-Args, und ein
Caret-Range in einem Build-Arg liefert die Untergrenze, nicht die
Lockfile-Version. Das ändert den Befund nicht, schärft aber die Beseitigung:
`PLAYWRIGHT_VERSION` ist bereits konform (Lockfile → `e2e-image.yml:75` →
`ARG` in `Dockerfile.e2e:111`); `PNPM_VERSION` wird dagegen aus dem
`packageManager`-Pin selbst gelesen (`e2e-image.yml:76`, `:78`, `:80` →
Build-Arg `:151` → `ARG PNPM_VERSION` in `Dockerfile.e2e:113`, Default
`11.23.0`) — fällt der Pin, muss diese Extraktion auf eine Major-Linie im
Workflow umgestellt werden (wie die `ci.yml`-Ausnahme oben). Die zitierten
Skill-Zeilen (`SKILL.md:20-25`, `:8-10`) gelten nach dem Englisch-Pass
unverändert fort.

### ✅ 51 — `github-ci-filters`: umgesetzt 2026-10-04 (Verifikation Runde 36 steht aus)

**Was gebaut wurde:** `ci.yml` `paths-ignore: ['**/*.md']` auf `push` + `pull_request` (24/649 Pfade, kein `.md`-Leser im Repo, Branch unprotected gemessen); Image-`paths:` **behalten mit Begründung** (Build-Eingabe positiv+winzig; `paths-ignore` ohne Negation unpflegbar) — aber zwei echte Lücken geschlossen: `deployment/Dockerfile` → `deployment/**` (Skripte per `RUN --mount` installiert, bis 24 h Drift), `e2e-image` + `frontend/package.json` (PNPM_VERSION aus Pin, bis 7 Tage Drift). Gate-Gefährdung: 0 Key-Diffs, kein erlöschender Check (Dependabot kann keinen `.md`-only-PR erzeugen). Messung 40 Commits: 9 SKIP / 31 RUN / 0 Mismatch *(korrigiert 2026-10-04, Runde 36, Befund F2: 9+30=39 ≠ 40 — der 40. ist Leer-Commit `324a27e5`, ohne Pfade fällt die Enumeration auf RUN)*. Echte Bewährung beobachtet: Docs-Commit `d048ef2` erzeugte 0 Runs.
