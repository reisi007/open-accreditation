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
# The contract is DIFFERENTIAL, not "close enough": for every input in
# `backend/tests/Feature/DotenvReaderMatchesPhpDotenvTest.php`, this function
# must return exactly what `Dotenv\Dotenv::parse()` returns for the same file
# and key. That test is what keeps the claim true; this file is what it is true
# of.
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
# WHAT IT DELIBERATELY DOES NOT IMPLEMENT, and why that is safe here
#   * `KEY="multi` continued over following lines (`Lines::looksLikeMultilineStart`)
#     and `$VAR` interpolation (`RepositoryBuilder`). Both are real phpdotenv
#     features this reader ignores. Neither can occur for a key whose value is
#     a connection name (`CACHE_STORE`, `QUEUE_CONNECTION`); if one did, Laravel
#     would resolve it to something this note cannot, which is exactly why the
#     note names the connection it found rather than asserting one.
#     MEASURED (2026-10-03, 8000 randomized bodies against
#     `Dotenv\Dotenv::parse()`): 4731 identical, 3268 inputs phpdotenv rejects
#     outright, and **exactly one** difference — `K=";\t`, an unbalanced quote
#     that phpdotenv treats as an unterminated multiline and therefore never
#     emits at all, while this reader returns `;\t`. The reader's own claim is
#     "identical wherever phpdotenv answers", and that is what is measured.
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

    [ -f "$file" ] || return 0

    while IFS= read -r line || [ -n "$line" ]; do
        # CRLF: a `\r` would otherwise end up inside the last value.
        line="${line%$'\r'}"

        # `Lines::isCommentOrWhitespace()` — a blank line or one whose first
        # non-space character is `#` is not an entry at all.
        rest="${line#"${line%%[![:space:]]*}"}"
        [ -n "$rest" ] || continue
        [ "${rest:0:1}" = '#' ] && continue

        # `EntryParser::splitStringIntoParts()` — split on the FIRST `=`, and a
        # line without one is a name with no value, which is not an assignment.
        rest="${line#*=}"
        [ "$rest" != "$line" ] || continue
        name="${line%%=*}"

        name="${name#"${name%%[![:space:]]*}"}"
        name="${name%"${name##*[![:space:]]}"}"

        # `export` is a prefix only when a whitespace follows it — the condition
        # `EntryParser::parseName()` states. Stripping it unconditionally would
        # read a key `exportFOO` as `FOO`, i.e. invent a value that is not there.
        if [ "${#name}" -gt 6 ] && [ "${name:0:6}" = 'export' ] && [[ "${name:6}" =~ ^[[:space:]] ]]; then
            name="${name:6}"
            name="${name#"${name%%[![:space:]]*}"}"
        fi

        # A quoted name is the same name.
        if [ "${#name}" -ge 3 ]; then
            case "$name" in
                \"*\") name="${name:1:${#name}-2}" ;;
                \'*\') name="${name:1:${#name}-2}" ;;
            esac
        fi

        [ "$name" = "$key" ] || continue

        # `splitStringIntoParts()` trims the value before it is parsed.
        value="${rest#"${rest%%[![:space:]]*}"}"
        value="${value%"${value##*[![:space:]]}"}"

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
            # 0 = unquoted (phpdotenv's INITIAL/UNQUOTED share this treatment)
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