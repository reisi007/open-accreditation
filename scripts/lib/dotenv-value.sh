#!/usr/bin/env bash
#
# dotenv_value — read ONE key out of a `.env` file the way Laravel reads it.
#
# WHY THIS FILE EXISTS
# Two scripts need to resolve one environment variable for a NOTE, and both got
# it wrong first (2026-10-03): `scripts/dev-worker.sh` used
# `grep -E '^CACHE_STORE='` and silently missed `export` and leading whitespace
# (Befund F5), and `scripts/e2e-up.sh` grew the same reader a second time and
# inherited the same blindness plus two more (L1). Two copies of a rule are
# two rules that drift — so the rule lives here once and both scripts source it.
#
# The contract is DIFFERENTIAL, and it is a claim about a SET, not about every
# input: for every input in `backend/tests/Feature/DotenvReaderMatchesPhpDotenvTest.php`
# this function returns exactly what `Dotenv\Dotenv::parse()` returns for the
# same file and key, and every input where it does NOT is one of the EIGHT
# classes named below. That test is what keeps both halves true; this file is
# what they are true of. The classes ARE the contract: a difference outside
# them is a bug in this file, not a documented boundary.
#
# The contract has a SECOND half, and it is the one an alphabet decides: it is a
# claim about the SET OF INPUTS THE FUZZ REACHED. "0 unattributed" over an ASCII
# alphabet is not "0 unattributed" over a non-ASCII one — measured 2026-10-03,
# it was not (see the locale class that was removed below). A class list that
# only ever gets checked against ASCII is a list of the ASCII cases.
#
# WHY NOT SHELL OUT TO PHP INSTEAD
# Because then the differential test would compare PHP with PHP and could not
# fail: a reader that is broken *and* shells out to `Dotenv::parse()` would
# still pass. The pin has to be able to go red on a broken reader, so the
# reader is reimplemented here and the test compares the two.
#
# WHAT phpdotenv ACCEPTS (vendor/vlucas/phpdotenv/src/Parser/)
#   * an optional `export ` prefix — but only when a WHITESPACE follows it
#     (`EntryParser::parseName()` requires `ctype_space($name[6])`, so
#     `exportFOO=1` is a key called `exportFOO`, not `FOO`)
#   * a quoted NAME: `"FOO"=1` and `'FOO'=1` are both `FOO`
#   * leading and trailing whitespace around the name and around `=`
#   * values in single or double quotes, and an inline `#` comment after them
#   * an inline `#` comment on an unquoted value — `#` needs no leading space
#   * `\n \r \t \v \f \" \\` inside DOUBLE quotes (single quotes are literal)
#   * the LAST assignment of a key wins
#
# WHAT IT DELIBERATELY DOES NOT IMPLEMENT — EIGHT measured classes, not one
#
# MEASURED (2026-10-03) with a randomized differential against
# `Dotenv\Dotenv::parse()`, 6000 bodies per run, over an alphabet deliberately
# different from the one that produced the original claim — seed 20261003:
# 4308 identical, 683 divergent, 1009 rejected by phpdotenv (6000 total); seed
# 424242: 4278 / 682 / 1040. Every divergent body falls into one of the classes
# below, 0 unattributed. The classes OVERLAP — 504 of the 683 hit more than
# one, because a form feed in front of an unquoted value is both a trim-set
# difference and a comment-lookahead difference.
#
# RE-MEASURED (2026-10-03, Befund R7-1) with a NON-ASCII alphabet: 4000 bodies
# whose byte set contains U+3000, U+2028, U+00A0, U+205F, NEL, an overlong
# encoding of TAB and lone continuation bytes. Two runs of the same reader, same
# bytes, only `LC_ALL` differing: 209 bodies were answered DIFFERENTLY under
# C.UTF-8 than under C. That was a divergence the class list did not name,
# because `[[:space:]]` is LOCALE-DEPENDENT while phpdotenv's trim is a byte set.
# It is NOT a class — it is removed at the source (see `$ws` in the function).
# Re-measured with it removed, TWO seeds: 4000 bodies seed 20261003 and 4000
# bodies seed 424242, each read under both locales. BEFORE the fix 209 and 196
# locale-dependent; AFTER it 0 and 0 — and not ONE answer changed under
# `LC_ALL=C` in either seed, so the byte set removes the locale dependence and
# nothing else. The letter M2 below is the one genuine ADDITION that fuzz found;
# the other residuals are the seven classes below wearing more than one of them at
# a time, which is the overlap this header already warns about.
#
# The earlier claim here was "exactly one difference", the multiline case M. An
# independent fuzz over a different alphabet found five further classes, and a
# sixth (A) came out of a targeted probe on top of it; the claim was wrong, and
# it is REPLACED by this list rather than softened into "the forms we thought
# of". The letters below are labels, nothing more.
#
#   M — MULTILINE, UNBALANCED (`Lines::looksLikeMultilineStart()`). An unbalanced
#       `="` swallows the rest of the file and phpdotenv emits no key at all.
#       MEASURED: `K=";<TAB>` → phpdotenv emits nothing, this reader returns
#       `;` — the tab is already gone, trimmed off with the rest of the value
#       before it is parsed. (An earlier version of this comment claimed the
#       reader returns `;\t`; it does not.)
#   M2 — MULTILINE, BALANCED: a quoted value that CLOSES on a later line.
#       phpdotenv joins the lines and keeps every one of them; `read` hands this
#       function one line at a time and the state machine simply ends, so the
#       reader stops at the first line end. MEASURED: `K="a<NL>b"` → phpdotenv
#       `a<NL>b`, this reader `a`. Two things make this its own class and not a
#       footnote to M: phpdotenv ACCEPTS the file (M is the case where it emits
#       nothing at all), and the ESCAPE form agrees — `K="a\nb"` written with a
#       backslash is `610a62` on both sides. The header's class-A note used that
#       ESCAPE form as its evidence that "an interior newline agrees", and a real
#       newline does not: the note has been corrected rather than deleted,
#       because it was true of the bytes it named and false about the class.
#   A — A VALUE WHOSE LAST BYTE IS A NEWLINE. `dotenv_value` prints with
#       `printf '%s'` and loses nothing itself, but BOTH callers use it as
#       `$(dotenv_value …)`, and command substitution strips trailing
#       newlines. MEASURED: `K="sync\n"` → phpdotenv `sync<NL>`, reader-as-called
#       `sync`. The ESCAPE form of an interior newline agrees (`K="a\nb"` with a
#       backslash); a REAL one does not, and that is class M2 above.
#   E — A QUOTE BYTE INSIDE AN UNQUOTED VALUE. phpdotenv's `UNQUOTED_STATE`
#       (`EntryParser::processToken`) appends `"` and `'` as ordinary bytes and
#       stays unquoted; this reader enters its quoted state, drops the byte, and
#       can truncate at it. MEASURED: `K=x"y` → `x"y` vs `xy`; `K=x'y` →
#       `x'y` vs `xy`; `K=syn"` → `syn"` vs `syn`.
#   V — `${VAR}` INTERPOLATION. `Dotenv\Dotenv::parse()` runs the entries
#       through `Loader\Loader::load()` → `Resolver::resolve()`, which resolves
#       `${NAME}` when `NAME` is among the SAME entries — the very oracle the
#       differential test compares against. This reader never resolves.
#       MEASURED: `FOO=sync` + `K=${FOO}` → phpdotenv `sync`, reader `${FOO}`.
#       A bare `$NAME` and `${NAME:-default}` are NOT resolved by phpdotenv
#       either and agree with this reader, which is why only the brace form is
#       a class.
#   B — A LONE `\r` IS A LINE SEPARATOR to phpdotenv: `Parser::parse()` splits
#       on `/(\r\n|\n|\r)/`. `read` splits on `\n` only, and the trailing-`\r`
#       strip below sits at end of line, so it cannot help here. MEASURED:
#       `K=sync<CR>V` → phpdotenv `sync`, reader `sync<CR>V`. An ordinary CRLF
#       file is unaffected: there every `\r` precedes a `\n`.
#   C — CONTROL WHITESPACE. phpdotenv trims ` \n\r\t\0\x0B` — NOT `\f`
#       (`EntryParser::splitStringIntoParts()`), and its value lexer treats any
#       `ctype_space()` byte as the whitespace that may precede an inline `#`
#       comment. The byte set in the function matches `\f` as well, and the value
#       state machine below knows only space and tab. MEASURED: `K=<FF>foo` →
#       phpdotenv `<FF>foo`, reader `foo`; `K=.?<VT> # c` → phpdotenv `.?`,
#       reader `.?<VT> # c`.
#   N — A NUL BYTE cannot be held by a bash variable at all, so `read` DROPS it
#       and carries on. MEASURED: `K=sy<NUL>nc` → phpdotenv `sy<NUL>nc`
#       (5 bytes), reader `sync` (4 bytes).
#
# REACHABILITY FOR THE TWO KEYS THESE SCRIPTS ASK FOR. `QUEUE_CONNECTION` and
# `CACHE_STORE` hold connection and store NAMES, and for THOSE TWO keys the
# classes above cannot be reached by a `backend/.env` a developer writes: a name
# carries no newline, no quote byte and no control byte; a quoted name spanning
# two lines (M2) is not a connection name in any form; the line the CI job
# writes carries no lone `\r`; and nobody writes `${…}` into either key. That is
# a statement ABOUT THESE TWO KEYS — it is not a property of the reader, and it
# is therefore written here and pinned per class in the test, not asserted as a
# general claim.
#
# AND the LOCALE is not a class at all, which is the point of the byte set: the
# reader's answer must not depend on `LC_ALL`. Measured before the fix, 209 of
# 4000 non-ASCII bodies did. A class is something the reader cannot do about
# inside a grammar; this was the environment deciding for it.
#
# ONE class IS reachable for them anyway, and it is V: `.env` is legal
# phpdotenv input, so `QUEUE_CONNECTION=${SOME_VAR}` with `SOME_VAR` set
# earlier in the same file is legal, Laravel resolves it, and this reader does
# not — the note then names a connection the app never resolves. It is pinned
# as a documented divergence
# (`DotenvReaderMatchesPhpDotenvTest::test_the_documented_divergence_classes`)
# rather than implemented: variable resolution in the shell is a larger surface
# than a NOTE needs, and the note is a note.
#
# WHAT phpdotenv REJECTS
#   * a value phpdotenv REJECTS (`K=a b`, whitespace inside an unquoted value)
#     stops the parse where phpdotenv would raise, and returns what it had.
#     Laravel refuses such a file outright, so there is no resolved connection
#     for this note to be right about.
#
# USAGE
#   dotenv_value <file> <key>      # prints the value, or nothing
#
# No output on a missing file or an unset key, and it never fails: it is a note,
# not a guard. Callers apply their own fallback.

dotenv_value() {
    local file="$1" key="$2" line rest name value found=''

    # ASCII WHITESPACE, AS BYTES. NOT `[[:space:]]` — that class is LOCALE-
    # DEPENDENT: glibc decodes it through the locale's charset table, so under
    # LC_ALL=C.UTF-8 it also matches U+3000, U+2028, U+00A0 and an overlong
    # encoding of TAB, while phpdotenv's trim is a byte set (`" \n\r\t\0\x0B"`,
    # `EntryParser::splitStringIntoParts()`) that none of those is.
    #
    # MEASURED 2026-10-03 (Befund R7-1), 4000 generated .env bodies over an
    # alphabet that CONTAINS those bytes, the same reader run twice:
    #   with `[[:space:]]`   209 of 4000 bodies were answered DIFFERENTLY under
    #                        LC_ALL=C.UTF-8 than under LC_ALL=C (seed 20261003;
    #                        196 of 4000 on seed 424242), and 44 of those agreed
    #                        with phpdotenv under C and disagreed under C.UTF-8 —
    #                        a divergence produced by the LOCALE alone, with the
    #                        same function and the same bytes.
    #   with this byte set    0 of 4000 in BOTH seeds, and NOT ONE answer changed
    #                        under LC_ALL=C in either — so the byte set removes the
    #                        locale dependence and nothing else.
    #
    # The set is the ASCII members of `[[:space:]]`, `\f` INCLUDED: dropping `\f`
    # would not be neutral, it would silently delete one half of class C below.
    # It is `local`, so it cannot leak into a caller that sorts or prints.
    local ws=$' \t\n\r\v\f'

    [ -f "$file" ] || return 0

    while IFS= read -r line || [ -n "$line" ]; do
        # CRLF: strip a trailing `\r`. MEASURED 2026-10-03: this line is REDUNDANT —
        # deleting it changes no pinned result, because both trims below also
        # match `\r` and would remove the same byte. It is kept as the cheap guard
        # for the day someone narrows that trim set, and the comment says so
        # because a line whose comment claims it is load-bearing when a
        # measurement says it is not is worse than no comment.
        #
        # It is a strip of a TRAILING `\r` only. A lone `\r` elsewhere in the line
        # is a line separator to phpdotenv and not to `read` — that is class B.
        line="${line%$'\r'}"

        # `Lines::isCommentOrWhitespace()` — a blank line or one whose first
        # non-space character is `#` is not an entry at all.
        rest="${line#"${line%%[!"$ws"]*}"}"
        [ -n "$rest" ] || continue
        [ "${rest:0:1}" = '#' ] && continue

        # `EntryParser::splitStringIntoParts()` — split on the FIRST `=`, and a
        # line without one is a name with no value, which is not an assignment.
        rest="${line#*=}"
        [ "$rest" != "$line" ] || continue
        name="${line%%=*}"

        name="${name#"${name%%[!"$ws"]*}"}"
        name="${name%"${name##*[!"$ws"]}"}"

        # `export` is a prefix only when a whitespace follows it — the condition
        # `EntryParser::parseName()` states. Stripping it unconditionally would
        # read a key `exportFOO` as `FOO`, i.e. invent a value that is not there.
        #
        # The test is `${name:6}` against the BYTE SET, anchored with a trailing
        # `*`. Two traps, both measured, and the first one is worth the whole
        # comment: a bare bracket class is an UNANCHORED glob inside `[[ ]]`, so
        # `[[ $x == [$ws] ]]` does not ask "does the first byte belong to this set"
        # — it asks "does the string CONTAIN one of these bytes ANYWHERE", and
        # `exportFOO=1` contains a space nowhere yet is read as a prefixed key
        # `FOO` with a value that is not there. `=~ ^[[:space:]]` was anchored and
        # was right about WHERE; it was wrong about WHICH BYTES, being the same
        # locale-dependent class as the trims above. `== ["$ws"]*` is both.
        if [ "${#name}" -gt 6 ] && [ "${name:0:6}" = 'export' ] && [[ "${name:6}" == ["$ws"]* ]]; then
            name="${name:6}"
            name="${name#"${name%%[!"$ws"]*}"}"
        fi

        # A quoted name is the same name.
        if [ "${#name}" -ge 3 ]; then
            case "$name" in
                \"*\") name="${name:1:${#name}-2}" ;;
                \'*\') name="${name:1:${#name}-2}" ;;
            esac
        fi

        [ "$name" = "$key" ] || continue

        # `splitStringIntoParts()` trims the value before it is parsed — with
        # ` \n\r\t\0\x0B`, which is NOT `\f`, while the byte set above matches
        # `\f` as well. That is the first half of class C in the header.
        value="${rest#"${rest%%[!"$ws"]*}"}"
        value="${value%"${value##*[!"$ws"]}"}"

        # Last assignment wins: keep looping and let a later line overwrite.
        found="$(dotenv_parse_value "$value")"
    done < "$file"

    printf '%s' "$found"
}

# The value state machine of `EntryParser::parseValue()`: unquoted, single
# quoted, double quoted (with escapes), and whitespace-separated. `$out` is
# built byte by byte, which is phpdotenv's own unit — its lexer steps over bytes.
#
# `dotenv_value()` is the only caller; it is not part of the public surface.
dotenv_parse_value() {
    local v="$1" out='' i=0 n=${#1} c state=0

    [ -n "$v" ] || return 0

    while [ "$i" -lt "$n" ]; do
        c="${v:i:1}"

        case "$state" in
            # 0 = unquoted (phpdotenv's INITIAL/UNQUOTED share this treatment).
            # NOT the same treatment: in `UNQUOTED_STATE` phpdotenv appends a
            # quote byte as an ordinary byte and stays unquoted, while this arm
            # switches state and drops it. That is class E in the header.
            0)
                case "$c" in
                    '#') break 2 ;;
                    "'") state=1 ;;
                    '"') state=2 ;;
                    ' ' | $'\t') state=3 ;;
                    *) out+="$c" ;;
                esac
                ;;
            # 1 = inside single quotes: everything is literal, `\` included
            1)
                if [ "$c" = "'" ]; then state=3; else out+="$c"; fi
                ;;
            # 2 = inside double quotes: `\"` ends nothing, backslash escapes
            2)
                case "$c" in
                    '"') state=3 ;;
                    '\') state=4 ;;
                    *) out+="$c" ;;
                esac
                ;;
            # 3 = whitespace after a value or a closing quote. phpdotenv allows
            #     only more whitespace and a `#` comment here and raises on
            #     anything else (`K=a b`, `K="a"x`); we stop where it would
            #     raise, which is the same place it stops accumulating.
            3)
                case "$c" in
                    '#') break 2 ;;
                    ' ' | $'\t') ;;
                    *) break 2 ;;
                esac
                ;;
            # 4 = the character after a backslash inside double quotes
            4)
                case "$c" in
                    '"') out+='"' ;;
                    '\') out+='\' ;;
                    f) out+=$'\f' ;;
                    n) out+=$'\n' ;;
                    r) out+=$'\r' ;;
                    t) out+=$'\t' ;;
                    v) out+=$'\v' ;;
                    *) break 2 ;;
                esac
                state=2
                ;;
        esac

        i=$((i + 1))
    done

    printf '%s' "$out"
}