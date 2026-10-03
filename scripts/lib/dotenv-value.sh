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
# same file and key, and every input where it does NOT is one of the TEN
# classes named below — or the one MEASURED RESIDUE further down, which is
# named here for the same reason and is deliberately NOT in the pinned class
# list yet. That test is what keeps the pinned half true; this file is what it is
# true of. The classes ARE the contract: a difference outside them and outside
# that residue is a bug in this file, not a documented boundary.
#
# The contract has a SECOND half, and it is the one an alphabet decides: it is a
# claim about the SET OF INPUTS THE FUZZ REACHED. "0 unattributed" over an ASCII
# alphabet is not "0 unattributed" over a non-ASCII one — measured 2026-10-03,
# it was not (see the locale class that was removed below). A class list that
# only ever gets checked against ASCII is a list of the ASCII cases. And the
# third run of that lesson is the tenth class below: the invalid-UTF-8 fuzz of
# 2026-10-03 left two differences, and the second of them needs nothing but
# ASCII. It was reported as a residue first and PROMOTED to a class afterwards —
# the two states are the same claim about the same bytes, and the class list is
# where a claim gets pinned.
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
# WHAT IT DELIBERATELY DOES NOT IMPLEMENT — TEN measured classes plus ONE
# measured residue, not one
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
# the other residuals are the nine classes below wearing more than one of them
# at a time, which is the overlap this header already warns about.
#
# RE-FUZZED with an INVALID-UTF-8 alphabet (Befund R8-1, THREE runs of 3000
# bodies: seed 20261003 and seed 424242 over `QUEUE_CONNECTION`, seed 777 over
# `CACHE_STORE`): 214 / 194 / 190 divergent bodies, every one of them reduced to
# a minimal witness, and 0 unattributed once the NINTH and TENTH classes below and
# the residue further down are counted — 1231 / 1280 / 1249 of the 9000 bodies are
# refused by phpdotenv outright. That this run found two classes and a residue
# where the previous ones found one class and nothing is not a contradiction: the
# R7 alphabet's "lone continuation bytes" are `0x80`-`0xBF`, and those do NOT
# trigger residue 1 (measured: no flip for any of `0x80`-`0xC1`) — and the tenth
# class, the one a bare ASCII `K=sync<NL>K` reaches, needed a RUN rather than a
# cleverer alphabet.
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
#   U — AN INVALID UTF-8 BYTE TOGETHER WITH A `$`. `Dotenv\Dotenv::parse()`
#       runs the entries through `Loader\Loader::load()` → `Resolver::resolve()`,
#       and `resolve()` hands the value straight back while `$vars === []`
#       (`Loader/Resolver.php:43-45`) — that is why the reader is byte-exact for
#       every value without a `$`. A `$` in an UNQUOTED or DOUBLE-QUOTED value
#       IS recorded as a var position (`EntryParser::processToken`,
#       `INITIAL_STATE`/`UNQUOTED_STATE`/`DOUBLE_QUOTED_STATE` return `$var =
#       true`), so the value is then cut with `Str::substr()` — which is
#       `mb_substr(…, 'UTF-8')` (`Util/Str.php:113-116`) — and mbstring
#       replaces every byte that is not valid UTF-8 with
#       `mb_substitute_character()`. MEASURED: `K=\x80$` → phpdotenv `3f24`
#       (`?$`, `0x3F` is the substitute character; `mb_substitute_character()` is
#       `int(63)`), this reader `8024`. The substitution measured on its own:
#       `mb_substr("\x80$", 0, 2, 'UTF-8')` → `3f24`.
#       BOTH halves are needed and each alone AGREES, which is what makes this
#       one class and not "invalid bytes are mangled": with no `$` there is no
#       `Str::substr()` call and the value survives byte-transparent
#       (`K=\x80` → `80` on both sides, and likewise for every other invalid
#       byte tried), and a `$` inside SINGLE quotes is not a var position
#       (`EntryParser::processToken` returns `$var = false` in
#       `SINGLE_QUOTED_STATE`), so `K='\x80$'` → `8024` on both sides.
#       It is NOT class V: `K=\x80$` resolves nothing — there is no `${…}` in
#       it — and what differs is one falsified byte, not a substituted variable.
#       Befund R8-1.
#   W — A BARE NAME LINE CLEARS THE KEY. A line that is a name with no `=` at
#       all, which phpdotenv does NOT reject: `splitStringIntoParts()` returns
#       `[$line, null]` when there is no `=` (`EntryParser.php:76-78`) and
#       `Parser::process()` keeps every entry it is handed, so the entry exists;
#       `Loader::load()` then CLEARS the name (`Loader/Loader.php:36-37`), and
#       with last-assignment-wins the bare name WINS. This reader treats such a
#       line as "not an assignment" — the `[ "$rest" != "$line" ] || continue`
#       in the loop — and keeps the earlier value. MEASURED:
#       `K=sync<NL>K` → phpdotenv `QUEUE_CONNECTION => NULL` (so
#       `Dotenv::parse($body)['QUEUE_CONNECTION'] ?? ''` is `''`), this reader
#       `sync`. Its own inline comment below, "a line without one is a name with
#       no value, which is not an assignment", is half right and misses the part
#       that bites: phpdotenv keeps the entry AND lets it win.
#       The witness alone does not carry the class, and these four measurements
#       are what make it one:
#         * the EARLIER ASSIGNMENT IS LOAD-BEARING. `K` ALONE answers `''` on
#           BOTH sides — there the reader is harmless, and only the pair
#           diverges.
#         * an INDENTED bare name is NOT this class. `K=sync<NL><SP><SP>K` makes
#           phpdotenv REFUSE the whole file ("Encountered an invalid name",
#           `EntryParser::parseName()`), so there is no oracle for that body; the
#           unindented form is the class.
#         * `export K` reaches the SAME answer pair (phpdotenv NULL, reader
#           `sync`), so the prefix is not a way out — and the trailing newline is
#           not load-bearing either: the same bytes without it answer identically,
#           thanks to the `|| [ -n "$line" ]` guard.
#         * and it is LOCALE-INDEPENDENT — `sync` under `LC_ALL=C` and under
#           `LC_ALL=C.UTF-8`. That is precisely why the residue below cannot hold
#           it: one oracle answer, one reader answer, one locale, one table row.
#       Befund R8-1; it was reported as "residue 2" first and promoted to a class
#       once it was pinned. FREQUENCY, so nobody reads "rare" as "impossible": 1
#       witness among the 194 minimized divergences of seed 424242; 0 among the
#       214 of seed 20261003 and the 190 of a `CACHE_STORE` run — which is exactly
#       why it took a THIRD run and not a cleverer alphabet to find.
#
# REACHABILITY FOR THE TWO KEYS THESE SCRIPTS ASK FOR. `QUEUE_CONNECTION` and
# `CACHE_STORE` hold connection and store NAMES, and for THOSE TWO keys the
# classes above cannot be reached by a `backend/.env` a developer writes: a name
# carries no newline, no quote byte and no control byte; a quoted name spanning
# two lines (M2) is not a connection name in any form; the line the CI job
# writes carries no lone `\r`; nobody writes `${…}` into either key; class U
# needs a byte that is not valid UTF-8, which a name written as text cannot
# carry at all; class W needs a line that is not an assignment at all, which is
# not a form a `.env` line carries; and the residue below is not something a
# developer types into a `.env` either. That is a statement ABOUT THESE TWO KEYS —
# it is not a property of the reader, and it is therefore written here and pinned
# per class in the test, not asserted as a general claim. And for class W
# specifically the evidence is about the WRITERS rather than about the keys:
# MEASURED 2026-10-03, zero lines matching `^\s*(export\s+)?(QUEUE_CONNECTION|CACHE_STORE)\s*$` in
# `backend/.env.example`, in `deployment/dev.env`, and in this host's own
# (gitignored, machine-local) `backend/.env`. What is NOT true is that it could
# not matter. MEASURED through Laravel's own resolution chain —
# `LoadEnvironmentVariables::createDotenv()` hands `Env::getRepository()` to
# `Dotenv::create()`, and `config/queue.php:16` / `config/cache.php:18` read
# `env($key, 'database')` — a bare line AFTER a real assignment yields NULL from
# phpdotenv, `PhpOption\Option::fromValue(null)` is `None::create()`, and `env()`
# hands back the CONFIG DEFAULT `database`, while this reader keeps the stale
# earlier value. The note then takes the WRONG BRANCH: a `.env` holding
# `QUEUE_CONNECTION=sync` plus a bare `QUEUE_CONNECTION` makes `scripts/e2e-up.sh`
# print `sync` and say the mail is delivered inline, while the app resolves
# `database` and needs a worker. Two shapes do NOT do that, and both are
# measured: a bare line ALONE answers `''` on both sides and the note falls back
# to the same config default, and an earlier value that happens to BE the config
# default agrees by coincidence. The only mechanical writer that could produce
# the form is a `sed` replacement that lost its `=VALUE`, and every one of those
# re-checks the line on the next statement (`scripts/e2e-up.sh:139-140`,
# `.github/workflows/ci.yml:521-529`), so it could not pass unnoticed. The gap
# that remains is stated in the test's reachability note, and it is a real one:
# the guard over committed writers matches `…\s*=(.*)$`, and the `=` is IN the
# pattern, so a bare line in a committed writer is not collected by it.
#
# AND the LOCALE is not a class at all, which is the point of the byte set: the
# reader's TRIMS must not depend on `LC_ALL`. Measured before the fix, 209 of
# 4000 non-ASCII bodies did. A class is something the reader cannot do about
# inside a grammar; this was the environment deciding for it.
#
# MEASURED RESIDUE 1 OF 1 (Befund R8-1, same invalid-UTF-8 fuzz): that sentence
# is TRUE OF THE TRIMS AND NOT OF `read`, and the re-fuzz shows it — 73 of 3000
# bodies (seed 20261003; 62 of 3000 on seed 424242, 87 of 3000 on a `CACHE_STORE`
# run) are still answered differently under `LC_ALL=C.UTF-8` than under
# `LC_ALL=C`. The cause is one step further down, in bash's line reader and not in
# this grammar: `while IFS= read -r line` delivers ONE line where phpdotenv sees
# two when the line ends in an INCOMPLETE multibyte sequence. MEASURED on `read`
# ALONE, with no trims and no state machine anywhere in the picture:
# `QUEUE_CONNECTION=\xc3<NL>e` is two lines under `LC_ALL=C` and ONE under
# `LC_ALL=C.UTF-8`. The trigger is a lead byte `0xC2`-`0xFD` immediately before
# the newline — MEASURED byte by byte over `0x80`-`0xFF`: those 60 flip, and
# `0x80`-`0xC1`, `0xFE`, `0xFF` do not; a COMPLETE multi-byte sequence does not,
# a lead byte followed by ASCII does not, and neither EOF nor `\r` does it — the
# newline is the only thing that truncates. ALL 73 minimized witnesses from the
# first seed have that one shape, and it is present in the pre-fix reader too, so
# the byte set of R7 never touched it.
#
# Because phpdotenv splits on `/(\r\n|\n|\r)/`, a BYTE rule, this is under a
# UTF-8 locale a divergence of its own — `K=\xc3<NL>e` → phpdotenv `c3`, this
# reader `c30a65` — and 23 of those same 3000 bodies are divergent under
# `C.UTF-8` while agreeing under `C` (1 the other way round).
#
# THE RESIDUE IS REPORTED HERE AND NOT PINNED, and only because of the SHAPE it
# needs and not because pinning it would be inconvenient. Pinning residue 1
# requires a `divergenceClasses()` entry that carries TWO reader answers, one per
# locale, and that entry has no shape in the test today — while its sibling was
# promoted to class W precisely because it did fit the existing one row per class
# (one oracle answer, one reader answer, one locale), which is the whole
# difference between the two. So "TEN classes, all pinned" is TRUE as far as it
# goes — it covers ten of the eleven differences these three fuzzes found — and
# this sentence is what keeps the eleventh from being read as covered. Reporting
# it is the honest half and it is the half that costs nothing; the other half is
# named here so nobody has to re-run the fuzz to find out what is missing.
#
# OF THE TEN, ONE class IS reachable for them anyway, and it is V: `.env` is
# legal phpdotenv input, so `QUEUE_CONNECTION=${SOME_VAR}` with `SOME_VAR` set
# earlier in the same file is legal, Laravel resolves it, and this reader does
# not — the note then names a connection the app never resolves. It is pinned
# as a documented divergence
# (`DotenvReaderMatchesPhpDotenvTest::test_the_documented_divergence_classes`)
# rather than implemented: variable resolution in the shell is a larger surface
# than a NOTE needs, and the note is a note. W is the class that had the best
# chance of joining it, and it does not: it is UNREACHABLE for the two keys for
# the measured reason above, and the paragraph on it says exactly what would
# happen if that ever stopped being true.
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
        # `*`. Both halves are load-bearing and each was measured, because the
        # previous version of this comment had the anchor backwards and would
        # have recommended the WRONG code (Befund R8-2):
        #
        #   * WHICH BYTES — `["$ws"]` is the explicit ASCII set. `=~
        #     ^[[:space:]]` was the locale-dependent class the trims above just
        #     gave up, one line further up the same function.
        #   * THE `*` — inside `[[ ]]` a bracket class is matched against the
        #     WHOLE string, not as a substring search, so the naked form means
        #     "the string IS exactly one byte of this set". MEASURED, bash
        #     5.2.15, identically under `LC_ALL=C` and `LC_ALL=C.UTF-8`:
        #     `[[ "se" == [set] ]]` is FALSE, `[[ "se" == [set]* ]]` is TRUE.
        #     Dropping the star would therefore LOSE `export FOO=1`, which
        #     phpdotenv accepts (`EntryParser::parseName()`, `:99`): the check
        #     fails, the prefix is not stripped, and this reader answers EMPTY
        #     where phpdotenv answers `1`. `dotenvBodies()`' `export prefix` case
        #     goes red on the spot — MEASURED by running the mutated reader.
        #
        # The failure mode this comment used to describe — `exportFOO=1` read as
        # a prefixed key `FOO`, inventing a value that is not there — is one the
        # NAKED form cannot produce either, and that is measured too: for the name
        # `exportFOO` all THREE forms keep it, because `${name:6}` is `OO` and no
        # byte of the set is an `O` (MEASURED, bash 5.2.15, identically under
        # `LC_ALL=C` and `LC_ALL=C.UTF-8`). The invention only ever came from
        # stripping `export` UNCONDITIONALLY.
        #
        # `=~ ^[[:space:]]` was anchored and was right about WHERE; it was wrong
        # about WHICH BYTES. `== ["$ws"]*` is both.
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