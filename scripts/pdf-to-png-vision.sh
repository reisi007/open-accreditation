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
# Ergebnis (kein Alpha, Eckpixel weiss, Seitenzahl stimmt) und **beendet sich
# ungleich 0**, wenn etwas fehlt. Es gibt bewusst kein `|| true`: ein Skript,
# das ohne `magick` grün durchläuft, ist schlimmer als keines.
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
#   0  Pipeline durchgelaufen, Ausgabe verifiziert (kein Alpha, Eckpixel weiss)
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

# Ist der von `txt:` gemeldete Pixel weiss? `magick … txt:` schreibt ihn als
#
#   0,0: (255)                 #FFFFFF            gray(255)     ← 8 bit, gray
#   0,0: (255,255,255)         #FFFFFF            white         ← 8 bit, srgb
#   0,0: (255,255,255,255)     #FFFFFFFF          white         ← 8 bit, srgba
#   0,0: (65535)               #FFFFFFFFFFFF      gray(255)     ← 16 bit, gray
#   0,0: (65535,65535,65535)   #FFFFFFFFFFFF      white         ← 16 bit, srgb
#
# Die **Komponentenzahl** des Tupels hängt von Farbraum und Bittiefe ab, der
# Wert nicht: das Hex-Feld ist in allen Formen `#FFFFFF`, gefolgt von
# Alpha- bzw. 16-Bit-Anteilen. Deshalb wird das Hex-Feld geprüft und nicht das
# Tupel — die alte Prüfung auf `*255,255,255*` kannte nur den 8-Bit-RGB-Fall
# und meldete eine Graustufen-Rasterung (Tupel `(255)`) fälschlich als
# "Hintergrund nicht erzwungen", obwohl der Pixel literal `#FFFFFF` war.
#
# `#FFFFFF00` (weiss mit Alpha 0) ist damit **nicht** weiss: der durchsichtige
# Hintergrund fällt durch, statt durch ein Muster durchgewinkt zu werden.
pixel_is_white() {
  # 0 = weiss, 1 = nicht weiss (fail-closed)
  local line="$1" hex tuple comp oldifs
  hex="$(printf '%s\n' "$line" \
    | awk '{for (i = 1; i <= NF; i++) if ($i ~ /^#[[:xdigit:]]+$/) {print $i; exit}}' \
    | tr 'a-f' 'A-F')"
  case "$hex" in
    '#FFFFFF' | '#FFFFFFFF' | '#FFFFFFFFFFFF' | '#FFFFFFFFFFFFFFFF') return 0 ;;
  esac
  # Ohne Hex-Feld: **jede** Komponente muss der Maximalwert sein. Ein Muster
  # wäre hier genau der Fehler, den die Kanalliste oben gerade abgeschafft hat.
  tuple="$(printf '%s\n' "$line" | sed -n 's/^[^(]*(\([^)]*\)).*$/\1/p' | tr -d ' ')"
  [ -n "$tuple" ] || return 1
  oldifs="$IFS"
  IFS=','
  for comp in $tuple; do
    case "$comp" in
      255 | 65535) ;;
      *) IFS="$oldifs"; return 1 ;;
    esac
  done
  IFS="$oldifs"
  return 0
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
# Die Postcondition ist: **kein Alpha-Kanal** (der Seitenhintergrund ist dann
# zwangsläufig literal weiss). Ohne diese Prüfung wäre ein fehlgeschlagenes
# `-alpha remove` ein grüner Lauf mit genau dem Defekt, den die Pipeline
# beseitigen soll.
#
# Geprüft wird mit dem, was da ist:
#   * Alpha      — `magick` (%[channels]) oder, ohne magick, `sips -g hasAlpha`
#   * Eckpixel   — nur mit `magick`; ohne magick wird das **laut als nicht
#                  geprüft** ausgewiesen und nicht als bestanden behauptet
#   * Seiten     — `gs` (echte `pdfpagecount`), soweit gs vorhanden
# Ist die Alpha-Prüfung nicht durchführbar, endet der Lauf mit 3 — nicht mit 0.
#
# **Farbraum, nicht Kanalanzahl:** „kein Alpha-Kanal" und „weisser Eckpixel" sind
# Eigenschaften, die unabhängig davon gelten, ob die Rasterung `srgb` oder
# `gray` ist. Ein PDF **mit** Bild-XObject rastern als `srgb` (das setzt den
# Farbraum), ein PDF **ohne** Bild — eine Textseite — rastern als `gray`. Beide
# Prüfungen entscheiden deshalb über **Listen** (Kanalkennung bzw. Hex-Wert), nie
# über ein Muster: das frühere `*a*` traf das `a` in "**g**r**a**y", und die alte
# Eckpixel-Prüfung `*255,255,255*` kannte nur ein RGB-Tripel — eine Graustufen-
# Rasterung liefert `(255)`. Gemessen an `gray-text.pdf` (schwarzer Text auf
# weiss, kein Bild, keine Farbfläche): alter Lauf Exit 3 mit „hat noch einen
# Alpha-Kanal" **und** „Eckpixel ist nicht weiss", obwohl der Pixel literal
# `#FFFFFF` war. Nach der Korrektur: Exit 0, ohne die Prüfung abzuschwächen.
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

printf '\n%-36s %-12s %-10s %s\n' "DATEI" "GRÖSSE" "KANÄLE/ALPHA" "PIXEL (2,2)"
printf '%-36s %-12s %-10s %s\n' "------------------------------------" "------------" "----------" "----------"

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

  if [ -n "$src" ] && [ "$PIXELCHECK" -eq 1 ]; then
    src_dim="$(magick identify -format '%wx%h' "$src")"
    src_ch="$(magick identify -format '%[channels]' "$src")"
    src_px="$(pixel_at "$src" 2 2)"
    printf '%-36s %-12s %-10s %s\n' "  $(basename "$src")" "$src_dim" "$src_ch" "$src_px"
    printf '  %-34s %-12s %-10s %s\n' "-> $(basename "$out")" "$out_dim" "$out_ch" "$out_px"
    if [ "$src_dim" != "$out_dim" ]; then
      printf '  FEHLER: die Auflösung hat sich geändert (%s -> %s).\n' "$src_dim" "$out_dim" >&2
      FAILED=1
    fi
  else
    printf '%-36s %-12s %-10s %s\n' "$(basename "$out")" "$out_dim" "$out_ch" "$out_px"
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

  if [ "$PIXELCHECK" -eq 1 ]; then
    if pixel_is_white "$out_px"; then :; else
      printf '  FEHLER: Eckpixel in %s ist nicht weiss (%s) — der Hintergrund ist nicht erzwungen.\n' "$(basename "$out")" "$out_px" >&2
      FAILED=1
    fi
  fi
done

if [ "$PIXELCHECK" -ne 1 ]; then
  printf '\nHINWEIS: der weisse Eckpixel wurde **nicht** geprüft (kein magick im PATH).\n' >&2
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
  printf '\nOK: %s Seite(n), kein Alpha-Kanal, Eckpixel weiss.\n' "$PAGES"
else
  printf '\nOK: %s Seite(n), kein Alpha-Kanal. Der Eckpixel wurde nicht geprüft (kein magick).\n' "$PAGES"
fi
printf 'PNG(s) für die Vision-Analyse:\n'
for f in $STEP2_FILES; do
  printf '  %s\n' "$f"
done
