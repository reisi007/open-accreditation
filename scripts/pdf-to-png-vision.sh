#!/usr/bin/env bash
#
# PDF → PNG für die visuelle Verifikation (PDF-VISION-Pipeline).
#
# Warum es dieses Skript gibt: ein Badge-PDF (dompdf) hat **keinen** gemalten
# Seitenhintergrund — der Hintergrund ist transparent. Ein Rasterer liefert
# deshalb eine PNG mit Alpha-Kanal, und wie die transparenten Pixel am Ende
# aussehen, entscheidet der **Konsument**, nicht der Rasterer:
#
#   * Ghostscript-via-`magick` legt sie als `#FFFFFF00` ab (weiss, alpha 0).
#     Wer die Alpha ignoriert, sieht weiss; wer auf schwarz komponiert, sieht
#     eine schwarze Seite — und **die schwarze Badgeschrift wird unsichtbar**
#     (gemessen: 0 sichtbare Tintepixel in einem 640x160-Textband).
#   * `sips` legt sie als `#00000000` ab (schwarz, alpha 0). Wer die Alpha
#     ignoriert, sieht eine **literalschwarze** Seite (gemessen: 91 % der
#     Pixel exakt #000000) — ebenfalls ohne Badgeschrift.
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
# Eckpixels (2,2), nicht dessen Farbe. Beabsichtigt ist die Wirkung, die der
# transparente dompdf-Hintergrund sonst hätte: die Darstellung darf nicht vom
# Betrachter abhängen. Die Farbe ist bewusst **nicht** Teil der Postcondition,
# weil ein deckendes Volldruck-Badge ein gültiges Ergebnis ist.
#
# Die damit unvermeidbare Grenze: eine **deckende, aber unbrauchbare** Seite
# besteht diese Prüfung. Eine komplett schwarze oder einfarbig grüne Seite ist
# opak und wäre "grün". Das ist kein Loch im Auftrag, sondern eine bewusste
# Abgrenzung — die Weiss-Prüfung konnte einendeckenden schwarzen Hintergrund
# immerhin melden, und der Unterschied ist ehrlich nicht mehr rekonstruierbar:
# "der Hintergrund ist eine deckende Farbe" und "der Hintergrund ist Papier"
# sehen am Eckpixel gleich aus. Wer das braucht, muss es woanders prüfen: die
# Tabelle gibt zu jeder Seite Kanal, Pixelwert und Eckalpha aus, und die
# Vision-Analyse beurteilt die gerenderte Seite ohnehin. Eine *farbliche*
# Mindestanforderung wäre eine andere, strengere Postcondition — und müsste
# dann auch einen Volldruck-Badge als gültig zulassen.
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
# Exit-Codes:
#   0  Pipeline durchgelaufen, Ausgabe verifiziert (kein Alpha, Hintergrund opak)
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
# Zweck war, dass der vom dompdf-Hintergrund gelassene transparente Bereich
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
# Fehler enden. Was sie dafür nicht mehr erkennt, ist ein **undurchsichtiger,
# unbrauchbarer** Hintergrund (etwa eine deckend schwarze Seite); siehe die
# Einschränkung im Kopfkommentar.
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

printf '\n%-36s %-12s %-10s %-34s %s\n' "DATEI" "GRÖSSE" "KANÄLE/ALPHA" "PIXEL (2,2)" "ECKALPHA"
printf '%-36s %-12s %-10s %-34s %s\n' \
  "------------------------------------" "------------" "----------" "----------------------------------" "--------"

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
  # benutzt, damit beide nie auseinanderlaufen können. `|| …` fängt den Status,
  # ohne dass `set -e` den Lauf abbricht.
  out_a="(nicht geprüft: kein magick)"
  corner_rc=0
  if [ "$PIXELCHECK" -eq 1 ]; then
    out_a=""
    corner_rc=0
    out_a="$(corner_alpha "$out")" || corner_rc=$?
  fi

  if [ -n "$src" ] && [ "$PIXELCHECK" -eq 1 ]; then
    src_dim="$(magick identify -format '%wx%h' "$src")"
    src_ch="$(magick identify -format '%[channels]' "$src")"
    src_px="$(pixel_at "$src" 2 2)"
    # Stufe 1 ist bei einem dompdf-PDF bewusst transparent (`#FFFFFF00`); der
    # Wert steht in der Tabelle, damit der Unterschied zu Stufe 2 ablesbar ist.
    src_a="$(corner_alpha "$src")" || src_a="(nicht lesbar)"
    printf '%-36s %-12s %-10s %-34s %s\n' "  $(basename "$src")" "$src_dim" "$src_ch" "$src_px" "$src_a"
    printf '  %-34s %-12s %-10s %-34s %s\n' "-> $(basename "$out")" "$out_dim" "$out_ch" "$out_px" "$out_a"
    if [ "$src_dim" != "$out_dim" ]; then
      printf '  FEHLER: die Auflösung hat sich geändert (%s -> %s).\n' "$src_dim" "$out_dim" >&2
      FAILED=1
    fi
  else
    printf '%-36s %-12s %-10s %-34s %s\n' "$(basename "$out")" "$out_dim" "$out_ch" "$out_px" "$out_a"
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
done

if [ "$PIXELCHECK" -ne 1 ]; then
  printf '\nHINWEIS: die Opazität des Hintergrunds wurde **nicht** geprüft (kein magick im PATH).\n' >&2
  printf 'Geprüft wurde nur das Fehlen des Alpha-Kanals. Für die volle Prüfung:\n' >&2
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
  printf '\nOK: %s Seite(n), kein Alpha-Kanal, Hintergrund opak (Eckpixel-Alpha 1).\n' "$PAGES"
else
  printf '\nOK: %s Seite(n), kein Alpha-Kanal. Die Opazität wurde nicht geprüft (kein magick).\n' "$PAGES"
fi
printf 'PNG(s) für die Vision-Analyse:\n'
for f in $STEP2_FILES; do
  printf '  %s\n' "$f"
done
