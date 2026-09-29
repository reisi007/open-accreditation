# Task Board — open-accreditation

> Stand: 2026-09-28. **Nur offene TODOs** (aktueller Plan). Architektur-SOLL wandert nach
> Umsetzung nach `features/`. Referenz: Sportdata „Accreditation Services" + Screenshots des
> Altsystems (Bundesliga/ÖFB) in `reference/`.
>
> **Status (2026-09-29):** P1–P8 umgesetzt und verifiziert. `pnpm test:run` **405/405**, `lint` ✅, `build` ✅ (inkl. `check:i18n`, 418 Nachrichten) — **alle vier CI-Jobs grün, Lauf `36552912340`** (Zahlen unten). Screenshot-Harness grün, Bandzahl **24/24/24** (Position 17).
> 
> **E2E — der fehlende Arm ist geliefert, und er entscheidet die offene Frage (2026-09-29).** Bisher stand hier „zwei vergleichbare Arme": Parent `b433fd8` **103 passed / 2 failed**, HEAD `263290f` **119 passed / 8 failed**, gleiche Worker, gleiche **Fremdlast** — und die Ursache blieb **unentschieden**, weil der Arm **ohne** Fremdlast lokal unmöglich war (Maschine neu gestartet, **Docker's Socket verschwand**). **Genau diesen Arm hat der Nightly geliefert:** Lauf `36552912340` (2026-09-29, 03:30-UTC-Cron), frischer Runner, **striktes** Profil `playwright.regression.config.ts --workers=1` (`retries: 0`, `maxFailures: 1`), Ergebnis **142 passed / 52 skipped / 0 failed** — **null** Login-Fehlschläge, **null** `toHaveURL`-Timeouts. **Damit ist die Frage entschieden, und zwar gegen die Änderung:** die 6 Netto-Ausfälle waren die **Login-Drossel unter Fremdlast** (40/min, Zähler im DB-Cache), nicht die Zugabe von `loginAdminApi()`. Ein grüner **strikter** Lauf ist hier ein stärkeres Argument als ein grüner verzeihender — **ein** Fehlversuch wäre rot gewesen, und es war keiner. **Was das nicht ersetzt:** der Screenshot-Lauf ist davon unberührt (er braucht einen laufenden Dev-Stack), und der Messarm *lokal* bleibt bis zu einer ruhigen Maschine offen.
> 
> Postgres-Portable-Gate: SQLite **1607 passed / 0 skipped**, PostgreSQL **1606 passed / 1 skipped** — **heute (2026-09-29, Lauf `36552912340`) neu gefahren, beide grün.** Der Job prüft vor dem Lauf selbst, dass der aufgelöste Driver wirklich `pgsql` ist — es ist also kein zweiter SQLite-Lauf, der grün ist und nichts prüft. `1607 = 1606 + 1`: der eine Skip ist der dokumentierte `AllocationAtomicityTest` (SQLite-`PRAGMA defer_foreign_keys` erzeugt nach der Migration nachweislich kein `DEFERRABLE`).
> 
> **Gate-Stand 2026-09-29 (`36552912340`, alle vier Jobs grün):** E2E strikt **142 / 52 skipped / 0 failed** · Backend SQLite **1607 passed** (7892 Assertions) · Backend Postgres **1 skipped + 1606 passed** (7886 Assertions) · Frontend Vitest **42 Files / 405 passed** · `build` ✅ inkl. `check:i18n` (418 Nachrichten) · `lint` ✅.
> 
> **Verbleibend:** Go-Live (wartet auf Benutzer-Freigabe) + **22 Positionen** in der Batch-Tabelle (5–37). **Offen sind 18 davon:** 5, 6, 7, 8, 9, 10, 12, 13, 14, 22, 30, 31, 32, 33,
> 34, 35, 36, 37. **Geschlossen, aber stehengeblieben sind 4:** 17, 21, 23, 25 — alle vier hält der §4-Schnitt 2026-09-29 fest, weil
> **lebende Querverweise** daran hängen (Begründung: „Offen bei Übergabe“). **Position 22 trägt zwar `**[x]`
> VERIFIZIERT`**, ist aber per Verifikatorentscheid **korrekt offen gelassen** — der Marker gilt dem Verifikationslauf,
> nicht der Position; ein mechanischer `[x]`-Sweep würde genau die offene Position löschen.
>
> **Zweiter §4-Durchgang 2026-09-29:** 11, 15, 16 entfernt — jede Zeile **gegen den Code geprüft**, nicht
> geglaubt (§3): der Store-Pfad-Guard existiert und ist verdrahtet (`frontend/scripts/ui-review-captures.test.ts:44`,
> in `pnpm test:run` via `frontend/vitest.config.ts:33`), die Marker-Tabelle deckt alle drei Spec-Namen und wird
> **in der richtigen Richtung** getestet (`namespace-isolation.spec.ts:317-336`), und der Logo-Reset nimmt den
> Mutex (`tests/screenshots/helpers/dataset.ts:402`). Was die Zeilen trugen, steht im Git und im Abschluss.
> **Abschluss dieser Session:** unten im Board.
> 
> **Archiv entfernt (2026-09-28, auf deine Entscheidung):** die abgeschlossene Session-Historie ist weg — §4 verlangt nur offene Punkte, also den aktuellen Plan. Sie steht im Git: Commit-Range `263290f..<dieser Commit>`, Datei `AGENTS.todo.md`. **Was der Schnitt dabei gerettet hat, steht im Abschluss** — die `Mandant`-Route-Bindung war als offener Punkt in **keiner** Position geführt und ist jetzt `AGENTS.md` §10 **A7**.

---

## 🔁 Welle C — Zombie-Fix-Nacharbeit (2026-09-29, läuft)

> **Umfang:** Positionen **30, 31, 33, 34, 35, 36, 37** + **32(b)** (jetzt **D26**).
> Alle S1, alle im selben Strang (`frontend/tests/e2e/child-lifetime.spec.ts`,
> `frontend/tests/e2e/ownership-probe/run-child.ts`, `deployment/Dockerfile.e2e`),
> disjunkt zum §4-Board-Cleanup → **parallelisierbar**.
>
> **VOR dem Fix wird jede Position gegen HEAD geprüft, nicht geglaubt.** Grund ist
> gemessen, nicht vermutet: bei der Vorbereitung dieses Batches trafen **mehrere**
> Board-Zeilen den Code **nicht** (siehe Position 34 — die dort genannte
> Ein-Zeilen-„Regression" ist real und exakt wie beschrieben; Position 33 (L4)
> beschreibt den Pass-2-Sweep als „läuft unbedingt", der Code **bedingt** ihn
> dagegen über `leaderIsStillOurs`). Der Implementer meldet deshalb **jede**
> Position, die am HEAD **nicht** hält — und zwar **gemessen**, nicht behauptet.

### Stand 2026-09-29 — implementiert (`2e9ea93`), verifiziert: **`CHANGES REQUIRED`**

**Implementer-Verdikt, das zählt weil es Abweichungen meldet statt sie zu übergehen:**
34 · 31 · 30(M1/M3) · 33(L1) · 35(a/b) · 36 · 37 = **CONFIRMED+fixed** · 33(L2) = **ALREADY-SATISFIED**,
nichts geändert · 33(L3) = **DOES-NOT-HOLD-AS-DESCRIBED** (die BusyBox-Zitatstelle existiert an HEAD
nicht mehr, gelöscht in `c119f1d`) — **keine Arbeit erfunden**, das ist hier der eigentliche Wert ·
33(L4) = Board-Text veraltet, **aber der tiefere Punkt des Boards stimmte**: `pgrp === pid` beweist
„Gruppenleiter", nicht „unser Prozess" → `isSameProcess` (pid/pgrp/**comm** gegen die bei Kill-Zeit
gespeicherte Zeile) · **D26** = implemented, end-to-end **UNPROVEN**.

**Gates (unabhängig nachgefahren, alle Zahlen bestätigt):** `lint` clean `--max-warnings 0` ·
Vitest **434/43** (vorher 405/42, **+29**; `434 = 405 + 29` und `43 = 42 + 1` sind der Lebend-Tripwire,
falls die `include`-Erweiterung die Datei eines Tages verliert) · `build` ✅ inkl. `check:i18n` 418 ·
`ownership` **8** · `namespace-isolation` **56** · `admin-venue` **2 passed / 2 skipped** ·
`child-lifetime` **3 passed / 4 skipped / 1 failed = D26, absichtlich** (→ **D27**) ·
**Security: neutral**, keine Befunde — und keine erfunden.

**Blockierend (2 `high`), beide in der Prüfapparatur selbst — die Klasse, für die dieses Board existiert:**
- **H1** `run-child.test.ts:233` — die „fail loudly"-Verzweigung ist auf Linux eine **Tautologie**
  (`'procfs must be readable'` gegen sich selbst). **Vom Verifikator per Mutation bewiesen:** `freshParentTable()`
  → `return NO_TABLE` ließ die Suite bei **29/29 grün**. Damit nagelt die Position-34-Pin **nichts** fest, und
  der Testkommentar beschreibt exakt das Fehlverhalten, das er zeigt.
- **H2** `child-lifetime.spec.ts:387` — `readFileSync('/proc/1/comm')` ungeprüft; macOS hat kein procfs →
  **ENOENT**, der Test *bricht* statt grün zu werden. Widerspricht dem eigenen Docblock `:352-356`
  („NOT skipped, and NOT linux-only … the test is green there"). Dieselbe Datei kennt das richtige Idiom
  (`:388`, `:544`).

**Verifikator zur D26-Frage: `UNPROVEN-BUT-FAIL-CLOSED`** — und mit einer Präzisierung, die die
Commit-Message **überzeichnete:** outcome-proven ≠ mechanism-declared. Fehlender Reaper = rot
(nachgemessen: Waisenkind `Z`, `ppid=1`, PID 1 = `opencode` reapt nicht) — also **fail-closed**, und genau
die Richtung, die §3 verlangt. Aber der Test ist grün, sobald **irgendein** Reaper existiert: er beweist den
**Ausgang**, nicht die **Ursache**. Nur die Deklarations-Pin verknüpft beides — mutationsgeprüft, inkl. **Decoy**:
eine `options: --init` im `frontend`-Job erfüllt sie **nicht**, der Pin ist also gescoped und kein globales Grep.

**Vier neue Befunde aus der Implementierung, offene Positionen:** die Snapshot-Rück-Signalisierung und die
Gruppensignalisierung sind **beide** ungeschützt gegen Pid-Recycling (nur der Fresh-Walk ist gebunden) ·
`isSameProcess` vergleicht `comm` + `pgrp`, **keinen** Kernel-Generationszähler (einer ist nicht zugänglich)
· `DatabaseSeeder` setzt `teams_enabled => false`, wodurch `ownership.spec.ts` auf einer frischen lokalen DB
rot ist, bis ein Lauf es einschaltet · die Pass-2-Walk wird **nie** ausgeführt (die Prämisse hält laut der
eigenen Aufzeichnung des Moduls im Normalfall nicht) — der Fix entfernt eine Landmine, stellt aber **keine**
echten SIGKILLs wieder her.



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
| **7** | **`[x]` GESCHLOSSEN 2026-09-29 — der Cron ist gelaufen, strikt, grün im ersten Versuch.** Nightly `@regression` einmal fahren | S1 | **Die Prämisse ist gemessen überholt:** der Cron hatte nicht „null Läufe", sondern **null Läufe bis eben**. Lauf `36552912340` (2026-09-29, 03:30-UTC) fuhr `playwright.regression.config.ts --workers=1` — das **strikte** Profil, `retries: 0`, `maxFailures: 1` — und Ergebnis: **142 passed / 52 skipped / 0 failed**. **Der Flakiness-Detektor hat damit zum ersten Mal gegen diesen Stand gearbeitet, und der erste Fehlversuch ist ausgeblieben.** Das ist genau die Information, um die es hier ging — und sie ist die gute. **Nebenwirkung, die größer ist als die Position:** dieser Lauf ist der seit Wochen **fehlende Messarm ohne Fremdlast** (siehe Kopf), und er entscheidet die unentschiedene Login-Drossel-Frage zugunsten der Unschuld der Änderung. **Was ausdrücklich nicht behauptet wird:** ein grüner Lauf beweist nicht, dass es nie flaky war — er sagt, dass dieser Lauf **ohne Nacharbeit** grün war. Ein zweiter Nightly ist der einzige Weg, „stabil" zu sagen. |
| **8** | **Der Vision-Loop prüft den Editor, nicht das gerenderte PDF — dompdf-Fehler sind dort prinzipiell unsichtbar** (medium, 2026-09-28) | S1 | **Entschieden 2026-09-28: das gerenderte Ausweis-PDF kommt in den Loop.** Die Lücke ist **strukturell**, nicht sporadisch, und die Kette ist gemessen: `BadgeExportService:80` rendert **eine A6-Karte pro Antrag** in einem PDF und hat **keine** Postcondition im Backend (`grep` nach `pdf-to-png`/`has_alpha`/`corner_alpha` in `backend/app/`: **null** Treffer). Der Vision-Loop screenshotet den **Editor** — HTML/CSS, Browser. `pdf-to-png-vision.sh` prüft **ein** PDF, aber **nur wenn jemand es von Hand aufruft**. **Dazwischen liegt die Lücke:** ein Template kann im Editor **perfekt** aussehen und trotzdem 500 Ausweise **falsch** drucken. **Belegt, nicht behauptet:** genau das ist heute **zweimal** passiert — die `object-fit`-Streckung und die QR-Verzerrung waren **beide** im Editor unsichtbar, weil der Browser `object-fit` beherrscht und dompdf nicht. **Ein Editor-Screenshot kann einen dompdf-Fehler prinzipiell nicht zeigen.** **Vertrag für die Umsetzung:** (a) die Screenshot-Spec holt das PDF über den **echten** Export-Weg (`GET /api/admin/accreditations/{id}/badges/export` mit Session-Cookie) — nicht über einen Renderer-Aufruf im Test, sonst prüft der Loop etwas anderes als die Produktion; (b) rastert es mit `scripts/pdf-to-png-vision.sh` (dessen Postcondition inzwischen auf **Opazität** prüft); (c) legt die resultierenden PNGs **neben** den Editor-Screenshots ab, damit beide in **einem** Batch beim `vision`-Subagenten liegen; (d) der Auftrag an den Vision-Agenten sagt ausdrücklich, dass er **zwei Ansichten derselben Vorlage** vergleicht — Editor gegen Druck. **Warum (a) so wichtig ist:** ein im Test direkt aufgerufener `renderPdf()` wäre ein **anderer** Pfad als der Export, und genau der Unterschied, den man prüfen will, wäre ausgeschlossen. **Grenze, die nicht zu schönreden ist:** der Loop prüft **eine** Vorlage, der Export **viele** — er findet Klassen von Fehlern, keine Einzelfälle im Massenlauf. Für den Rest bleibt Position 7 (Schwarzseite) die offene Frage. |
| **9** | **Druck: `name` läuft aus seiner Box und überlappt `category` — Überlauf muss umbrechen, nicht abschneiden** (medium, 2026-09-28) | S1 | **NUTZERENTSCHEIDUNG 2026-09-28: „nie abschneiden, muss dann mit Zeilenumbruch umgehen können ohne das ganze Layout zu brechen."** **Gemessen:** 14 pt in 40 × 8 mm, zweizeilig, im **Druck** — im Editor ist es unauffällig. **Die Ursache ist eine dokumentierte Asymmetrie, kein Zufall:** `BadgeCanvas.tsx:154` → `badgeCanvasFontSizeCss` kappt die Schrift per `min()` gegen **eine** Ein-Zeilen-Breite (`cqw`) **und** eine **zweizeilen-sichere** Höhe (`cqh`), Boden 2 px (FE4-F1, genau dafür gebaut). `BadgeRenderService::renderField:394` setzt `font-size:%dpt` **wörtlich** aus dem Template, einzige Anpassung `max(1, …)` — **kein Auto-Fit**. **Wichtig, und es begründet die Entscheidung:** der Editor kann das **nicht** ehrlich machen — eine Vorlage hat **keine Person**, er zeigt `SAMPLE_TEXT.name = „Max Mustermann"`. Er ist strukturell **optimistisch**. **Also gehört die Robustheit in den Druck**, und zwar als **derselbe Vertrag**: eine Zeile, sonst Umbruch, und die Schrift so klein, dass der **umbrochene** Text in die Box passt. **Die Umsetzung ist nicht CSS:** dompdf kennt keine Container-Einheiten, also misst das Backend die Textbreite **selbst** (Font-Metriken). **Nicht** `overflow:hidden` + Ellipse — das ist abgelehnt, weil ein abgeschnittener Name auf einem Ausweis die Person **nicht eindeutig** zuordnet, und gedruckt ist gedruckt. **Rest, der nicht wegdefiniert werden kann:** unterhalb der Untergrenze passt auch der umbrochene Text nicht in 8 mm, und ein `position:absolute`-Feld kann dann nur noch **überlappen**. Das ist eine **Grenze der Geometrie**, keine offene Frage — sie wird gemessen und benannt, nicht weggetestet. **Die Umsetzung ist möglich, und zwar ohne Schätzungen — gemessen am Code, nicht entworfen:** `Dompdf\FontMetrics::getTextWidth(string $text, $font, float $size, …)` (`:294`) delegiert an `$this->canvas->get_text_width(…)` — **denselben Canvas, mit dem gerendert wird**. `BadgeRenderService:217` instanziiert `new Dompdf` **ohne Optionen**, es gibt **keine** veroeffentlichte `config/dompdf.php`, und `DejaVuSans.ttf` **+ `.ufm`** liegen gebuendelt bei. **Folge:** die Messung benutzt **genau die Metriken, mit denen gedruckt wird** — Vorschau und Druck stimmen also **konstruktiv** überein, nicht zufällig. Das ist der Grund, warum die Asymmetrie zum Editor kein Problem ist, sondern eine Fehlstelle: der Editor nähert sich über CSS-`min()`, der Druck gar nicht — und ab jetzt kann **beides** dieselbe Zusage erfüllen. **Zwei Fallen, die der Auftrag ausdrücklich nennen muss:** - **`sans-serif` ist eine Falle.** Das CSS sagt `font-family: DejaVu Sans, sans-serif`; gemessen werden darf **nur die von dompdf aufgelöste** Familie, sonst misst man eine andere Schrift als die gedruckte. `FontMetrics::getFont()` **wirft** bei unbekannter Familie — das ist der gewünschte Ausgang: **ein nicht auflösbares Template soll laut sein**, nicht still eine plausible falsche Zahl liefern. - **`getTextWidth` hat einen `static $cache`,** in dem die Textlänge **nicht** Teil des Schlüssels ist (nur `$text` als zweiter Level — der Schlüssel selbst ist `canvasClass/font/size/spacing`). Für unsere Länge unkritisch, aber es ist eine **modulweite** Falle und gehört als Kommentar an die Aufrufstelle. **Algorithmus, aus der Entscheidung abgeleitet:** Zeilenumbruch auf die Boxbreite (Wörter, **danach** zeichenweise — sonst zerbricht „Müller-Schmidt" als Einzelwort) · passen die Zeilen in die Boxhöhe, bleibt die eingestellte Schriftgrösse · sonst **schrumpfen**, bis der **umbrochene** Text passt · unterhalb der Untergrenze überlappt es — die dokumentierte Grenze. **Kein `overflow:hidden`, keine Ellipse, kein `text-overflow`** — abgelehnt. |
| **10** | **Konto-Löschung: Nutzer selbst, `mandant_admin` und `super_admin` — und es wird **wirklich** gelöscht** (medium, 2026-09-28) | S1 | **NUTZERENTSCHEIDE 2026-09-28, alle drei beantwortet.** (1) *Selbstbedienung:* „user soll selbst account löschen können, admins bzw. super admins dürfen auch accounts löschen." (2) *Harte Löschung:* „wirklich löschen wegen DSGVO" — **keine** Anonymisierung. (3) *Mandantenübergreifend:* **ja**, ein `super_admin` darf Benutzer **anderer** Mandanten löschen, **wie bei jeder anderen Berechtigung** — `Gate::before` (`AuthServiceProvider:28`) gibt ihm bereits **jeden** Gate mandantenunabhängig frei, ein `users.delete`-Gate hätte für ihn also **nichts** geändert; eine Sonderregel nur für die Löschung wäre willkürlich. **Pflicht dabei:** eine **explizite Rückfrage**, die den **Namen des Kontos** und die **Anzahl seiner Anträge** nennt. **Das Schema hat die Kaskade bereits entschieden** (meine Frage im Board war die falsch formulierte): `role_user` · `media` · `applications` · `sub_applications` → alle vier `cascadeOnDelete()`. **Einzige echte Lücke:** `sessions.user_id` ist `nullable()->index()` **ohne** FK — die Zeile bliebe verwaist. **Jeder gedruckte Ausweis hängt an einem Antrag**; mit dem Antrag stirbt sein `qr_token`, der Ausweis ist **nicht mehr verifizierbar**. **Das ist die richtige DSGVO-Folge** — der Token wäre sonst ein Weg, die gelöschte Person wiederzuerkennen — und es muss dem Nutzer **gesagt** werden, nicht verschwiegen. **Protokollierung:** Nutzerentscheid „kein Audit, nur das Anwendungslog" → akzeptiertes Risiko **A5** in `AGENTS.md` §10. **Die JWT-Frage ist GEMESSEN und die Zusage trägt:** die Widerruf-Wirkung hängt **nicht** an der Blacklist, sondern am **DB-Treffer** — `JWTGuard::user():107` ruft `retrieveById($payload[‚sub‘])`, fehlt die Zeile, ist `$this->user` null → **401 sofort**, nicht „60 Minuten Zugang". **Achtung, zwei verschiedene Wege, sehr verschiedene Güte:** (a) **Konto gelöscht** = DB-Zeile weg = **sofort 401**, trägt; (b) **`logout()`** = Token in die **Cache**-Blacklist (`Providers\Storage\Illuminate::__construct(CacheContract $cache)` — **Cache**, keine Tabelle) und dort `JWTGuard::logout():219-223` `catch (JWTException) {}` → **200 „Erfolgreich abgemailed", obwohl nichts gesperrt wurde** (Vendor-Verhalten, akzeptiertes Risiko, **nicht** im Repo gefixt). **Die Löschung darf Weg (a) nie über Weg (b) begründen** — das ist jetzt als Vertragstest festgeschrieben. **Umsetzungsvertrag, aus dem Code gelesen (nicht entworfen):** - **Neue Berechtigung `users.delete`**, getrennt von `users.manage` (`config/permissions.php:77` — die existiert bereits und ist **Rollenvergabe**). Löschung ans `users.manage` zu hängen hiesse: wer Rollen verteilen darf, darf auch Konten beenden. `mandant_admin` bekommt `users.delete`; `super_admin` hat `'*'` und umgeht es ohnehin. - **Zwei Routen, zwei Autorisierungen:** `DELETE /api/user/account` unter `auth:api`, **ohne** Gate — das Ziel ist `$request->user()`, ein Gate wäre nur eine zweite Fehlerquelle. `DELETE /api/admin/users/{user}` mit `can:users.delete`, mandantenscoped. `user` hält heute nur `accreditations.self` (`:100`), die Selbstbedienung braucht also **keine** neue Rolle. - **Die Kaskade löscht die Zeile, nicht die Datei.** `UserMediaService` hat **keine** `destroy()` — nur `store()`, und dessen Docblock beschreibt „unlink the predecessor files after the commit" nur für den **Vorher**-Fall. Ein gelöschtes Konto nähme sein **Porträt** also als Datei mit. `MandantMediaService::destroy()` macht es richtig (`:212`), und `MandantController` hat dafür `purgeWithRetry` (`:344`) samt Vertrag: unlink schlägt fehl → **loggen, weiterlaufen, Reste melden**, nicht 500 werfen. **Derselbe Vertrag gehört hierher**, und `UserMediaService::destroy()` ist Teil des Auftrags, nicht Nebenarbeit. - **`sessions.user_id`** (kein FK) wird **explizit** mitgelöscht, sonst bleibt die Zeile verwaist. - **Selbstbedienung hat keine Oberfläche.** Das Frontend ruft `PUT /api/user/profile` **nirgends** auf — es gibt **keine** Profilseite, nur `MyAccreditationsPage` und `PortalHomePage`. Und `UsersPage.tsx` hat **keine** destruktive Aktion. „Nutzer kann selbst löschen" ist also **kein Button**, sondern eine Selbstbedienungsfläche von Grund auf — das ist Umfang, der in der Entscheidung „Selbstbedienung" nicht sichtbar war, und er gehört benannt statt stillschweigend mitgenommen. **KORREKTUR 2026-09-28, Abend — Vertrag vor Delegation gegen Code geprüft, zwei Stellen waren falsch:** (1) „`UserMediaService` hat keine `destroy()`" ist falsch — sie existiert seit `e9e98f1` (`UserMediaService:167`), nimmt ein einzelnes `UserMedia`, wirft `RuntimeException` bei Fehlschlag und behält die Zeile. Der Auftrag ist also **nicht** „`destroy()` schreiben", sondern „Pro-User-Purge mit `purgeWithRetry`-Semantik verdrahten". (2) Das Vorbild ist nicht `MandantMediaService::destroy()` (`:108`, nicht `:212`), sondern `purge()` (`:130`) — „ohne Spaltenschreibung, weil die Zeile selbst gelöscht wird", genau der Löschfall; beide werfen (`MediaRemovalFailedException` dort, `RuntimeException` hier), `purgeWithRetry` fängt nur erstere — Typangleich oder doppeltes `catch` gehört zum Auftrag. Der Rest hält: Routen, Gates, Kaskaden, `sessions`-Lücke (`0001_01_01_000000:75`), A5; `MandantController`-Pfad braucht `Api/Admin/`-Präfix. |
| **12** | **Ein Checkout mit einem Store am alten Pfad (`test-results/ui-review/`) wird weder umgezogen noch gewarnt** (low, 2026-09-28) | S1 | Der Store ist per `mv` **von Hand** umgezogen (einmalig, in einem gitignorierten Verzeichnis). **Folge:** jeder Checkout, der noch einen Store von einem älteren Commit trägt, behält eine **veraltete Kopie** am alten Pfad — und **nichts** warnt, **nichts** migriert, **nichts** räumt sie ab. Wer danach `test-artifacts/ui-review/` nicht kennt und den alten Pfad öffnet, sieht einen **vollständigen, plausibel aussehenden, aber veralteten** Review-Batch. **Das ist die gefährlichste Form von Altlast**: nicht ein Fehler, sondern ein **richtig aussehender falscher** Befund. **Gehört behoben** — und zwar **nicht** durch Aufräumen (ein gitignorierter Ordner ist vergessen, sobald niemand mehr hinschaut), sondern durch einen **Hinweis mit Ausstieg**: meldet der Harness einen nichtleeren alten Pfad, mit dem aktuellen als Vergleich. Billig, und es verhindert, dass jemand einen Batch prüft, der nicht existiert. |
| **13** | **`withCookie()` transportiert im API-Test **nichts** — Auth-Tests sind aus dem falschen Grund grün** (high, 2026-09-28) | S1 | **Gefunden, als ein Agent eine eigene Messung als Falsch-Positiv entlarfte.** `MakesHttpRequests::prepareCookiesForJsonRequest()` (`:747-750`) ist **eine Zeile**: `return $this->withCredentials ? $this->prepareCookiesForRequest() : [];` — **ohne** `withCredentials()` werden Cookies **stillschweigend verworfen**; **mit** `withCredentials()` verschlüsselt `withCookie()`, und die `api`-Gruppe hat **kein** `EncryptCookies` (`bootstrap/app.php:23+` konfiguriert nur die Host-Allow-List), also entschlüsselt niemand. **Folge:** jeder Request ohne Token sollte **401** sein — und ist es nicht, weil der `JWT::$token`-**Singleton** aus `login()` noch gesetzt ist. **Das ist dieselbe Fehlerform wie der Falsch-Positiv, aus dem er entstanden ist: grün, weil ein Zustand nebenbei gesetzt war, nicht weil die geprüfte Sache funktioniert.** **Was dadurch als Beweis hinfällig ist:** `AuthLoginTest.php:105-114` transportiert das JWT-Cookie per `withCookie()` und prüft dann `assertOk()` — diese drei Assertions prüfen **nicht** den Cookie-Pfad. `AGENTS.md` §11 führt „httpOnly-Cookie-Auth" als **bestätigte Stärke**; dieser Beleg trägt die Aussage **nicht**. (Die Produktion ist unberührt — der Browser sendet den Cookie. Betroffen ist die **Evidenz**.) **Deckt sich mit dem 30-Minuten-Stall:** dieselbe Klasse, anderes Symptom — ein Test, der aus einem Zustand grün ist, den er selbst erzeugt hat. **UMGESETZT 2026-09-28, wartet auf den separaten Verifikator.** Kanal: `TestCase::withJwtCookie()` — `withCredentials()` (schaltet den JSON-Cookie-Transport überhaupt erst ein) **plus** `withUnencryptedCookie()` (Klartext, weil `EncryptCookies` auf der `api`-Gruppe nie läuft); beides privat in dieser einen Methode. **Kanal-Test:** `JwtCookieChannelTest` (8 Tests, 28 Assertions) mit der gemessenen Tabelle `withCookie()` → 401 · `withCookie()`+`withCredentials()` → 401 (Ciphertext `eyJpdiI6` = `{"iv":` statt `eyJ0eXAi` = `{"typ":`) · `withUnencryptedCookie()` allein → 401 · `withJwtCookie()` → 200 · `->call()` → 401. **Rückfallwächter:** `ForbiddenJwtCookieChannelTest` (5/22) verbietet `withCookie(`, `withCredentials(`, `->call(` in ausführbarem Code unter `tests/` — Kommentare vorher via `token_get_all` entfernt, damit die Prosa, die den Bug erklärt, nicht anschlägt; die Ausnahme ist eine **festgenagelte Anzahl** (`EXEMPT`), **kein Pfad**, weil eine Pfadausnahme ein Loch mit Kommentar ist. **Mutationen:** neutralisierter Kanal → 4 rot · verschlüsselter Kanal → 5 rot · je injizierte verbotene Schreibweise → 1 rot. **Der Fund, der den Fund auslöst — 18 Tests prüften die Tür, nicht das Schloss.** Nach der Reparatur: `1160 passed` vorher **und** nachher, **und 18 wurden dabei rot.** Sie behaupteten `403` und bekamen `401`. **403 = authentifiziert, aber verboten; 401 = nicht authentifiziert** — eine 403-Zusage auf einer nie authentifizierten Anfrage kann ein funktionierendes Gate nicht von einer **zugemauerten Tür** unterscheiden. Betroffen: `AdminMandantTest:96` (5 Admin-Routen × 5 Rollen) und `MandantMediaSelfServiceTest:252/260/281` (6 Self-Service-Routen × 2 Rollen + Fremdmandant). **Ausgelöst durch `actingAsApi()`**, das den `JWT::$token`-Singleton stehen ließ — die Reparatur der einen Tatsache deckte 18 falsche Zusagen auf, die **nie geprüft** waren. **Ein vierter kaputter Kanal kam erst beim Messen dazu:** `MakesHttpRequests::call()` nimmt Cookies als **dritten** Parameter und **defaultet auf `[]`**, ein direkter `->call($method, $uri)` transportiert also **gar nichts**, egal wie die Konfiguration aussieht — und genau darüber liefen die 18. |
| **14** | **`Google Wallet` (P6)** | — | Externer Schritt: API-Zugang/Issuer-Setup bei Google. Von uns nicht leistbar. |
| **17** | **[x] VERIFIZIERT 2026-09-29 — per Rubrik APPROVED (kein critical/high). Fixed mit Mutations-Nachweis (2500-ms-Delay: ohne Postcondition rot, mit grün; 400-ms-Zahl korrigiert — networkidle absorbiert). Gates: test:run 405, lint clean, build OK, E2E 140 passed/50 skipped/0 failed (**damaliger Stand**; der Nightly `36552912340` vom 2026-09-29 fährt inzwischen **142/52/0**), Screenshots 75 passed, Bänder 24/24/24, Gegenrichtung grün. E2E-%-Delta: nur users +15…+30 (Position 10, offen). M5 VOID (CACHE_STORE=array, kein persistenter Limiter). Follow-ups: **25** (26–29 sind per §4-Schnitt 2026-09-29 entfernt — alle vier verifiziert geschlossen).** Der Capture hat keine Daten-Postcondition — ein 400-ms-Request wird als gültiges Artefakt gespeichert** (high, 2026-09-28) | S1 | `ui-screenshots.spec.ts:173` via `helpers/session.ts:20-23`. **Der Verifikator hat die Ursache gemessen, nicht die Erklärung übernommen.** `waitForAppSettled` ist `networkidle` + **300 ms**; nach einem Klick ist `networkidle` **schon erfüllt** und kehrt sofort zurück — das 300-ms-Budget ist die **einzige** Reserve, und die Daten kommen nach **102–163 ms**. **Gemessene Reserve: 159–220 ms.** Erzeugt per Verzögerung von `/api/admin/users` um 400 ms, **Dataset unverändert**: `h=950, bands=0`, und der **grüne Lauf** speichert den **Lade-Spinner** unter der Überschrift. **§7 Abnahme 1 ist damit nicht unstabil, sondern unbewacht.** Und **strukturell asymmetrisch:** Desktop navigiert per Klick, Mobile per Dokument-Laden — dieselbe 400-ms-Verzögerung wird dort absorbiert. **Die Behauptung, das seien Daten, ist widerlegt: ein fester Datensatz kann keine 20-zeilige Liste verschwinden lassen.** |
| **21** | **[x] VERIFIZIERT 2026-09-29 — Diagnose korrigiert (M4, 2026-09-29): kein Gruppen-Defekt, sondern Zombie — PID 1 reapet im CI-Container nie, kill(pid,0) auf Zombies erfolgreich. Fix: STAT-Prüfung Z/X = nicht-ausführend (fail-closed) + Deszendenten-Sweep; CI-Bestätigung ausstehend (siehe 32).** Ein verklemmter Kind-Lauf überlebt als Waisenkind und schreibt weiter in die DB** (medium, 2026-09-28) | S1 | `ownership.spec.ts:87-90` — `execFileSync` **ohne** `timeout`. Hängt der Kind-Run, stirbt der Elterntest an seinem 300-s-`setTimeout`, **der Kind-Prozess läuft weiter** und schreibt für den Rest der Suite in die Datenbank. **Gut:** `workers: 1`, `fullyParallel: false`, `retries: 0`, `timeout: 60000`, **kein Port**, `maxFailures` und `retries` des Elternlaufs **durchdringen nicht**. |
| **22** | **[x] VERIFIZIERT 2026-09-29 — korrekt offen gelassen (users +15, Rest 0→0; admin-venue +1/+1 → Follow-up 25).** Ledger-Hook vorhanden, aber leer — fünf Specs verlassen sich weiter auf den seriellen Sweep** (medium, 2026-09-28) | S1 | `admin-mandant-switch`, `admin-category`, `admin-event`, `admin-venue`, `approvals` erzeugen UI-Zeilen und löschen sie **über die UI selbst**. **Ein Mittelfehlschlag lässt sie dem Sweep** — genau die F1-Form, die das Ledger beenden sollte. Die Grenze ist ehrlich benannt: die ID steht in keiner Create-Antwort. **Behebelbar** über einen Lookup nach exaktem, worker-eindeutigem Namen — was einen Parameter braucht, und der setzt die TS-Parser-Frage aus Position 23 voraus. |
| **23** | **[x] VERIFIZIERT 2026-09-29 — fixed (Kind-Env-Pin, Mutation rot).** Der verschachtelte Lauf erbt die ganze `process.env` und damit die Messschalter selbst** (medium, 2026-09-28) | S1 | `ownership.spec.ts:90` reicht `{ ...process.env, CI: '' }` durch — der Kind-Lauf braucht nur `E2E_BASE_URL`. **Strukturell** erreicht `E2E_OWNERSHIP=off` auch das Kind, und dessen Teardown wäre neutriert, wodurch Assertion (3) des Treibers fiele. **Heute nur maskiert**, weil `e2e-per-spec-leaks.mjs:110` den Treiber per `--grep-invert` ausschliesst. **Die Gültigkeit eines Tests hängt damit an einem Schalter, den der Treiber nicht kontrolliert.** |
| **25** | **[x] VERIFIZIERT 2026-09-29 — Docblock + zurückgenommene Negativprobe (Diff auf namespace-isolation leer).** M1: admin-venue-Entscheidung steht nirgends im Repo (medium, 2026-09-29) | S1 | Verifikatorbefund zum Implementierer-Diff: `admin-venue.spec.ts:17-21` trägt leere Ledger-Hooks, UI-erzeugtes Team+Venue (+1/+1 je Lauf, gemessen 2026-09-29) ist reclaimbar aber unregistriert — korrekt nicht gemacht (Auftragserweiterung über das damalige Band 17–24 hinaus; 18–20 und 24 sind seither per §4-Schnitt entfernt), aber nirgends dokumentiert. Fix: ~5-Zeilen-Docblock; `admin-venue.spec.ts` in `UI_CREATE_SITES` aufzunehmen machte das Gate korrekt rot. Natürlicher nächster Kandidat für die Registrierung im **Namens-Lookup**, mit dem `admin-mandant.spec.ts` inzwischen räumt. |
| **30** | **M1+M3: Dokuwidersprüche im Zombie-Fix** (medium, 2026-09-29) | S1 | Verifikatorbefunde: (M1) `run-child.ts:33-38/41-45` begründet den Sweep mit unzuverlässiger Gruppenzugehörigkeit, `child-lifetime.spec.ts:250-252` stellt fest, sie war nie kaputt (gemessen: Enkel erbte pgid) — die Dateien widersprechen sich über dieselbe CI. (M3) `:46-47` attribuiert PID 1 = `tail -f /dev/null` an diese CI als Messung — `deployment/Dockerfile.e2e` setzt weder ENTRYPOINT noch CMD, das failing Image (`ghcr.io/reisi007/accriditation-e2e`, procps-Basis) ist BusyBox-frei; Schlussfolgerung richtig, Kausalzuordnung Hypothese. Fix: Wortlaut auf gemessene Fakten zurücknehmen. |
| **31** | **M2: die tragende Fix-Zeile hat keinen Test** (medium, 2026-09-29) | S1 | `child-lifetime.spec.ts:150` (`/^[ZX]/`) wird auf darwin nie genommen (gemessenes Alphabet `? R S U`, kein Z/X) und auf CI nur im Vorbeigehen; `statCodeFor`/`isExecuting` (`:95`/`:128`) und `readParentTable`/`descendantPids`/`sweepDescendants` sind modulprivat, null Vitest-Refs. Eine spätere Entfernung des Musters bliebe überall grün. DoD §3 verlangt Tests für neue Logik — reine Stringfunktion, nach Export trivial testbar. |
| **32** | **M4: Reaper — Nutzerentscheid 2026-09-29 („no process leaks please") → `tini` als PID 1, Test-Toleranz wird zur zusätzlichen Postcondition** (medium, 2026-09-29) | S1 | (a) GESCHLOSSEN 2026-09-29: CI-Run 36537851878 grün (WATCH-EXIT=0) — /proc-Fix läuft im echten Container, Zombie-These damit belegt, nicht mehr nur reproduziert. **(b) ENTSCHIEDEN 2026-09-29, siehe D26:** keine Prozesslecks — ein verwaistes Kind muss **wirklich verschwinden**, nicht bloss nicht-ausführbar sein. Also **strikte Lesart**: `tini` (oder gleichwertiger Reaper) als **PID 1** im E2E-Image, und die Test-Prädikate bleiben als **zweite** Postcondition (fail-closed) bestehen. **Wichtig, die Entscheidung hat eine Reichweiten-Folge:** mit Reaper in CI ist die *Z*-Toleranz in CI **nicht mehr der Normalfall**, sondern der Beweis, dass der Reaper fehlt — die STAT-Prüfung ist damit nicht mehr „die Aussage", sondern „die Aussage plus ihre Abwesenheitskontrolle". Row 21 trägt die Zombie-Diagnose statt des Gruppen-Kills. |
| **33** | **L1–L4: vier Lows im Zombie-Fix** (low, 2026-09-29) | S1 | (L1) `statCodeFor`-Docblock `:71-94` beschreibt `isExecuting`, dupliziert `:117-127` (Defektklasse wie der per §4-Schnitt 2026-09-29 entfernte Pos 29 — ein Docblock, der neben seinem Code steht, ohne ihn zu beschreiben). (L2) Fehlermeldung wird auch grün gebaut (~41 ms ps-Forks/Lauf, „failure path" halb falsch). (L3) BusyBox-Zitat falsch (`invalid option -- 'p'` gemessen) + BusyBox ist nicht die failing Plattform (failing = procps, `ps -p` geht dort). (L4) Sweep-Pass 2 läuft unbedingt, recycelte Pid könnte fremde Nachkommen einsammeln und SIGKILLen — unwahrscheinlich, aber die einzige destruktive statt nur rote Stelle. |
| **34** | **M: Pass-2-Fresh-Walk ist vakuös (Regression, Ein-Token-Fix)** (medium, 2026-09-29) | S1 | Verifikatorbefund zur Welle A: `run-child.ts:807` ruft `readParentTable()` OHNE Argument → leere Map → Walk liefert `[0]` → Sweep überspringt Pid 0 → **0 SIGKILLs** (gemessen: `[]`/size 0 vs. `[0,66]`/2 Pids mit echter Tabelle). Regression gegen HEAD (dort las `readParentTable()` selbst `ps`). Praktische Auswirkung ~0 (nach Reparenting fände der Walk eh `[0]`; Fehlrichtung sicher = weniger Kill), kein Test rot, kein CI-Risiko — daher medium. Fix: `readParentTable(readProcessTable())`. Docblock `:762-763`/`:776-783` beschreibt einen Walk, der nichts finden kann. |
| **35** | **L: Bilanz-Satz + Doppel-Read im Zombie-Fix** (low, 2026-09-29) | S1 | (a) `run-child.ts:774` „the one place … damage" stimmt nicht — `:809` re-signaliert Snapshot-Pids bedingungslos mit derselben Recycling-Exposition (plus `:722`/`:744`); Wortlaut korrigieren. (b) `child-lifetime.spec.ts:422` vergleicht zwei unabhängige Reads derselben Datei (Flake 0/200000, µs-Fenster S→R); deterministisch machbar, kostenlos. |
| **36** | **L: falsche Zeilennummer im Docblock** (low, 2026-09-29) | S1 | Verifikatorbefund zur Welle B: `namespace-isolation.spec.ts:1062` zitiert `ownership.spec.ts:413` für den Variable-Key-POST — `:413` ist `continue;`, der POST steht bei `:416`. Inhalt wahr, Deckung hält (Literal-Posts `:387`/`:391`/`:506` gemessen). Reiner Zitierfehler. |
| **37** | **L: falsche Parenthese über Skip-Scope** (low, 2026-09-29) | S1 | Verifikatorbefund: `admin-venue.spec.ts:34` („the second test skips the mobile project") — der Skip ist describe-weit (`:74-76`, ein Describe `:70-199`), beide Tests überspringen. Schlussfolgerung (Desktop-only, +1/+1) korrekt. |
### Nicht in diesem Batch
- **Go-Live** (~20 Positionen: Pre-Prod-Domain, DNS, Caddy, Secrets, Deploy, Backup,
  Prod-Smoke) — liegt per Anweisung unten.
- **`P5-F4` Queue-Integration** — braucht Queue-Worker, ist Go-Live-Infrastruktur.

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
| D26 | **Keine Prozesslecks** (2026-09-29) | Nutzerentscheid auf Board-Position 32(b), wörtlich: **„no process leaks please."** Ein verwaistes Kind muss **wirklich verschwinden**, nicht bloss nicht-ausführbar sein — also **strikte Lesart** statt Test-Toleranz. **Was gebaut wurde (`2e9ea93`):** `options: --init` am **e2e-Job-Container** in `ci.yml` — Docker-Daemon-eigenes `docker-init` (tini) als PID 1. **Bewusst KEIN `ENTRYPOINT` im Image:** bei einem Job-Container bestimmt der *Runner* PID 1, nicht das Image; ein `ENTRYPOINT`, den der Runner ersetzt, wäre eine Zeile ohne Aussage über das laufende System. **Stand der Erkenntnis — zwei Hälften, und das ist nicht dasselbe:** (a) **outcome-proven** — `docker create --init` → `PID1 comm=docker-init`, Waisenkind **GONE**; ohne → `state=Z` (gemessen, Docker 29.8.1); (b) **mechanism-declared** — dass GitHub `options:` für einen Job-Container auswertet, steht in der Syntax-Referenz (nur `--network`/`--entrypoint` ausgeschlossen), im Runner-Quellcode **nicht** nachlesbar. **Nur ein echter CI-Lauf schliesst (b).** Die bestehenden STAT-Prädikate (`isExecuting`, `isZombieState`) **bleiben**: ein `Z` ist jetzt die **Abwesenheitskontrolle** für den Reaper, nicht sein Ersatz. **Nicht zu verwechseln** mit „Toleranz abbauen": die Entscheidung **verschärft** die Zusage. Begründung: §7 „Fixture-Besitz" und die ganze Board-Kette lehren, dass ein grüner Lauf, der geleckt hat, eine Lüge ist — dasselbe gilt für Prozesse. |
| D27 | **Bekanntes Rot: `child-lifetime.spec.ts` ist ohne Reaper absichtlich rot** (medium, 2026-09-29) | Aus D26 folgt eine **dauerhaft rote** Teststelle auf jedem Host ohne PID-1-Reaper — u. a. in **jedem** Entwickler-Container, in diesem Sandbox likewise (gemessen: PID 1 = `opencode`, Waisenkind `Z`, `ppid=1`). **Das ist fail-closed und damit richtig** (§3: ein rotes Gate ist besser als eine grüne Lüge), **aber es muss bekannt sein** — §3/§4 verlangen, dass eine wissentlich rote Standard-Suite-Stelle als akzeptiertes Risiko festgehalten wird, sonst begegnet sie dem Nächsten unvorhergesagt. **Zweite, unbequemere Folge:** der **CI-Push-Gate** ruht jetzt vollständig auf der **unbewiesenen** Hälfte (b) von D26 — wäre `--init` dort inert, geht der `e2e`-Job **bei jedem Push** rot. Auch das die richtige Richtung, aber ein selbst zugefügtes Rot über alle vier Gates, das nichts vorhersagt. **Grenze, die bleibt:** der Test ist grün, sobald **irgendein** Reaper existiert — er beweist damit den **Ausgang**, nicht die **Ursache** (b). Das einzige, was beides verknüpft, ist die Deklarations-Pin im Vitest (`options: --init` in `ci.yml`), mutationsgeprüft. |

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
> Abschnitt „Nächster Batch" oben (9 Positionen, S1–S3, alle delegierbar ohne Server/DNS/Secrets).
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

**22 Positionen** (5–37; der §4-Schnitt 2026-09-29 hat 8 geschlossene Zeilen aus 17–29 entfernt, der zweite
Durchgang am selben Tag die drei ausserhalb des Bandes liegenden 11/15/16 — alle drei gegen den Code geprüft,
nicht nach dem `[x]`-Marker geschlossen). **17, 21, 22, 23 und 25 sind verifiziert und freigegeben (2026-09-29, keine critical/high); 18–20, 24 und 26–29 sind per §4-Schnitt entfernt, weil verifiziert geschlossen, und 36/37 sind reine Zitier-Lows. CI GRÜN (Run 36537851878, WATCH-EXIT=0) — Zombie-These im echten Container belegt; Welle B ebenso grün (Run 36539885849, alle 4 Jobs).** Offen: **Position 10** (Konto-Löschung) als Ursache der `users`-Lücke (**+15…+30** pro vollem Lauf, gemessen 2026-09-29, bewusst **nicht** geglättet — liefert die DELETE-Route, ohne die das Ledger diese Art nicht besitzen kann); 22 (Teilaspekt admin-venue → 25/30); 30–35 (Zombie-Fix-Follow-ups inkl. Ein-Token-Regression 34). **Diese Liste ist der Nachtrag zum 2026-09-28-Session-Plan, nicht der Plan selbst** — die vollständige offene Menge steht in der Kopfzeile. **Der §4-Schnitt hat 17/21/22/23/25 bewusst NICHT entfernt**, obwohl sie verifiziert sind: sie tragen lebende Querverweise. **21** die korrigierte Zombie-Diagnose, auf der **32** (M4) steht; **22** die „korrekt offen gelassene“ Restposition samt der TS-Parser-Voraussetzung aus **23**; **25** den unregistrierten admin-venue-Rest, auf den „22 (→ 25/30)“ zeigt; **17** die einzigen Verifikationszahlen der Welle A (test:run 405 · E2E 140 passed/50 skipped/0 failed · Screenshots 75 · Bänder 24/24/24) und den Negativbefund „M5 VOID (CACHE_STORE=array, kein persistenter Limiter)“.

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
