#!/usr/bin/env bash
#
# PDF → PNG für die visuelle Verifikation (PDF-VISION-Pipeline).
#
# Warum es dieses Skript gibt: ein **PDF ohne gemalten Seitenhintergrund**
# (dompdf, wenn niemand `background-color` auf `@page`/`body` setzt) rastern
# mit Alpha-Kanal, und wie die transparenten Pixel am Ende aussehen,
# entscheidet der **Konsument**, nicht der Rasterer:
#
#   * Ghostscript-via-`magick` legt sie als `#FFFFFF00` ab (weiss, alpha 0).
#     Wer die Alpha ignoriert, sieht weiss; wer auf schwarz komponiert, sieht
#     eine schwarze Seite — und **die schwarze Badgeschrift wird unsichtbar**
#     (gemessen: 0 sichtbare Tintepixel in einem 640x160-Textband).
#   * `sips` legt sie als `#00000000` ab (schwarz, alpha 0). Wer die Alpha
#     ignoriert, sieht eine **literalschwarze** Seite (gemessen: 91 % der
#     Pixel exakt #000000) — ebenfalls ohne Badgeschrift.
#
# **Beide Fälle sind ausdrücklich zu nennen, sie verhalten sich verschieden:**
#
#   * **Fremde** PDFs — der Regelfall dieses Skripts. Sie malen den
#     Seitenhintergrund nicht, rastern mit Alpha, und Stufe 2 **repariert**
#     genau diesen Defekt. Ohne Stufe 2 bekommen sie drei mögliche
#     Konsumenten drei verschiedene Bilder (siehe oben).
#   * **Unsere eigenen** Badge-PDFs — seit der Nutzerentscheidung **D22**
#     (2026-09-28) ist der Fall umgedreht: `BadgeRenderService` setzt
#     `background-color: #ffffff` auf den Karten-Wurzelcontainer, die Seite ist
#     also an der Quelle gemalt. Sie rastern als `srgb` mit Eckalpha **1**,
#     gemessen in **allen drei** Bildzuständen (Porträt vorhanden, Platzhalter,
#     kein Bild). Stufe 2 hat dort **nichts** zu entfernen — das Skript ist
#     für unsere Ausweise vom Reparierenden zum **Prüfenden** geworden.
#
# Die Postcondition gilt deshalb **fremden** PDFs, und Stufe 2 bleibt trotzdem
# Pflicht, weil das Skript jedes PDF rasterbar machen muss. **Wer hier „unser
# Renderer malt keinen Hintergrund" liest und daraus die Postcondition oder
# Stufe 2 abbaut, liest veraltet:** die Aussage galt für dompdf im Allgemeinen,
# nicht mehr für unser Rendering. Umgekehrt ist die Postcondition **keine**
# Erlaubnis, Stufe 2 aus unseren Ausweisen heraus zu streichen — wer sie
# abbauen will, baut die Robustheit des Verifikationswerkzeugs ab.
#
# Deshalb ist der zweite Schritt **kein Kosmetik**, sondern das, was das Ergebnis
# überhaupt erst konsumentenunabhängig macht:
#
#   magick -density 200 x.pdf x-step1.png
#   magick x-step1.png -background white -alpha remove -alpha off x-step2.png
#
# Stufe 2 hat keinen Alpha-Kanal mehr; derselbe Pixel ist **literal weiss**. Auch
# die Anti-Aliasing-Kanten der schwarzen Schrift werden dadurch erst zu echten
# Graustufen (sonst sind sie entweder unsichtbar oder um 14 % zu fett).
#
# Es gibt eine kürzere, gleichwertige Route: `gs -sDEVICE=png16m` ist ein
# **deckendes** Device — kein Alpha-Kanal, weisser Hintergrund von Ghostscript
# gemalt, einstufig. Sie wird als Fallback A benutzt, wenn `magick` fehlt.
#
# Das Skript prüft seine Werkzeuge, benennt den genommenen Pfad, verifiziert das
# Ergebnis (kein Alpha-Kanal, Hintergrund **deckend**, Seitenzahl stimmt) und
# **beendet sich ungleich 0**, wenn etwas fehlt. Es gibt bewusst kein `|| true`:
# ein Skript, das ohne `magick` grün durchläuft, ist schlimmer als keines.
#
# **Was "deckend" prüft — und was nicht.** Geprüft wird die *Alphakomponente* des
# Eckpixels (2,2), nicht dessen Farbe. Beabsichtigt ist die Wirkung, die ein
# transparenter Seitenhintergrund sonst hätte: die Darstellung darf nicht vom
# Betrachter abhängen. Die Farbe ist bewusst **nicht** Teil **dieser**
# Postcondition, weil ein deckendes Volldruck-Badge ein gültiges Ergebnis ist.
#
# **Deckung allein ist aber zu wenig, und das war ein gemessener Fehler.** Eine
# Seite ist opak **und** leer; eine Seite ist opak **und** schwarz. Beides
# bestand die reine Deckungsprüfung mit **Exit 0**, während die frühere
# Weiss-Prüfung eine komplett schwarze Seite mit **Exit 3** gemeldet hatte
# (Mittelwert = min = max = 0). Für `BadgeExportService` heisst das konkret:
# ein Verband exportiert 500 Ausweise, **eine A6-Karte pro Antrag**, und niemand
# sieht auf jede einzelne. Ein Template mit dunklem Volldruck-Hintergrund,
# dessen Inhalt nicht gerendert wurde, liefert dann 500 unbrauchbare Seiten
# **ohne jede Reaktion**. Es gibt deshalb eine **zweite** Postcondition: die
# **Tinte**. Sie ist additiv daneben, hat eine eigene Schwelle und eigene drei
# Ausgänge, und sie fasst die Deckungsprüfung nicht an.
#
# **Warum die Tinte nicht am Eckpixel hängt.** Der Eckpixel ist per Konstruktion
# die Ecke und damit die am wenigsten repräsentative Stelle der Seite, und
# "der Hintergrund ist eine deckende Farbe" gegen "der Hintergrund ist Papier"
# ist dort nicht unterscheidbar. Gemessen wird deshalb die **Verteilung über die
# ganze Seite**: die Graustufen-Standardabweichung, Spalte `TINTE` der Tabelle.
#
# **Die Schwelle `INK_SIGMA_MIN` = 0.001 — und ihre Herkunft.** Alle Zahlen
# unten sind selbst gemessen (A6, dompdf, `magick`-Pipeline, Graustufen-σ nach
# dem Beschnitt um 1 %, 72…400 dpi):
#
#   Seite                                Graustufen-σ
#   ------------------------------------------------------------------
#   leer, weiss #ffffff                       exakt 0
#   leer, schwarz #000000                     exakt 0
#   leer, dunkel #1b2a3c                      exakt 0
#   leer, creme #f2e9d8                       exakt 0
#   ein Wort, 1pt, ohne QR              0.00086 … 0.00163
#   ein Wort, 4pt, ohne QR              0.00553 … 0.00742
#   nur der QR, 10 × 10 mm               0.05099 … 0.05272
#   echtes Badge (3 Felder + QR)         0.11862 … 0.12065
#
# **Die Leerseite ist nicht "fast 0", sie ist exakt 0** — 24 Messungen, vier
# Füllfarben über sechs Dichten. Die Schwelle muss deshalb **nicht knapp**
# getunt werden; sie muss nur begründet sein. Sie ist es, weil σ für vollschwarze
# Tinte exakt `sqrt(Tintenpixel / Seitenpixel)` ist (gemessen: 1 px → 0.00103,
# 4 px → 0.00206, 9 px → 0.00309, 25 px → 0.00515, 400 px → 0.02058, Abweichung
# ≤ 0.02 %). **0.001 ist damit "ein einziges dunkles Pixel auf einer A6-Seite"**
# — ein Vertrag, den ein Leser nachrechnen kann, und 4 Grössenordnungen über dem
# exakten 0 der Leerseite (jede Float-Rundungsresiduum liegt bei ≤ 1e-7).
#
# **Was die Schwelle bewusst unter sich durchlässt — und was sie nicht.** Sie
# liegt 1.9 × unter dem Boden eines einzelnen 2pt-Worts (0.00194), 5.5 × unter
# einem 4pt-Wort und 51 × unter dem **strukturellen** Boden: `cardHtml()` ruft
# `renderQr()` **unbedingt** auf, jede Karte trägt also einen QR, und dessen
# gesetzliche Kleinstbox von 10 × 10 mm allein ergibt 0.0509. Der 1pt-Fall der
# Tabelle ist damit ausdrücklich **kein** Boden, den diese Schwelle tragen
# muss: 2.8 px Schrifthöhe bei 200 dpi, und der Renderer kann ihn ohne QR gar
# nicht erzeugen.
#
# **Die eine Stelle, an der die Schwelle nicht monoton ist, offen benannt.** Der
# 1pt-Fall **überstreicht** 0.001 (0.00086 bei 150 dpi, 0.00163 bei 200 dpi), und
# sein Urteil wechselt damit mit der Dichte. Das ist die ehrliche Grenze der
# Stufe: unterhalb einer gut sichtbaren Textgrösse ist σ selbst dichteabhängig,
# weil dasselbe Wort bei verschiedenen Pixelrastern verschieden viel antialiasierte
# Tinte ergibt. Zwei Argumente halten diese Stelle trotzdem:
#   * betroffen ist **nur** eine Seite, die *keinen* QR trägt — und die erzeugt
#     `cardHtml()` nicht (siehe oben);
#   * die Alternative wäre eine Schwelle unter 0.00086, und σ = 0.0005
#     entspräche **einem Viertel** eines Pixels. Ein Vertrag, der auf
#     Bruchteile von Pixeln zeigt, ist keiner.
# Wer die Stufe strenger braucht, muss die QR-Mindestgrösse erzwingen (eine
# Layout-Regel, keine Postcondition) — nicht die Schwelle tiefer legen.
#
# **Der Beschnitt um 1 % ist nicht Kosmetik, er ist die Voraussetzung.** Ohne
# ihn ist die Leerseite **nicht** 0: die Rasterkante des Seitenhintergrunds ist
# antialiasiert, und je nach Dichte fällt sie auf Pixelraster oder daneben. Eine
# komplett schwarze Seite misst so bei 72 dpi **0.0202**, bei 100 dpi 0.0110 und
# bei 400 dpi 0.0086 — also **mehr als ein 1pt-Wort** (0.0015) und auf demselben
# Blatt, das der 4pt-Fall mit 0.0055 belegt. Ohne Beschnitt wäre die Schwelle
# nicht nur zu lax, sie wäre gar nicht wählbar. Der Beschnitt ist relativ
# (1 %), damit er bei jeder Dichte greift; er kostet 0.5 mm je Seite (A6, 200
# dpi) und damit nichts, was ein Layout-Eintrag legal platzieren kann.
#
# **Graustufen, nicht Farbe — gemessen, nicht begründet.** Auf allen vier
# Leerseiten-Füllfarben sind Graustufen-σ **und** Farb-σ **exakt 0**: die
# Farbe trennt hier nichts, was die Graustufen nicht auch trennen. Umgekehrt
# reagiert σ_farbe auf den *Farbton* einer Fläche, nicht darauf, ob gezeichnet
# wurde: eine vollflächig zweifarbige Seite (0.108 grau gegen 0.397 farbig)
# unterscheidet sich um Faktor 3.7, obwohl auf beiden **nichts** steht. Ein
# Farbmass hiesse damit: der Wert hängt davon ab, welche Farbe der Verband
# gewählt hat. Genau das ist die Abgrenzung, die hier gilt: gleichförmig schwarz
# und gleichförmig weiss fallen in Graustufen auf **denselben** exakten Nullwert,
# und deshalb genügt **eine** Schwelle für beide.
#
# **Die verbleibende Grenze ist der Kontrast, nicht die Leere.** σ misst Tinte,
# nicht Lesbarkeit: eine Seite mit 9771 Tintenpixeln in 0.06 % Kontrast
# (Text #1e2d3f auf #1b2a3c) misst 0.00109, dieselbe Seite auf weiss mit
# #fdfdfd misst 0.00063 — beides **um** die Schwelle. Solche Seiten sind
# unbrauchbar, und das Skript meldet sie (unter der Schwelle) oder weist sie
# durch ("Inhalt vorhanden") und überlässt das Urteil der Vision-Analyse, die
# die gerenderte Seite ohnehin beurteilt. **Ein Kontrast-Urteil ist eine andere
# Postcondition** und wird hier bewusst nicht erfunden: es braucht eine
# Farbregel, und die hat der Eckpixel nicht her.
#
# Usage:
#   bash scripts/pdf-to-png-vision.sh <file.pdf> [-o OUTDIR] [-d DENSITY] [--keep-step1]
#   bash scripts/pdf-to-png-vision.sh --help
#
# Beispiele:
#   bash scripts/pdf-to-png-vision.sh /tmp/badge-fixture.pdf
#   bash scripts/pdf-to-png-vision.sh badges-1.pdf -o /tmp/badges-1 -d 300
#
# Rasterpfade (in dieser Reihenfolge, jeder wird **laut** angekündigt):
#   1. magick + gs   PRIMÄR    — zweistufig, alle Seiten, freie DPI
#   2. gs            FALLBACK A — einstufig (`png16m`, deckend), alle Seiten
#   3. sips          FALLBACK B — NUR Seite 1, ~72 dpi, Alpha **nicht** entfernbar;
#                               Exit 3, die Ausgabe ist nicht vertrauenswürdig
#   Fehlt alles    Abbruch     — nennt die fehlenden Werkzeuge
#
# **Die Tinte braucht zwingend `magick`, die Deckung nicht** — und das ist der
# einzige Fall, in dem Fallback A (gs allein) jetzt **Exit 3** liefert, obwohl
# seine Rasterung in Ordnung ist: ohne magick ist die Tintenmenge nicht messbar
# (sips kann keine Statistik), und sie wird nicht durchgewinkt. Gemessene
# Begründung und die Abgrenzung zur Deckung stehen bei `ink_sigma`. Auf macOS
# ist das nicht erreichbar, weil dort immer ein magick im PATH steht oder
# ausdrücklich keiner — ein Linux-Feld ohne ImageMagick installiert magick.
#
# Exit-Codes:
#   0  Pipeline durchgelaufen, Ausgabe verifiziert (kein Alpha, Hintergrund opak,
#      Tinte über der Schwelle)
#   1  kein Rasterpfad verfügbar (meldet WHICH, nicht nur "command not found")
#   2  Aufruffehler (kein PDF angegeben / Datei fehlt / PDF unlesbar)
#   3  Rasterung oder Postcondition fehlgeschlagen
#
# Geschrieben für Board-Position 10 (AGENTS.todo.md) — die Pipeline war bis
# dahin eine Einmal-Anweisung und wurde nie durchlaufen.

set -euo pipefail

DENSITY=200
OUTDIR=""
KEEP_STEP1=0
PDF=""

usage() {
  sed -n '3,/^$/p' "$0" | sed -e 's/^# \{0,1\}//'
}

die() {
  printf 'FEHLER: %s\n' "$1" >&2
  exit "${2:-1}"
}

while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help)
      usage
      exit 0
      ;;
    -o|--outdir)
      [ $# -ge 2 ] || die "-o/--outdir braucht ein Verzeichnis" 2
      OUTDIR="$2"
      shift 2
      ;;
    -d|--density)
      [ $# -ge 2 ] || die "-d/--density braucht einen Wert" 2
      DENSITY="$2"
      shift 2
      ;;
    --keep-step1)
      KEEP_STEP1=1
      shift
      ;;
    --)
      shift
      break
      ;;
    -*)
      die "unbekanntes Argument: $1 (siehe --help)" 2
      ;;
    *)
      [ -z "$PDF" ] || die "mehr als eine PDF angegeben: $PDF und $1" 2
      PDF="$1"
      shift
      ;;
  esac
done

if [ -z "$PDF" ]; then
  printf 'FEHLER: keine PDF angegeben.\n\n' >&2
  usage >&2
  exit 2
fi

[ -f "$PDF" ] || die "PDF nicht gefunden: $PDF" 2
[ -r "$PDF" ] || die "PDF nicht lesbar: $PDF" 2
[ -s "$PDF" ] || die "PDF ist leer (0 Byte): $PDF" 2

case "$DENSITY" in
  ''|*[!0-9]*) die "--density muss eine positive Ganzzahl sein, ist: $DENSITY" 2 ;;
esac
[ "$DENSITY" -gt 0 ] || die "--density muss > 0 sein, ist: $DENSITY" 2

BASE="$(basename "$PDF")"
BASE_NOEXT="${BASE%.*}"

if [ -z "$OUTDIR" ]; then
  OUTDIR="${TMPDIR:-/tmp}/pdf-vision-${BASE_NOEXT}"
fi
mkdir -p "$OUTDIR"
OUTDIR="$(cd "$OUTDIR" && pwd)"

# ---------------------------------------------------------------------------
# Werkzeug-Prüfung. Drei Rasterpfade, in dieser Reihenfolge:
#
#   1. magick + gs  — PRIMÄR. die zweistufige Pipeline (Stufe 1 rastern,
#                    Stufe 2 Alpha entfernen). ImageMagick 7 kann die Flags
#                    nicht mit PDF-Input kombinieren, deshalb zwei Aufrufe.
#   2. gs allein     — FALLBACK A. `gs -sDEVICE=png16m` ist ein **deckendes**
#                    Device: kein Alpha-Kanal, der weisse Seitenhintergrund wird
#                    von Ghostscript selbst gemalt. Einstufig, alle Seiten,
#                    freie DPI. Gemessen nahezu identisch zum Primärpfad
#                    (0,29 % abweichende Pixel, reines Anti-Aliasing-Rauschen).
#   3. sips allein   — FALLBACK B (nur macOS). kann KEINEN Alpha-Kanal
#                    entfernen; die Ausgabe ist damit genau das defekte Bild,
#                    das die Pipeline beseitigen soll. Das Skript rendert sie
#                    trotzdem (brauchbares Diagnose-Artefakt), beendet sich aber
#                    **ungleich 0** und sagt, dass sie nicht vertrauenswürdig ist.
#
# Fehlt eines, wird es **namentlich** gemeldet — der Lauf wird nie übersprungen.
# ---------------------------------------------------------------------------
HAVE_MAGICK=0
HAVE_GS=0
HAVE_SIPS=0
command -v magick >/dev/null 2>&1 && HAVE_MAGICK=1
command -v gs >/dev/null 2>&1 && HAVE_GS=1
command -v sips >/dev/null 2>&1 && HAVE_SIPS=1

MISSING=""
[ "$HAVE_MAGICK" -eq 1 ] || MISSING="$MISSING magick (ImageMagick 7)"
[ "$HAVE_GS" -eq 1 ] || MISSING="$MISSING gs (Ghostscript)"

if [ "$HAVE_MAGICK" -eq 1 ] && [ "$HAVE_GS" -eq 1 ]; then
  METHOD="magick"
  printf 'Werkzeuge: magick %s + gs %s\n' \
    "$(magick -version | head -1 | awk '{print $3}')" \
    "$(gs --version | head -1)"
  printf 'Pfad    : PRIMÄR — zweistufig (Stufe 1 rastern mit Alpha, Stufe 2 Alpha weg)\n'
  printf '          magick -density %s + -alpha remove/-alpha off, alle Seiten\n' "$DENSITY"
elif [ "$HAVE_GS" -eq 1 ]; then
  # Einstufig, weil `png16m` deckend ist. Die Ankündigung ist Pflicht: der
  # Aufrufer muss wissen, dass er nicht die (sonst dokumentierte) zweistufige
  # Pipeline bekommen hat.
  METHOD="gs-png16m"
  printf 'Werkzeuge: gs %s\n' "$(gs --version | head -1)"
  printf 'ABWARNUNG: >>> FALLBACK A <<< magick fehlt%s.\n' "$MISSING" >&2
  printf 'ABWARNUNG: >>> FALLBACK A <<< es wird gs -sDEVICE=png16m benutzt: EINSTUFIG,\n' >&2
  printf 'ABWARNUNG: >>> FALLBACK A <<< aber deckend (kein Alpha, weisser Hintergrund von\n' >&2
  printf 'ABWARNUNG: >>> FALLBACK A <<< Ghostscript gemalt), alle Seiten, %s dpi.\n' "$DENSITY" >&2
elif [ "$HAVE_SIPS" -eq 1 ]; then
  METHOD="sips"
  printf 'Werkzeuge: sips %s\n' "$(sips --version 2>/dev/null | awk '{print $2}')"
  printf 'FEHLER: der Primärpfad und Fallback A sind nicht verfügbar, es fehlt:%s\n' "$MISSING" >&2
  printf 'ABWARNUNG: >>> FALLBACK B <<< es wird sips benutzt: NUR SEITE 1, ~72 dpi,\n' >&2
  printf 'ABWARNUNG: >>> FALLBACK B <<< KEIN Multi-Page, und sips kann keinen Alpha-Kanal\n' >&2
  printf 'ABWARNUNG: >>> FALLBACK B <<< entfernen. Die Ausgabe hat daher einen transparenten\n' >&2
  printf 'ABWARNUNG: >>> FALLBACK B <<< Hintergrund — genau der Defekt, den diese Pipeline\n' >&2
  printf 'ABWARNUNG: >>> FALLBACK B <<< beseitigt. Für eine Vision-Analyse NICHT verwendbar.\n' >&2
else
  die "kein Rasterpfad verfügbar. Es fehlt:${MISSING} sips (nur macOS). Installieren: brew install imagemagick ghostscript" 1
fi

# ---------------------------------------------------------------------------
# Rastern. Zwei Varianten: der Primärpfad braucht Stufe 1 **mit** Alpha, weil
# erst Stufe 2 den Hintergrund erzwingt; die beiden Fallbacks liefern bereits
# fertige PNGs.
# ---------------------------------------------------------------------------
STEP1_GLOB="$OUTDIR/${BASE_NOEXT}-step1"
STEP2_GLOB="$OUTDIR/${BASE_NOEXT}-step2"
STEP1_FILES=""
STEP2_FILES=""

# `magick` und `sips` als Einzeiler für die Metadaten einer Datei. Für den
# **Pixelwert** ist `%[pixel:…]` unbrauchbar: auf einem PaletteAlpha-Bild meldet
# es unabhängig vom gespeicherten Wert `srgba(0,0,0,0)` (gemessen). Der
# zuverlässige Weg ist ein 1x1-Crop mit `txt:` — der liest die Datei selbst.
pixel_at() {
  magick "$1" -crop "1x1+$2+$3" +repage txt: 2>/dev/null \
    | tail -1 | sed -e 's/^[0-9]*,[0-9]*: //' -e 's/ *$//'
}

# Ist der gemeldete Pixel **opak**? Nicht: ist er weiss?
#
# Das ist die entscheidende Unterscheidung. Die Postcondition will keinen
# Farbwert, sie will ein **deterministisch** gerastertes Bild: der ursprüngliche
# Zweck war, dass ein vom Renderer gelassener transparenter Bereich
# (`#FFFFFF00`) die Darstellung nicht dem Betrachter überlässt. "Weiss" war dafür
# die falsche Eigenschaft — sie verwechselte den *Farbwert* mit der *Deckung* und
# produzierte beide Fehlerrichtungen gleichzeitig:
#
#   #FFFFFF00   weiss, aber transparent  →  von `*255,255,255*` durchgewinkt
#   #3E5D8E     deckend, aber nicht weiss →  Fehlalarm auf ein Volldruck-Badge
#
# Deshalb fragt die Prüfung ImageMagick selbst nach dem Alphakanal dieses
# Pixels, statt die `txt:`-Zeile zu deuten:
#
#   magick <datei> -crop 1x1+2+2 +repage -alpha extract -format '%[fx:maxima]' info:
#
# `-alpha extract` legt den Alphakanal als Graustufenbild frei, `%[fx:maxima]`
# liefert dessen Maximum als 0.0…1.0. Damit ist die Frage **ohne** Farbwert und
# **ohne** Bittiefen-Annahme zu beantworten:
#
#   Bild ohne Alpha-Kanal (gray, srgb)        -> 1        opak per Definition
#   deckendes Volldruck-Badge  #3E5D8E       -> 1        opak, erlaubt
#   weiss mit Alpha 0           #FFFFFF00     -> 0        verletzt
#   teilweise transparent       (127,128)     -> 0.501961 verletzt
#
# Gibt den gemessenen Wert auf stdout aus (für die Befundtabelle) und liefert
# als Status 0 = opak, 1 = nicht opak, 2 = nicht feststellbar.
corner_alpha() {
  local file="$1" wh w h value rc
  # (2,2) muss überhaupt im Bild liegen. Sonst liefert `-crop` stillschweigend
  # eine leere Fläche und maxima=1 — ein fail-open auf ein nicht vorhandenes
  # Pixel (gemessen: 2x2-Bild, Crop 2,2 -> 1, ohne Fehler).
  wh="$(magick identify -format '%w %h' "$file" 2>/dev/null)" || return 2
  # `read` statt `set -- $wh`: die Funktion wird in Test-Harnesses per `eval`
  # aus dieser Datei extrahiert, und `set --` splittet nur unter bash.
  read -r w h <<< "$wh"
  [ "${w:-0}" -ge 3 ] 2>/dev/null && [ "${h:-0}" -ge 3 ] 2>/dev/null || return 2
  value="$(magick "$file" -crop '1x1+2+2' +repage -alpha extract \
    -format '%[fx:maxima]' info: 2>/dev/null)" || return 2
  # Die Entscheidung trifft `awk` numerisch, nicht `case` auf Strings: so ist
  # "0", "0.0" und "0.000001" derselbe Fall und ein unlesbarer Wert ist 2.
  printf '%s\n' "$value"
  awk -v a="$value" 'BEGIN { if (a == "") exit 2; if (a + 0 < 1) exit 1; exit 0 }' \
    || rc=$?
  case "${rc:-0}" in
    0) return 0 ;;
    1) return 1 ;;
    *) return 2 ;;
  esac
}

# **Einzige** Aufrufstelle von `corner_alpha` im ganzen Skript. Sie kapselt den
# Aufruf **und** das Sichern des Rückgabewerts und setzt beide Ergebnisse als
# Globals:
#
#   CORNER_ALPHA_VALUE   der Messwert (bei Status 2 ist er leer bzw. unbrauchbar)
#   CORNER_ALPHA_RC      0 = opak, 1 = nicht opak, 2 = nicht feststellbar
#
# Warum es diese Hülle gibt — der gemessene Fehler, nicht der befürchtete:
# die beiden Aufrufstellen (Stufe 1 und Stufe 2) behandelten den Rückgabewert
# **unterschiedlich**. Stufe 2 speicherte ihn (`|| corner_rc=$?`), Stufe 1
# **verwarf** ihn (`|| src_a="(nicht lesbar)"`). Damit fiel der Wert **1**
# (nicht opak) in denselben Zweig wie 2, und die Spalte ECKALPHA wies den
# entscheidenden Nachweis — "Stufe 1 hat einen Alpha-Kanal, Stufe 2 nicht" — als
# **"(nicht lesbar)"** aus. Gemessen: Eckpixel `#FFFFFF00`, Eckalpha `0`, in der
# Tabelle "(nicht lesbar)". Ein Aufrufer kann den Status hier nicht mehr
# versehentlich wegwerfen, weil es nur diesen einen gibt.
corner_alpha_measure() {
  CORNER_ALPHA_VALUE=""
  CORNER_ALPHA_RC=0
  # `|| …` fängt den Status, ohne dass `set -e` den Lauf abbricht.
  CORNER_ALPHA_VALUE="$(corner_alpha "$1")" || CORNER_ALPHA_RC=$?
}

# Formatiert (Messwert, Rückgabewert) **einheitlich** für die Spalte ECKALPHA.
# Beide Aufrufstellen benutzen diese eine Formatierung, damit die Fälle nicht
# wieder auseinanderlaufen können:
#
#   0  opak               ->  "1 (opak)"
#   1  nicht opak         ->  "0 (NICHT opak)"     <- der Fall, der gemessen war
#   2  nicht feststellbar ->  "(nicht feststellbar)"
#
# **1 und 2 müssen sich unterscheiden**, und 1 muss den **Wert** mitzeigen: der
# Unterschied zwischen Stufe 1 und Stufe 2 ist genau dieser Wert. Bei Status 2
# wird der Wert bewusst **nicht** mitgedruckt — er ist dann leer oder
# unbrauchbar, und ihn als Zahl auszugeben wäre geraten.
alpha_cell() {
  case "$2" in
    0) printf '%s (opak)' "$1" ;;
    1) printf '%s (NICHT opak)' "$1" ;;
    *) printf '%s' '(nicht feststellbar)' ;;
  esac
}

# ---------------------------------------------------------------------------
# Zweite Postcondition: **Tinte**. Die Deckung sagt, ob der Hintergrund erzwungen
# wurde; sie sagt nichts darüber, ob auf der Seite **etwas steht**. Die beiden
# Eigenschaften sind orthogonal, und der gemessene Fehlerfall ist genau ihre
# Kombination: opak **und** leer (Exit 0, 500 unbrauchbare Ausweise).
#
# Gemessen wird die **Graustufen-Standardabweichung** der Seite, nicht ein
# Farbwert und nicht der Eckpixel. Begründung und Messwerte im Kopfkommentar
# (Tabelle, `INK_SIGMA_MIN`, der 1-%-Beschnitt und seine Begründung); hier nur
# die drei Punkte, die man beim Lesen des Aufrufs braucht:
#
#   * `-colorspace Gray`  — der Messkanal. Farbe ist nicht die Grundlage, weil
#     sie auf den Farbton einer Fläche reagiert statt darauf, ob gezeichnet
#     wurde (gemessen: Faktor 3.7 auf einer zweifarbigen Leerseite).
#   * `-crop 99%x99%`     — **kein Kosmetik.** Ohne den Beschnitt ist die
#     Leerseite nicht 0: die antialiasierte Rasterkante misst bis 0.0202
#     (schwarze Seite, 72 dpi) und läge damit **über** einem legitimen 1pt-Wort
#     (0.0015). Relativ, damit er bei jeder Dichte greift.
#   * `standard_deviation` — `sqrt(Varianz)`, für vollschwarze Tinte exakt
#     `sqrt(Tintenpixel / Seitenpixel)`. Die Schwelle 0.001 ist damit
#     "ein einziges dunkles Pixel auf der Seite".
#
# Gibt den Messwert auf stdout aus und liefert als Status
#   0 = Inhalt vorhanden, 1 = LEER, 2 = nicht feststellbar.
# ---------------------------------------------------------------------------

# Die Mindeststreuung, ab der eine Seite als "Inhalt vorhanden" gilt. Begründet
# im Kopfkommentar; der Wert ist **keine** Rundungsgrenze, sondern die
# Streuung eines einzelnen volldunklen Pixels (gemessen 0.00103 bei A6/200 dpi).
INK_SIGMA_MIN=0.001

ink_sigma() {
  local file="$1" wh w h value rc
  # Dieselbe Mindestgrösse wie `corner_alpha`, und aus demselben Grund: unter
  # 3 × 3 Pixeln ist **keine** Pixel-Eigenschaft dieser Seite sinnvoll
  # feststellbar. Gemessen: ein 1x1-Bild liefert sigma = 0 und würde sonst als
  # "leer" gemeldet — die richtige Richtung (Exit 3), aber aus dem **falschen**
  # Grund, und der Diagnose fehlte der eigentliche Befund.
  wh="$(magick identify -format '%w %h' "$file" 2>/dev/null)" || return 2
  # `|| return 2` ist hier **keine** Pedanterie: `read` gibt 1 zurück, wenn die
  # Here-Zeichenkette leer ist, und ein 1 unter `set -e` bricht den ganzen Lauf
  # ab — aus "nicht messbar" würde ein stiller Abbruch ohne Befund. Genau vor
  # diesem Fall schützt sich `has_alpha` ausdrücklich ("Eine leere
  # Kanalkennung ist **kein** 'kein Alpha'").
  read -r w h <<< "$wh" || return 2
  [ "${w:-0}" -ge 3 ] 2>/dev/null && [ "${h:-0}" -ge 3 ] 2>/dev/null || return 2
  value="$(magick "$file" -colorspace Gray -gravity center \
    -crop '99%x99%+0+0' +repage -format '%[fx:standard_deviation]' info: 2>/dev/null)" \
    || return 2
  printf '%s\n' "$value"
  # Die Entscheidung ist **eine** — dieselbe Form wie in `corner_alpha`, aus
  # demselben Grund: der Vergleich ist numerisch in `awk`, nicht `case` auf
  # Strings, und die Gültigkeit der Zahl wird **am selben Ort** geprüft, damit ein
  # unlesbarer Wert nicht als "0" durchgeht. `+0` allein wäre genau das
  # fail-open: awk macht aus "nonsense" die Zahl 0, und 0 < Schwelle hiesse
  # "leer" — ein gemessener Befund, den es nicht gibt. Also zuerst die Form:
  # `magick` druckt fx sprachneutral und ohne Exponent (gemessen: `0.000764092`),
  # alles andere ist ein Defekt und wird "nicht feststellbar".
  awk -v s="$value" -v m="$INK_SIGMA_MIN" '
    BEGIN {
      if (s !~ /^[0-9]+(\.[0-9]+)?$/) exit 2
      if (s + 0 < m + 0) exit 1
      exit 0
    }' || rc=$?
  case "${rc:-0}" in
    0) return 0 ;;
    1) return 1 ;;
    *) return 2 ;;
  esac
}

# **Einzige** Aufrufstelle von `ink_sigma`, aus demselben Grund wie bei
# `corner_alpha`: Messwert **und** Status gehören untrennbar zusammen, und der
# Status darf nicht versehentlich weggeworfen werden. Beide landen als Globals:
#
#   INK_SIGMA_VALUE   der Messwert (bei Status 2 leer bzw. unbrauchbar)
#   INK_SIGMA_RC      0 = Inhalt vorhanden, 1 = LEER, 2 = nicht feststellbar
ink_sigma_measure() {
  INK_SIGMA_VALUE=""
  INK_SIGMA_RC=0
  INK_SIGMA_VALUE="$(ink_sigma "$1")" || INK_SIGMA_RC=$?
}

# Formatiert (Messwert, Rückgabewert) **einheitlich** für die Spalte TINTE.
# Die drei Ausgänge sind auch hier drei **verschiedene** Zellen, und 1 muss den
# **Wert** mitzeigen: "0" und "0.0009" sind derselbe Ausgang, aber nur einer von
# beiden ist der gemessene Befund, den man nachschlagen muss.
ink_cell() {
  case "$2" in
    0) printf '%s (Inhalt)' "$1" ;;
    1) printf '%s (LEER)' "$1" ;;
    *) printf '%s' '(nicht feststellbar)' ;;
  esac
}

case "$METHOD" in
  magick)
    magick -density "$DENSITY" "$PDF" "${STEP1_GLOB}.png" \
      || die "Stufe 1 fehlgeschlagen: magick -density $DENSITY $PDF" 3
    # magick nummeriert multi-frame Ausgaben mit "-0", "-1", …
    for f in "${STEP1_GLOB}"*.png; do
      [ -e "$f" ] || continue
      STEP1_FILES="$STEP1_FILES $f"
    done
    [ -n "$STEP1_FILES" ] || die "Stufe 1 hat keine PNG erzeugt (${STEP1_GLOB}*.png)" 3

    # Stufe 2: Alpha entfernen, Hintergrund literal weiss. DAS ist der Punkt —
    # in Stufe 1 ist der Seitenhintergrund transparent.
    for f in $STEP1_FILES; do
      # magick nummeriert nur bei mehreren Frames ("…-step1-0.png"); bei genau
      # einer Seite schreibt es "…-step1.png". Der Suffix wird deshalb vom
      # Basisnamen abgezogen statt auf einen Index geparst.
      b="$(basename "$f")"
      suffix="${b#"${BASE_NOEXT}-step1"}"
      out="$STEP2_GLOB${suffix%.png}.png"
      magick "$f" -background white -alpha remove -alpha off "$out" \
        || die "Stufe 2 fehlgeschlagen für $f" 3
      [ -s "$out" ] || die "Stufe 2 hat keine Datei geschrieben: $out" 3
      STEP2_FILES="$STEP2_FILES $out"
    done
    ;;
  gs-png16m)
    # `png16m` ist ein deckendes Device: kein Alpha-Kanal, weisser Hintergrund
    # kommt von Ghostscript. Einstufig — es gibt nichts zu compositen.
    gs -q -dNOSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r"$DENSITY" \
      -dTextAlphaBits=4 -dGraphicsAlphaBits=4 \
      -sOutputFile="${STEP2_GLOB}-%02d.png" "$PDF" \
      || die "Rasterung fehlgeschlagen: gs -sDEVICE=png16m" 3
    ;;
  sips)
    # `sips` meldet ein unlesbares Input mit "not a valid file - skipping" und
    # **Exit 0**. Deshalb wird nicht der Exit-Code, sondern die Existenz und
    # Größe der Zieldatei geprüft.
    sips -s format png "$PDF" --out "${STEP2_GLOB}.png" >/dev/null 2>&1
    [ -s "${STEP2_GLOB}.png" ] \
      || die "Rasterung fehlgeschlagen: sips hat keine lesbare PNG geschrieben (Achtung: sips quittiert ein kaputtes PDF mit Exit 0)" 3
    ;;
esac

if [ "$METHOD" != "magick" ]; then
  for f in "${STEP2_GLOB}"*.png; do
    [ -e "$f" ] || continue
    STEP2_FILES="$STEP2_FILES $f"
  done
fi
[ -n "$STEP2_FILES" ] || die "es wurde keine PNG erzeugt" 3

PAGES=0
for f in $STEP2_FILES; do
  PAGES=$((PAGES + 1))
done

# Ghostscript kennt die echte Seitenzahl — damit ist die Multi-Page-Aussage
# *geprüft* und nicht behauptet. Ohne gs (nur sips) gibt es keine Prüfung, und
# das wird auch so gesagt.
if [ "$HAVE_GS" -eq 1 ]; then
  PDF_PAGES="$(gs -q -dNOSAFER -dNODISPLAY -dBATCH -dNOPAUSE \
    -c "($PDF) (r) file runpdfbegin pdfpagecount = quit" 2>/dev/null | tr -d '[:space:]')"
  if [ -n "$PDF_PAGES" ] && [ "$PDF_PAGES" != "$PAGES" ]; then
    die "Seitenzahl stimmt nicht: PDF hat $PDF_PAGES Seite(n), gerastert wurden $PAGES" 3
  fi
  printf 'Seiten  : PDF hat %s Seite(n), gerastert %s\n' "$PDF_PAGES" "$PAGES"
else
  # Ohne gs gibt es keinen `pdfpagecount` — die Seitenzahl des PDFs ist hier
  # nicht feststellbar, und das wird auch so gesagt statt geraten.
  printf 'Seiten  : **nur Seite 1 gerastert** — die Seitenzahl des PDFs ist ohne gs nicht prüfbar\n'
fi

# ---------------------------------------------------------------------------
# Postcondition + Befund.
#
# Die Postcondition ist: **kein Alpha-Kanal** und ein **deckender** Hintergrund.
# Ohne diese Prüfung wäre ein fehlgeschlagenes `-alpha remove` ein grüner Lauf
# mit genau dem Defekt, den die Pipeline beseitigen soll.
#
# Geprüft wird mit dem, was da ist:
#   * Alpha      — `magick` (%[channels]) oder, ohne magick, `sips -g hasAlpha`
#   * Eckpixel   — nur mit `magick` (dessen **Opazität**, nicht dessen Farbe);
#                  ohne magick wird das **laut als nicht geprüft** ausgewiesen
#                  und nicht als bestanden behauptet
#   * Seiten     — `gs` (echte `pdfpagecount`), soweit gs vorhanden
# Ist die Alpha-Prüfung nicht durchführbar, endet der Lauf mit 3 — nicht mit 0.
#
# **Zwei Korrekturen an derselben Prüflogik, beide gemessen:**
#
# 1. „kein Alpha-Kanal" war über das Muster `*a*` entschieden. Das traf den
#    Buchstaben `a` in "**g**r**a**y": eine Textseite **ohne** Bild-XObject
#    rastern als `gray` (mit Bild rastern sie als `srgb`), wurde also als
#    „hat Alpha" gemeldet. Jetzt: explizite Listen der Kanalkennungen, und eine
#    unbekannte Kennung ist **nicht feststellbar** statt „kein Alpha".
#
# 2. Der Eckpixel war auf **Weissheit** geprüft (`*255,255,255*`). Das war die
#    falsche Eigenschaft in beiden Richtungen: es verwechselte Farbwert mit
#    Deckung, winkte also `#FFFFFF00` (weiss, aber transparent) durch und
#    feuerte auf jedem deckenden Volldruck-Badge. Jetzt wird die **Alphakomponente**
#    des Eckpixels gemessen (`corner_alpha`), unabhängig vom Farbwert.
#
# Der Preis dieser zweiten Korrektur, offen benannt: sie kann nicht mehr
# unterscheiden, ob der Hintergrund *weisses Papier* oder *eine deckende Farbe*
# ist. Beides ist ein gültiges Ergebnis — ein Volldruck-Badge soll nicht als
# Fehler enden. Was dafür zunächst **nicht** erkannt wurde, war ein
# **undurchsichtiger, unbrauchbarer** Hintergrund (etwa eine deckend schwarze
# Seite, gemessen mit der alten Weiss-Prüfung Exit 3 und mit der reinen
# Deckungsprüfung Exit 0). Genau dafür steht jetzt die **Tinte**-Prüfung daneben
# (`ink_sigma`): sie misst die Graustufen-Streuung der ganzen Seite und ist
# damit unabhängig vom Farbwert des Hintergrunds. Herkunft der Schwelle, der
# Beschnitt und die bewusst nicht gebaute Kontrastprüfung: siehe Kopfkommentar.
# ---------------------------------------------------------------------------
FAILED=0
PIXELCHECK=0
[ "$HAVE_MAGICK" -eq 1 ] && PIXELCHECK=1

has_alpha() {
  # 0 = kein Alpha, 1 = Alpha vorhanden, 2 = nicht feststellbar
  if [ "$HAVE_MAGICK" -eq 1 ]; then
    local file="$1" raw name
    raw="$(magick identify -format '%[channels]' "$file" 2>/dev/null)" || raw=""
    # Eine leere Kanalkennung ist **kein** "kein Alpha": das wäre ein
    # durchgewinkter Befund, weil das `identify` gerade fehlschlug.
    [ -n "$raw" ] || return 2
    # `%[channels]` liefert "gray  2.0" / "srgba  4.0" — der Name steht vorn.
    set -- $raw
    name="$(printf '%s' "$1" | tr 'A-Z' 'a-z')"
    # **Explizite Listen, kein Muster.** Das frühere `*a*` war ein Muster und
    # traf den Buchstaben `a` in "**g**r**a**y" — eine reine Graustufen-Rasterung
    # wurde dadurch als "hat Alpha" gemeldet (gemessen: `magick … txt:` liefert
    # für eine Textseite ohne Bild-XObject `gray  2.0`, Exit 3 bei literal
    # weissem Hintergrund `#FFFFFF`). Alpha-tragend wurde abgemessen: `graya`
    # (gray+alpha, auch Bilevel-mit-Alpha), `srgba` (auch PaletteAlpha),
    # `lineargraya` (`-colorspace RGB`); alpha-frei: `gray`, `srgb`, `rgb`,
    # `lineargray`. Die übrigen Namen stammen aus ImageMagicks Channel-
    # Vokabular (CMYKA, BGR, ABGR, …) und sind nicht je einzeln reproduziert —
    # sie stehen hier, damit ein unerwarteter Farbraum **nicht** stillschweigend
    # als "kein Alpha" durchgeht: er landet im `*)`-Zweig und wird gemeldet.
    case "$name" in
      srgba | rgba | abgr | argb | bgra | graya | lineargraya | cmyka | \
        alpha | indexalpha | palettealpha)
        return 1 ;;
      gray | lineargray | srgb | linearsrgb | rgb | bgr | cmyk | cmy | \
        ycbcr | xyz | lab | lch | hsl | hsb | index | palette | \
        bilevel | mono | rec601luma | rec709luma)
        return 0 ;;
      *)
        # Unbekannte Kanalkennung: **fail-closed**. Sie wird nicht als
        # "kein Alpha" durchgewinkt, sondern als nicht feststellbar gemeldet —
        # derselbe Ausgang, den der Lauf ohne magick/sips ohnehin nimmt.
        return 2 ;;
    esac
  fi
  if [ "$HAVE_SIPS" -eq 1 ]; then
    case "$(sips -g hasAlpha "$1" 2>/dev/null | tail -1 | tr -d '[:space:]')" in
      *hasAlpha:no*) return 0 ;;
      *hasAlpha:yes*) return 1 ;;
    esac
  fi
  return 2
}

printf '\n%-36s %-12s %-10s %-34s %-10s %s\n' \
  "DATEI" "GRÖSSE" "KANÄLE/ALPHA" "PIXEL (2,2)" "ECKALPHA" "TINTE (GRAU-σ)"
printf '%-36s %-12s %-10s %-34s %-10s %s\n' \
  "------------------------------------" "------------" "----------" \
  "----------------------------------" "----------" "---------------------"

N=0
for out in $STEP2_FILES; do
  N=$((N + 1))
  # Stufe 1 und Stufe 2 werden in derselben Reihenfolge gebaut, also lässt sie
  # sich positionsgleich paaren.
  src=""
  if [ "$METHOD" = "magick" ]; then
    i=0
    for f in $STEP1_FILES; do
      i=$((i + 1))
      [ "$i" -eq "$N" ] && src="$f"
    done
  fi

  if [ "$PIXELCHECK" -eq 1 ]; then
    out_dim="$(magick identify -format '%wx%h' "$out")"
    out_ch="$(magick identify -format '%[channels]' "$out")"
    out_px="$(pixel_at "$out" 2 2)"
  elif [ "$HAVE_SIPS" -eq 1 ]; then
    out_dim="$(sips -g pixelWidth -g pixelHeight "$out" 2>/dev/null \
      | tail -2 | sed 's/.*: *//' | tr '\n' 'x' | sed 's/x$//')"
    out_ch="$(sips -g hasAlpha "$out" 2>/dev/null | tail -1 | sed 's/.*: *//')"
    out_px="(nicht geprüft: kein magick)"
  else
    out_dim="?"; out_ch="?"; out_px="(nicht geprüft: kein magick)"
  fi

  # Die Eckpixel-Opazität wird **einmal** gemessen und für Tabelle *und* Urteil
  # benutzt, damit beide nie auseinanderlaufen können. Gemessen wird über
  # `corner_alpha_measure` (einzige Aufrufstelle von `corner_alpha`); die Zelle
  # der Tabelle kommt aus `alpha_cell`, derselben Formatierung wie in Stufe 1.
  out_a="(nicht geprüft: kein magick)"
  out_cell="$out_a"
  corner_rc=0
  if [ "$PIXELCHECK" -eq 1 ]; then
    corner_alpha_measure "$out"
    out_a="$CORNER_ALPHA_VALUE"
    corner_rc="$CORNER_ALPHA_RC"
    out_cell="$(alpha_cell "$out_a" "$corner_rc")"
  fi

  # Die Tinte wird **einmal** gemessen und für Tabelle *und* Urteil benutzt,
  # damit beide nie aueinanderlaufen können — dieselbe Begründung, dieselbe
  # Hülle: `ink_sigma_measure` ist die einzige Aufrufstelle von `ink_sigma`, die
  # Zelle kommt aus `ink_cell`. **Nur auf Stufe 2**: σ ist die Eigenschaft des
  # fertigen Bildes, und eine zweite Messung auf Stufe 1 wäre genau die zweite
  # Aufrufstelle, an der `corner_alpha` einmal auseinandergelaufen ist. In der
  # Stufe-1-Zeile steht deshalb `-` — dort steht der Nachweis der Deckung, nicht
  # der Tinte.
  out_s="-"
  out_ink_cell="$out_s"
  # **Vorgabe ist 2, nicht 0.** Ohne magick wurde nicht gemessen, und "nicht
  # gemessen" ist nicht "Inhalt vorhanden": der Status 0 als Anfangswert liest
  # das Fehlen einer Messung als ein positives Ergebnis. Genau dieser Fehler
  # wäre hier passiert — `ink_rc=0` hätte auf der gs-only-Route eine schwarze
  # Leerseite mit Exit 0 durchgewinkt.
  ink_rc=2
  if [ "$PIXELCHECK" -eq 1 ]; then
    ink_sigma_measure "$out"
    out_s="$INK_SIGMA_VALUE"
    ink_rc="$INK_SIGMA_RC"
    out_ink_cell="$(ink_cell "$out_s" "$ink_rc")"
  else
    out_ink_cell="(nicht messbar: kein magick)"
  fi

  if [ -n "$src" ] && [ "$PIXELCHECK" -eq 1 ]; then
    src_dim="$(magick identify -format '%wx%h' "$src")"
    src_ch="$(magick identify -format '%[channels]' "$src")"
    src_px="$(pixel_at "$src" 2 2)"
    # Stufe 1 ist bei einem PDF **ohne gemalten Seitenhintergrund** bewusst
    # transparent (`#FFFFFF00`) — genau der Nachweis, den diese Zeile liefern
    # soll. Der Wert steht deshalb **mit** dem Status in der Tabelle, damit der
    # Unterschied zu Stufe 2 ablesbar ist und nicht als "nicht lesbar"
    # untergeht (der gemessene Fehler, siehe `corner_alpha_measure`).
    corner_alpha_measure "$src"
    src_cell="$(alpha_cell "$CORNER_ALPHA_VALUE" "$CORNER_ALPHA_RC")"
    printf '%-36s %-12s %-10s %-34s %-10s %s\n' \
      "  $(basename "$src")" "$src_dim" "$src_ch" "$src_px" "$src_cell" '-'
    printf '  %-34s %-12s %-10s %-34s %-10s %s\n' \
      "-> $(basename "$out")" "$out_dim" "$out_ch" "$out_px" "$out_cell" "$out_ink_cell"
    if [ "$src_dim" != "$out_dim" ]; then
      printf '  FEHLER: die Auflösung hat sich geändert (%s -> %s).\n' "$src_dim" "$out_dim" >&2
      FAILED=1
    fi
  else
    printf '%-36s %-12s %-10s %-34s %-10s %s\n' \
      "$(basename "$out")" "$out_dim" "$out_ch" "$out_px" "$out_cell" "$out_ink_cell"
  fi

  # `has_alpha` liefert 0/1/2; `&& … || …` fängt den Status, ohne dass `set -e`
  # den Lauf abbricht.
  alpha_rc=0
  has_alpha "$out" && alpha_rc=0 || alpha_rc=$?
  case "$alpha_rc" in
    0) : ;;
    1) printf '  FEHLER: %s hat noch einen Alpha-Kanal — der Hintergrund ist NICHT erzwungen.\n' "$(basename "$out")" >&2
       FAILED=1 ;;
    *) printf '  FEHLER: der Alpha-Zustand von %s war nicht feststellbar (unbekannte Kanalkennung, oder weder magick noch sips im PATH).\n' "$(basename "$out")" >&2
       FAILED=1 ;;
  esac

  # Postcondition: der Hintergrund muss **deckend** sein. Sein Farbwert ist
  # unerheblich — ein deckendes Volldruck-Badge ist ein gültiges Ergebnis.
  case "$corner_rc" in
    0) : ;;
    1) printf '  FEHLER: der Hintergrund in %s ist NICHT opak — der Eckpixel (2,2) hat Alpha %s (%s).\n' \
         "$(basename "$out")" "$out_a" "$out_px" >&2
       printf '         Der Seitenbereich ist teilweise oder ganz transparent; wie er später\n' >&2
       printf '         aussieht, entscheidet dann der Betrachter, nicht dieser Lauf.\n' >&2
       FAILED=1 ;;
    2) if [ "$PIXELCHECK" -eq 1 ]; then
         printf '  FEHLER: die Opazität des Hintergrunds in %s war nicht feststellbar (Bild kleiner als 3x3, oder magick konnte den Eckpixel nicht lesen).\n' "$(basename "$out")" >&2
         FAILED=1
       fi ;;
  esac

  # Zweite Postcondition: die Seite muss **Tinte** tragen. Additiv neben der
  # Deckung und ohne sie anzufassen — beide messen verschiedene Eigenschaften,
  # und nur die Kombination fängt den gemessenen Fehler (opak **und** leer).
  #
  # Drei Ausgänge, drei Meldungen, wie bei `corner_alpha`:
  #   0  Inhalt vorhanden  — der Wert steht in der Tabelle, hier ist nichts zu sagen
  #   1  LEER              — **Fehler**, der Lauf bricht ab
  #   2  nicht messbar     — **fail-closed**, kein Durchwinken
  #
  # **Ausgang 2 endet hier anders als bei der Deckung, und der Unterschied ist
  # gemessen.** Ohne magick wird die Deckung weiterhin nur übersprungen (das ist
  # der bestehende Stand und wird nicht angefasst) — sie prüft einen Defekt, den
  # der Rasterweg **selbst** erzeugt, und der `gs`-Weg hat nachweislich keinen
  # Alpha-Kanal zum Entfernen. Die Tinte ist anders: sie ist eine Eigenschaft der
  # **Quelldatei**, und kein Rasterweg verändert daran etwas. Gemessen auf der
  # gs-only-Route: eine komplett schwarze Leerseite lief mit Exit **0** durch,
  # weil `sips` keine Statistik kann und der Lauf das als "geprüft" behandelte.
  # Genau der Fall, den diese Postcondition verhindern soll, wäre damit
  # stummschweigend wieder zugelassen — und zwar **nur** auf der Route, auf der
  # niemand ein Werkzeug nachinstalliert hat. Deshalb gilt hier ausnahmslos:
  # nicht messbar heisst Abbruch.
  case "$ink_rc" in
    0) : ;;
    1) printf '  FEHLER: %s ist LEER — die Graustufen-Streuung der Seite ist %s, unter der Schwelle %s.\n' \
         "$(basename "$out")" "$out_s" "$INK_SIGMA_MIN" >&2
       printf '         Die Seite ist deckend, aber es steht nichts darauf: bei vollschwarzer\n' >&2
       printf '         Tinte entspricht %s einem einzigen dunklen Pixel auf der ganzen Seite.\n' "$INK_SIGMA_MIN" >&2
       printf '         Ein gleichförmiger Hintergrund — weiss, schwarz oder ein Volldruck-\n' >&2
       printf '         Farbton — ergibt exakt 0 und wird hier gemeldet. Das ist der Fall, an\n' >&2
       printf '         dem ein Export 500 unbrauchbare Ausweise liefert, ohne dass jemand\n' >&2
       printf '         eine einzelne Seite ansieht. Werte zum Vergleich: nur der QR auf\n' >&2
       printf '         10 x 10 mm ergibt 0.051, ein einzelnes 4pt-Wort 0.0055.\n' >&2
       FAILED=1 ;;
    2) if [ "$PIXELCHECK" -eq 1 ]; then
         printf '  FEHLER: die Tintenmenge in %s war nicht feststellbar (Bild kleiner als 3x3, unlesbarer Messwert, oder magick konnte die Seite nicht auswerten).\n' "$(basename "$out")" >&2
         printf '         Ohne diesen Wert ist nicht entscheidbar, ob die Seite Inhalt traegt;\n' >&2
         printf '         das wird nicht durchgewinkt.\n' >&2
       else
         printf '  FEHLER: die Tintenmenge in %s war nicht messbar — es gibt kein magick im PATH.\n' "$(basename "$out")" >&2
         printf '         sips kann keine Statistik auswerten, und die Tinte ist eine Eigenschaft\n' >&2
         printf '         der Quelldatei: im Gegensatz zur Deckung kann kein Rasterweg sie\n' >&2
         printf '         einbauen oder umgehen. Ohne diesen Wert bleibt offen, ob die Seite\n' >&2
         printf '         Inhalt traegt, und das wird nicht durchgewinkt.\n' >&2
       fi
       FAILED=1 ;;
  esac
done

if [ "$PIXELCHECK" -ne 1 ]; then
  # Dieser Block wird auch dann gedruckt, wenn der Lauf gleich abbricht (er
  # steht vor dem `die`). Er muss deshalb in **beiden** Fällen wahr sein:
  # ohne magick ist die Deckung nicht geprüft **und** die Tinte nicht messbar.
  printf '\nHINWEIS: die Opazität des Hintergrunds wurde **nicht** geprüft (kein magick im PATH),\n' >&2
  printf 'und die Tintenmenge war nicht messbar — das hat den Lauf oben bereits zum Abbruch\n' >&2
  printf 'gebracht. Geprüft wurde nur das Fehlen des Alpha-Kanals. Für die volle Prüfung:\n' >&2
  printf '  brew install imagemagick\n' >&2
fi

if [ "$FAILED" -ne 0 ]; then
  die "Postcondition verletzt: die Ausgabe ist nicht vertrauenswürdig (siehe die FEHLER-Zeilen oben)" 3
fi

if [ "$KEEP_STEP1" -eq 0 ]; then
  for f in $STEP1_FILES; do
    rm -f "$f"
  done
  printf '\nStufe 1 entfernt. Für den Vergleich Stufe 1 vs. Stufe 2:\n'
  printf '  bash scripts/pdf-to-png-vision.sh <pdf> -o <dir> --keep-step1\n'
else
  printf '\nStufe 1 behalten (--keep-step1).\n'
fi

if [ "$PIXELCHECK" -eq 1 ]; then
  printf '\nOK: %s Seite(n), kein Alpha-Kanal, Hintergrund opak (Eckpixel-Alpha 1),\n' "$PAGES"
  printf '     Tinte auf jeder Seite über der Schwelle %s.\n' "$INK_SIGMA_MIN"
else
  printf '\nOK: %s Seite(n), kein Alpha-Kanal. Opazität und Tintenmenge wurden nicht\n' "$PAGES"
  printf '     geprüft (kein magick).\n'
fi
printf 'PNG(s) für die Vision-Analyse:\n'
for f in $STEP2_FILES; do
  printf '  %s\n' "$f"
done
