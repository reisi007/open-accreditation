<?php

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * THE REGRESSION GUARD: the broken spellings of "send a JWT cookie" may not
 * come back into `tests/` — the four forbidden CALLS and, since 2026-09-30,
 * the four transport PROPERTIES behind them.
 *
 * ## Why a textual guard and not just the channel test
 *
 * `JwtCookieChannelTest` proves the channel works. It cannot prove that no
 * test reaches past it — that would need the whole suite to fail, and the
 * failure mode is silent by construction: a request that stops carrying its
 * cookie is answered by the `JWT::$token` singleton and comes back **200**. So
 * the suite does not go red when someone reintroduces `withCookie()`; it goes
 * green for a reason nobody wrote down. This file makes the reintroduction
 * itself the failure.
 *
 * ## Why the DEFAULT run must see it, and what it sees NOW
 *
 * The reason this file is a textual guard at all: a dead channel does not make
 * the suite red, it makes it green for a reason nobody wrote down. Whether the
 * DEFAULT run can see that depends on the tree, and the answer has changed
 * once already — so the numbers below are a measurement with a date, never a
 * property of the design.
 *
 * MEASURED 2026-09-30 on this tree, injecting the two property lines
 * (`defaultCookies[name] = $token` and `withCredentials = true`) into
 * `MandantMembershipTest::withJwt()` — the helper all 24 of that class's tests
 * go through (MEASURED: 24 test methods, no data providers):
 *
 *   | mode                                | result                                    |
 *   |-------------------------------------|-------------------------------------------|
 *   | RELAXED, `php artisan test`         | 18 failed / 1714 passed / 1 skipped       |
 *   | STRICT, `JWT_AUTH_STATE_STRICT=1`   | 18 failed / 1714 passed / 1 skipped       |
 *
 * and the two failure sets are the same 18 NAMES, not merely the same count:
 * 17 in `MandantMembershipTest` (which is red in RELAXED too — 17 failed / 7
 * passed scoped) plus 1 in this file, the pattern-4 scan that names the line.
 *
 * **The class is red in the default run, and this guard is no longer its only
 * relaxed witness.** That is a change, and the commit that caused it is
 * `00a9248` ("Position 13 - 2 high + 3 medium"): it gave
 * `MandantMembershipTest::tokenFor()` a `forgetJwtAuthState()` call, which
 * removes the `JWT::$token` singleton the class used to answer from. Before
 * that, a property-written channel left the class at 24 passed in RELAXED and
 * only the strict run could see it (17 red) — the number this paragraph used to
 * quote, and it was true at `0572408` and is false here. A reader who trusts it
 * would conclude the strict mode is the safety net; today the default mode is
 * already the net, and the guard's remaining job is the one only it can do:
 * name the FILE AND THE LINE.
 *
 * Two numbers in the old text were wrong in the same way, and both are
 * recorded here so neither is carried forward again: the guard was quoted at
 * "7 passed", which was its size at `0572408` — MEASURED on that tree: four
 * patterns, i.e. four data rows, plus three other tests — and it is 58 today;
 * and the injected class was quoted as having 32 tests, which is 24.
 *
 * ## What is forbidden, and the exact mechanism each time
 *
 *  1. `withCookie(` — puts the value in `defaultCookies`, which
 *     `prepareCookiesForRequest()` runs through `encrypt()`. Nothing on
 *     `/api/*` decrypts it (`EncryptCookies` is `web`-group only). MEASURED:
 *     401, value on the wire begins `eyJpdiI6`.
 *  2. `withCredentials(` — the switch that turns JSON cookie transport on at
 *     all, so it is REQUIRED, and equally required is pairing it with
 *     `withUnencryptedCookie()`. MEASURED: `withCookie()` +
 *     `withCredentials()` transports a ciphertext, i.e. 401. It belongs
 *     inside `withJwtCookie()`, alone.
 *  3. `->call(` — `MakesHttpRequests::call()` takes `$cookies` as its third
 *     parameter, defaulting to `[]`; the verb helpers are what fill it in. A
 *     direct `->call($method, $uri)` therefore transports no cookie, and an
 *     access-matrix test written that way asserts 403 while the wire says 401.
 *     MEASURED: `callAsApi()`/`getJson()` → 403, raw `call()` → 401.
 *  4. `withUnencryptedCookie(` — the plaintext cookie setter. On its own it
 *     transports nothing (the `withCredentials` switch is what enables
 *     transport at all), so it is the fourth spelling of the same silent
 *     drop. MEASURED: 401. This one was missing from the table for a
 *     while, and the hole was live: `SameOriginGuardTest` called it directly
 *     and stayed green while its only authentication came from the singleton
 *     (measured — the same 403 came back with the call deleted entirely).
 *  5. Writing `defaultCookies` / `unencryptedCookies` / `withCredentials` /
 *     `encryptCookies` — the four `protected` properties on the same trait
 *     (`MakesHttpRequests.php:30, :37, :58, :67`). Rules 1–4 police the
 *     METHODS; this one polices the state those methods write, which any test
 *     class can reach without calling a single method of theirs.
 *
 *     It is NOT a fifth failure mode, and saying otherwise would be the easy
 *     lie. MEASURED, it is the same mechanisms one door further down, and they
 *     have THREE outcomes, not two:
 *
 *       spelling                                              outcome
 *       `unencryptedCookies` without the switch               dropped, 401
 *       `defaultCookies` with the switch                      ciphertext, 401
 *       `unencryptedCookies` with the switch                  WORKS, 200
 *       `defaultCookies` with the switch AND encryptCookies   WORKS, 200
 *
 *     The last row is the one that was written down wrong. This comment used
 *     to say that `encryptCookies` "cannot drop a JWT" and was therefore in the
 *     set only because it is the property that makes the other three work — a
 *     justification for keeping a row that a reader has to take on faith, and
 *     MEASURED false on both halves. `prepareCookiesForRequest()` opens with
 *     `if (! $this->encryptCookies) { return array_merge($this->defaultCookies,
 *     $this->unencryptedCookies); }` (`MakesHttpRequests.php:730-740`), so
 *     writing `false` takes the unencrypted branch and the encrypting property
 *     hands the RAW token to the wire. It is not a helper for the other three;
 *     it is a second door of its own. (Measured in
 *     `JwtCookieChannelTest::test_the_encrypt_flag_is_a_second_working_door`.)
 *
 *     Which is why there are **TWO** open doors and not one — an earlier
 *     version of this paragraph said "one of which is open", and that singular
 *     was wrong in the direction that matters: a reader who believes there is
 *     one open door will assume the other three are harmless.
 *
 *     Those two open doors are the reason this row belongs here instead of
 *     being dismissed as redundant with the calls above. A *working* second
 *     door is worse than a dead one in the only sense this class cares about:
 *     the rule is "exactly ONE place in `tests/` configures a JWT cookie", and a
 *     hand-built channel that answers 200 is a second place, quietly, for as
 *     long as nobody bans it. The dead door is what was measured as a
 *     green-suite hole; the live doors are what makes banning the property
 *     spelling the right fix rather than the convenient one. Note the direction
 *     of the correction: documenting `encryptCookies` as an exclusion "because
 *     it cannot do harm" would not have been merely imprecise — the property
 *     opens a working channel by itself, which is a stronger reason to ban it
 *     than the one the old text gave.
 *
 * ## Ban or pairing? A DECISION, and the reason
 *
 * The docblock used to imply that `withUnencryptedCookie(` was only reachable
 * *paired with* `withCredentials()`, which would have made rule 4 a pairing
 * check ("these two calls must be near each other") instead of a fourth
 * banned spelling. It is implemented as a **ban**, for one decisive reason:
 *
 * **The thing a pairing check would have to observe is not local to the code
 * it reads.** `withCredentials()` is a *sticky property of the test instance*
 * — it is never reset — so "a `withCredentials()` appears near this line" is
 * not a statement about whether THIS cookie is transported. A pairing check
 * would pass on a test that enabled the switch three methods earlier for an
 * unrelated reason, and fail on a correct one that happens to wrap the call
 * differently. It would read as a guarantee and carry no information.
 *
 * A bare ban, by contrast, encodes the invariant that is actually true and
 * actually wanted: **there is exactly ONE place in `tests/` that configures a
 * JWT cookie — `withJwtCookie()`** — which is the same single-writer rule the
 * other three call patterns already enforce, and rule 5 extends to the STATE
 * those calls write. Nothing legitimate needs the pairing spelled out anywhere
 * else: production does not use these helpers at all, and the one test that
 * MEASURES the variants does it one variant per method (the stickiness above
 * would make a shared method unmeasurable).
 *
 * The pairing is not dropped — it is enforced **where it can be exact**:
 * `test_the_channel_helper_owns_exactly_one_use_of_each_switch` parses
 * `withJwtCookie()` out of `TestCase.php` and asserts the plaintext setter and
 * the credentials switch live in the SAME method. A proximity rule spread
 * over 100+ files is guesswork; a check on the one method that owns the pair
 * is a fact.
 *
 * The one legitimate occurrence of each of the first two is inside
 * `withJwtCookie()` itself, and it is checked separately below rather than
 * excluded by path — a blanket per-file exemption is exactly the kind of hole
 * a later edit walks straight into.
 *
 * ## Scope: `tests/` only
 *
 * Production is untouched, and deliberately so. The browser sends the cookie
 * itself, and no production code path in this application uses these test
 * helpers. The defect was never in the product — it was in the EVIDENCE, which
 * is why the blast radius of the fix is a directory.
 */
class ForbiddenJwtCookieChannelTest extends TestCase
{
    /**
     * The helper that owns the one legal use of both forbidden cookie setters.
     */
    private const CHANNEL_HELPER = 'tests/TestCase.php';

    /**
     * The file that MEASURES the variants and is therefore allowed to spell
     * them.
     */
    private const MEASUREMENT_FILE = 'tests/Feature/JwtCookieChannelTest.php';

    /**
     * This file, which may spell NONE of the five.
     *
     * Named as a constant rather than inlined because `EXEMPT` is compared
     * against a list of exempt files, and a guard that has to exempt its own
     * test fixtures should have to say so in a place a reader looks.
     */
    private const SELF_FILE = 'tests/Feature/ForbiddenJwtCookieChannelTest.php';

    /**
     * Files allowed to CALL or WRITE the forbidden spellings, each pinned to an
     * exact occurrence count per pattern.
     *
     * `JwtCookieChannelTest` has to spell all five: its whole purpose is to
     * MEASURE them, and the measured table is the evidence that the guard's
     * premise is real. A guard that forbade the demonstration would be
     * forbidding the proof of its own correctness.
     *
     * The counts are the point. A bare path exemption is a hole with a
     * comment on it, and the next edit walks into it — someone "just fixing a
     * test" adds a fourth `withCookie()` and the file is still exempt. So each
     * allowance is a NUMBER, asserted in
     * `test_the_exemptions_are_pinned_to_an_exact_count`, and the list has to be
     * edited deliberately to grow.
     *
     * Keyed by file, then by the pattern index from `forbiddenCallProvider()`,
     * so the numbers cannot silently drift onto the wrong pattern.
     *
     * @var array<string, array<int, int>>
     */
    private const EXEMPT = [
        // 0 → 2: the `withCookie()`-only drop AND the `withCookie()` +
        // `withCredentials()` ciphertext, which is one source line carrying
        // both forbidden calls.
        //
        // 1 → 1: `withJwtCookie()`'s own `withCredentials()`.
        //
        // 2 → 1: the raw `call()` entry point, measured by
        // `test_call_without_cookies_is_a_guest_even_with_a_configured_cookie`.
        //
        // 3 → 1: the plaintext setter, measured as row 3 of the table —
        // `withUnencryptedCookie()` alone transports nothing.
        //
        // 4 → 11, and it is the only number here that is not "one per thing
        // measured". Enumerated so a later reader can check the arithmetic
        // rather than trust it:
        //
        //   4 in `test_the_transport_properties_are_a_second_door_to_the_same_channel`
        //   — the two array writes (the ciphertext door and the working one),
        //   the credentials switch, and the reset that takes the ciphertext
        //   back off the wire again;
        //   1 in `test_the_unencrypted_property_without_the_switch_transports_nothing`
        //   — row 3 one door down;
        //   2 in `test_the_guard_memo_survives_dropping_only_the_singleton` —
        //   pre-existing, and the counterpart of a measurement: a test cannot
        //   prove the cookie was the only source of auth without taking it
        //   away again;
        //   4 in `test_the_encrypt_flag_is_a_second_working_door` —
        //   the encrypting write, the switch, the encrypt flag, and the switch
        //   going off again, which is what makes the 200 above readable as a
        //   working channel rather than a coincidence.
        //
        // Every one of the eleven is a measurement or the teardown of one. A
        // twelfth that were neither would have to be added HERE, deliberately,
        // which is the entire point of a number.
        self::MEASUREMENT_FILE => [0 => 2, 1 => 1, 2 => 1, 3 => 1, 4 => 11],

        // 0 → 0 is a POSITIVE statement, not an omission: TestCase.php must
        // never use the encrypting setter at all.
        //
        // 1 → 1 and 3 → 1 are the two halves of the one legal channel, and
        // `test_the_channel_helper_owns_exactly_one_use_of_each_switch` counts
        // them separately and additionally asserts they live in the SAME
        // method — instead of the file being exempt by path, which would also
        // cover any `withCookie()` that later appeared in the same file.
        //
        // 4 → 0 is the same kind of POSITIVE statement, and the reason the
        // helper is allowed to be a helper: `withJwtCookie()` reaches the
        // transport through the two CALLS above and must never reach through
        // the properties. A non-zero value here would mean the one file that
        // owns the channel had opened a second door inside itself.
        self::CHANNEL_HELPER => [0 => 0, 1 => 1, 2 => 0, 3 => 1, 4 => 0],

        // 0 for ALL FIVE, which is the strongest statement in this table, and
        // the reason the guard's own test cases do not need an exemption.
        //
        // The property pattern's cases in `propertyPatternCaseProvider()` are,
        // most of them, lines the pattern MUST match — and this file is under
        // `tests/`, so holding the case and being scanned by it are the same
        // fact. They are written with `@` where the code has `$` (see
        // `unmask()`), and every row of
        // `test_the_property_pattern_matches_exactly_what_it_means` asserts
        // twice: the verdict on the unmasked spelling, and that the masked
        // spelling in this source is not an offence. That is what lets the
        // numbers here be 0 rather than "however many fixtures we happen to
        // have written this month": a guard that has to exempt its own test
        // data has a budget an unreviewed edit can spend.
        self::SELF_FILE => [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0],
    ];

    /**
     * Call spellings — and, since index 4, one property-assignment spelling —
     * that must not appear in test code, with the reason.
     *
     * Rows 0–3 are CALL patterns, and each one carries a lookbehind for the
     * neighbour that is legitimate: row 0 a `(?<!assert)`, because
     * `assertCookieExpired` asserts on a RESPONSE and transports nothing
     * (MEASURED 2026-09-30: it is called in `SameOriginGuardTest`,
     * `MandantMembershipTest` and `AuthLoginTest`, so the lookbehind earns its
     * keep rather than decorating a name); rows 1 and 3 a `(?<!::)`, and row 2
     * a call-shaped pattern that leaves `$this->callAsApi(` alone. That
     * distinction is deliberate — a guard that cannot tell the two apart gets
     * disabled on its first false positive, and every one of these neighbours is
     * a row in `propertyPatternCaseProvider()`.
     *
     * One name in the older wording of this paragraph is not real: the trait
     * has a `withCookieExpired()`, but no test in `tests/` calls it
     * (MEASURED: 0 hits), so citing it explained nothing. `assertCookieExpired`
     * is the one that is actually there.
     *
     * Row 4 is a different shape (an assignment, not a call) and its own
     * lookbehinds are commented where it is defined.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function forbiddenCallProvider(): array
    {
        return self::forbiddenCalls();
    }

    /**
     * The forbidden spellings as `[patternIndex, pattern]` rows.
     *
     * Split out from the data provider so the pin test can index this very list
     * by the very same index the provider hands out. Two hand-maintained lists
     * would drift, and the drift would read as "0 occurrences" instead of "you
     * checked the wrong pattern".
     *
     * @return array<string, array{0: int, 1: string}>
     */
    private static function forbiddenCalls(): array
    {
        // `array_values()`: the data provider wants the STRING label as its
        // PHPUnit data-set name, while `EXEMPT` is keyed by the integer index.
        // Without this the two addressings disagree and the pin test asks for
        // key 0 of a list keyed by 'withCookie encrypts the value'.
        //
        // `~` as the delimiter, so the lookbehinds and `\s*` need no escaping
        // and the patterns read as the calls they forbid. The lookbehinds are
        // what keep `assertCookieExpired(` and `$this->callAsApi(` out — see the
        // docblock above on why that distinction has to hold.
        return array_values([
            // The index is part of the contract: `EXEMPT` is keyed by it, so the
            // order of these cases must not be permuted without updating that
            // table.
            'withCookie encrypts the value' => [0, '~(?<!assert)(?<!::)withCookie\s*\(~'],
            'withCredentials belongs to withJwtCookie' => [1, '~(?<!::)withCredentials\s*\(~'],
            'call drops the cookie argument' => [2, '~(?<!::)->call\s*\(~'],
            // Added 2026-09-29 after the hole was MEASURED rather than read:
            // injecting this spelling into a test file left the guard at
            // "5 passed", because the one live instance of it in the suite
            // (`SameOriginGuardTest`) was a false pass of its own. See
            // "Ban or pairing?" in the class docblock for why this is a ban and
            // not a proximity rule.
            //
            // The `(?<!::)` lookbehind is the same one the other patterns use: it
            // keeps `$this->withUnencryptedCookie(` (a forbidden CALL) apart
            // from a `::`-qualified reference, which transports nothing. It
            // also cannot match the pattern strings in THIS file — those read
            // `withUnencryptedCookie\s*\(`, i.e. the characters after the name
            // are a backslash, not a `(`. A guard that flagged its own
            // source would be unusable, and would be disabled on first sight.
            'withUnencryptedCookie belongs to withJwtCookie' => [3, '~(?<!::)withUnencryptedCookie\s*\(~'],

            // Added 2026-09-30 after the hole was MEASURED, not read: all four
            // forbidden things above are METHOD CALLS, and the cookie transport
            // is four PROTECTED PROPERTIES on the same trait
            // (`MakesHttpRequests.php:30, :37, :58, :67`). Those are reachable
            // from any test class, so the same four broken spellings have a
            // second door:
            //
            //     $this->defaultCookies[$k] = $token;   // encrypt()s the value
            //     $this->withCredentials = true;         // the switch, by hand
            //
            // The acceptance for this pattern was measured on
            // `MandantMembershipTest`, by injecting exactly those two lines into
            // the class's own `withJwt()`
            // — the helper all 24 of its tests go through (MEASURED: 24 test
            // methods, no data providers). The numbers, and the commit that is
            // responsible for them being what they are, live in the class
            // docblock under "Why the DEFAULT run must see it, and what it sees
            // NOW"; they are deliberately NOT repeated here, because the two
            // copies of that figure drifted apart once already (see there).
            //
            // Eight details, each of which would otherwise be a way through — and
            // four of them were MEASURED wrong before they were right: the offset
            // quantifier, the operator tail, the `??=` branch and the character
            // class. The other four were right the first time and are here so the
            // next reader does not have to re-derive them.
            //
            //  - The array offset is not optional, and not a single one either.
            //    `$this->defaultCookies[$k] = $t` is the exact line that was
            //    MEASURED dead, so a pattern that stops at the property name
            //    misses the only shape that occurs. `(?:[ \t]*\[[^\]\n]*\])*` —
            //    and the quantifier is `*` rather than `?` because with `?` the
            //    NESTED offset `$this->defaultCookies['a']['b'] = $t` sailed
            //    through: one offset, then `[ \t]*=` had to match `[`, and the
            //    whole thing fell over. MEASURED, both directions, in
            //    `test_the_property_pattern_matches_exactly_what_it_means`.
            //  - `\w+->` and NOT `this->`, and not an optional receiver either.
            //    The properties are `protected`, so the receiver is always an
            //    object: requiring it costs nothing (a bare `$defaultCookies`
            //    is a local, not the property) and it closes the alias
            //    spelling `$t = $this; $t->defaultCookies = …`. The price is
            //    that a FOREIGN object carrying one of these four property
            //    names would be flagged too. MEASURED 2026-09-30: there are
            //    none — every receiver in `tests/` is `$this->`, the single
            //    other spelling in the tree being this comment's own example —
            //    and such a mistake would be loud and self-explanatory anyway.
            //  - `(?<!::)` keeps a static property write out and `(?<!->)` keeps
            //    a variable-property write (`$obj->$name = …`) out — the same
            //    distinction rows 0–3 draw between a call and a `::`-qualified
            //    reference.
            //  - `[ \t]*` and never `\s*`, deliberately. This guard counts with
            //    TWO methods: `findOffences()` matches per LINE, the pin test
            //    matches over the WHOLE FILE. `\s*` would let a `\n` be part of
            //    a match, so an assignment split across two lines would be
            //    found by one counter and missed by the other. With `[ \t]*` the
            //    two agree by construction and the pin stays a backstop instead
            //    of a routine second opinion. (Both branches below keep it.)
            //  - The tail is an ASSIGNMENT and not a comparison, and it took
            //    three attempts to say so. `==`, `===` and `!==` reads of these
            //    properties are legitimate and stay legal, so are `?? ` reads
            //    and `=>` array keys. The tail is therefore
            //    `(?:>>|<<|\*\*|[.+\-*/%&|^])?=(?![=>])`: an optional operator
            //    letter (which is what makes `.=`, `+=`, `**=` writes visible
            //    instead of falling past a bare `=`), then a `=`, then a
            //    lookahead that refuses `=>`. So is `$this->withCredentials()`
            //    — `(` is not `=`, which is why index 1 and index 4 do not
            //    double-count the one legal call in `withJwtCookie()`.
            //  - `??=` gets a branch of its own, and the asymmetry is the
            //    point: `+=` is the same shape with a letter in front, but
            //    `??=` needs a QUESTION MARK pair, and with one operator branch
            //    it could not be had — `$this->defaultCookies['a'] ??= $t` is a
            //    WRITE whenever the key is absent, i.e. exactly the leak the
            //    ban exists for, and it went through. The second branch
            //    requires at least one offset (`+`, not `*`), which is what
            //    keeps the legitimate `$t = $this->defaultCookies['a'] ?? null`
            //    read out of its reach: with `*` that read would be banned too,
            //    and a ban that cannot tell a read from a write is a ban people
            //    route around.
            //  - The BARE `$this->defaultCookies ??= …` stays legal, and not as
            //    an oversight: all four properties are initialised to a non-null
            //    value (`MakesHttpRequests.php:30, :37, :58, :67` — `[]`, `[]`,
            //    `true`, `false`), so `??=` on the property itself can never
            //    assign anything. A test that first wrote `null` into one of
            //    them would be caught by that plain `=` line.
            //  - The character class holds NO delimiter, and that is not a
            //    style preference. An earlier version of this line also
            //    excluded the `~` delimiter, which made the WHOLE pattern
            //    INVALID: PHP's PCRE does not allow an unescaped delimiter
            //    inside a character class, so `[^=~]` ended the pattern at the
            //    `~` and every call below returned false. MEASURED, and the
            //    failure is the dangerous shape — `findOffences()` asks
            //    `preg_match(...) !== 1`, so `false` reads as "no match" and the
            //    guard passes with zero offences. The pin test is what makes it
            //    loud: `preg_match_all()` also returns false there, and `false`
            //    is not the pinned number. That backstop is the reason an
            //    allowance is a NUMBER and not a path.
            //
            // `encryptCookies` is in the set, and NOT for the reason the first
            // version of this comment gave. That reason was "a write to it
            // cannot drop a JWT, it is the one property that makes the other
            // three work" — MEASURED, false on both counts: it is not a helper,
            // it is a SECOND WORKING DOOR of its own. `prepareCookiesForRequest()`
            // reads `if (! $this->encryptCookies) return array_merge($this->defaultCookies,
            // $this->unencryptedCookies);` — so `defaultCookies` + the switch +
            // `encryptCookies = false` puts a PLAINTEXT token on the wire and
            // answers 200 (`MakesHttpRequests.php:730-740`, measured in
            // `JwtCookieChannelTest::test_the_encrypt_flag_is_a_second_working_door`).
            // Documenting the row as an exclusion "because it cannot do harm"
            // would therefore have been not merely wrong but actively so: the
            // property opens a working channel by itself, which is a stronger
            // reason to ban it than the one the comment used to give.
            //
            // What the pattern is NOT able to do, MEASURED 2026-09-30 rather
            // than guessed, because the first draft of this paragraph guessed
            // and was wrong twice:
            //
            //  - A write through a VARIABLE property name goes past it:
            //    `$p = 'defaultCookies'; $this->{$p}[$k] = $t;` is real PHP and
            //    does write the property, and the pattern does not match it — the
            //    `(?<!->)` lookbehind is what excludes `->{`. That is a
            //    deliberate trade (a variable property is a different write, and
            //    MEASURED: no such spelling exists in `tests/` today), and it is
            //    the one real hole in this pattern.
            //  - A write inside a CLOSURE or through `call_user_func` is NOT a
            //    hole, and this paragraph used to say it was. The scan matches
            //    per line over the whole file, so a line carrying the write is
            //    caught wherever it sits; MEASURED, a one-line
            //    `Closure::bind(… $this->defaultCookies = []; …)` matches.
            //  - Writing a LOCAL COPY is not a hole either, though it looks like
            //    one: `$jar = $this->defaultCookies; $jar[$k] = $t;` changes
            //    nothing, because PHP copies arrays by value. MEASURED: not
            //    matched, and correctly so.
            //
            // So the ban is a net over the spellings a person actually writes,
            // not a proof about the language — and the one hole above is named
            // here rather than left for the next reader to find.
            'cookie transport properties belong to withJwtCookie' => [4, '~(?<!::)(?<!->)\$\w+->(?:defaultCookies|unencryptedCookies|withCredentials|encryptCookies)(?:(?:[ \t]*\[[^\]\n]*\])+[ \t]*\?\?=|(?:[ \t]*\[[^\]\n]*\])*[ \t]*(?:>>|<<|\*\*|[.+\-*/%&|^])?=(?![=>]))~'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Per-test-method, not per-process: the five data-provider cases run
        // the same scan and must each see the full allowance.
        $this->exemptionsUsed = [];
    }

    #[DataProvider('forbiddenCallProvider')]
    public function test_no_test_uses_a_broken_cookie_channel(int $patternIndex, string $pattern): void
    {
        $offences = $this->findOffences($pattern, $patternIndex);

        $this->assertSame(
            [],
            $offences,
            'Found '.count($offences)." forbidden occurrence(s) matching `{$pattern}` in tests/.\n".
            "Each of these configures the cookie transport by hand instead of through the one\n".
            "sanctioned channel, and the outcomes are THREE, not two — an earlier version of this\n".
            "message said \"either dropped … or sent as a ciphertext\", and MEASURED, that\n".
            "dichotomy is false for two of the four properties:\n".
            "  1. DROPPED (401) — the switch is off, so prepareCookiesForJsonRequest() returns [].\n".
            "  2. CIPHERTEXT (401) — the switch is on and the value went in through the encrypting\n".
            "     property; prepareCookiesForRequest() runs it through encrypt(), nothing on /api/*\n".
            "     decrypts it, and the value arriving at the route begins eyJpdiI6.\n".
            "  3. DELIVERED AND AUTHENTICATING (200) — the plaintext property with the switch, or the\n".
            "     encrypting property with the switch AND encryptCookies turned off: prepareCookies-\n".
            "     ForRequest() takes its unencrypted branch and hands the raw token to the wire. A\n".
            "     second fully working place where a JWT cookie gets configured.\n".
            "Outcomes 1 and 2 are answered by the JWT::\$token singleton, so the suite stays GREEN for\n".
            "the wrong reason. Outcome 3 is the worst of the three to leave unbanned, because the\n".
            "test is green for a reason that is right by accident and owned by nobody. All of it in\n".
            "the RELAXED default run, which is the mode CI runs.\n".
            "Use Tests\\TestCase::withJwtCookie() for the cookie and ::callAsApi() for a\n".
            "verb-driven request. Measured behaviour of each forbidden spelling is documented in\n".
            "tests/Feature/JwtCookieChannelTest.php.\n".
            implode("\n", $offences)
        );
    }

    /**
     * The property pattern's own edge cases, one row each, with the verdict
     * asserted instead of described.
     *
     * ## Why this is a test and not a sentence in a comment
     *
     * The first version of this pattern shipped with a commit message listing
     * 28 edge cases it was "pinned to". MEASURED, nothing pinned them: a guard
     * can carry a number no test computes. The mutation proved it — deleting the
     * operator guard so the ban also matched every `==`, `===`, `!==` and `!=`
     * READ of these four properties left the whole file **green**. The
     * over-broad ban was invisible, and an over-broad ban that matches a
     * comparison is a worse failure than the original gap: it teaches people to
     * route around the guard.
     *
     * MEASURED 2026-09-30, on this tree, and the two halves are separate
     * findings:
     *
     *  - The SCAN cannot see that mutation at all. With the operator guard
     *    removed, the per-line scan over `tests/` finds the same 7 hits, all of
     *    them in the exempt measurement file — because no test in the suite
     *    happens to READ one of these four properties. A ban that is too broad
     *    and a tree that contains no comparison are indistinguishable from the
     *    outside.
     *  - So this test is the only witness, and it is the acceptance. MEASURED
     *    2026-09-30: replacing the tail with a bare `[ \t]*=` turns **9 of the
     *    49 rows below red** — the two equality reads and the two `=>` rows
     *    (the over-broad direction the mutation was built to expose), plus five
     *    compound-operator writes that a bare `=` cannot see either. The scan
     *    stays green throughout, so all nine come from here:
     *    `--filter ForbiddenJwtCookieChannelTest` goes from 58 passed to
     *    49 passed / 9 failed.
     *
     * The other direction is pinned too, and it is the one that matters for the
     * leaks: MEASURED 2026-09-30, putting this pattern BACK to its `791db7a`
     * form (one optional offset, a bare `=[^=]` tail) turns 9 rows red as well —
     * the seven leak rows (nested offset, `.=`, `+=`, `**=`, `<<=`, `%=` and
     * the offset `??=`) plus the two `=>` rows. Seven of those nine are writes
     * the ban is supposed to catch and did not, which is the shape of the
     * round-3 finding: not a wrong rule, a rule that stopped halfway.
     *
     * ## The `@` sigil, and why it is not a trick
     *
     * A case this test must MATCH is, by construction, an offence in a file
     * under `tests/` — and this file is under `tests/`. Holding the case and
     * being scanned by it are the same fact. So the cases are written with
     * SIGILS where the code has a banned character — `@` for `$`, `#` for `(` —
     * `unmask()` puts them back, and a second assertion in every row checks
     * the spelling AS WRITTEN here against ALL FIVE patterns.
     *
     * Two sigils rather than one, and the second was not planned: writing the
     * "one legal call" row as `@this->withCredentials()` kept `$` out of the
     * pattern's reach but left the CALL spelling, which pattern 1 matches
     * anywhere in a file. MEASURED, by the `EXEMPT` pin for this very file
     * going red — which is the backstop doing its job one test too late, since
     * the row itself could have said so. Hence `#`, and hence the check against
     * all five rather than against pattern 4 alone.
     *
     * That is what lets `EXEMPT` say "this file: 0 of all five" as a positive
     * statement instead of carrying an allowance for the guard's own fixtures —
     * a guard that must exempt its own test data has a budget an unreviewed
     * edit can spend.
     *
     * The leaks are rows here rather than prose: the nested offset, the `.=`
     * append, the compound operators and the offset `??=` all went through the
     * previous tail, and all four are writes of the kind the ban exists for.
     *
     * @param  string  $code  The spelling as written in this file — `@` for `$`, `#` for `(`.
     * @param  string  $why  Printed on failure, and the reason the row exists.
     */
    #[DataProvider('propertyPatternCaseProvider')]
    public function test_the_property_pattern_matches_exactly_what_it_means(
        string $code,
        bool $mustMatch,
        string $why
    ): void {
        // PREMISE, because the table below is addressed by INDEX and a
        // permutation of `forbiddenCalls()` would otherwise let it drift onto
        // another pattern and keep passing — the failure mode the pin test's own
        // docblock warns about, one level down.
        $propertyPattern = self::forbiddenCalls()[4][1] ?? null;

        $this->assertIsString(
            $propertyPattern,
            'PREMISE: index 4 must be the property-assignment pattern. If the four transport'
            .' properties moved to another index, move this test\'s index with them — a case'
            .' table that silently tests a different pattern is worse than no table.'
        );

        $pattern = $propertyPattern;
        $matched = preg_match($pattern, self::unmask($code)) === 1;

        $this->assertSame(
            $mustMatch,
            $matched,
            'Pattern 4 was expected '.($mustMatch ? 'TO' : 'NOT TO')." match this line:\n    {$code}\n".
            'It '.($matched ? 'did' : 'did not').".\n".
            "Why the row exists: {$why}\n".
            "The pattern is: {$pattern}"
        );

        // The masked spelling must be innocent of ALL FIVE patterns, not only of
        // pattern 4. A sigil that protects one case from one pattern is a sigil
        // someone will drop on a row that needs the other four.
        foreach (self::forbiddenCalls() as $index => [, $forbidden]) {
            $this->assertNotSame(
                1,
                preg_match($forbidden, $code),
                "The spelling as written in this file must not be an offence in its own source,\n".
                "but pattern {$index} matched it:\n    {$code}\n".
                'That means a sigil is missing. Unmasking is what lets the case be a case; writing the '.
                'banned character out literally would turn this file into the offence it bans.'
            );
        }
    }

    /**
     * The rows behind `test_the_property_pattern_matches_exactly_what_it_means`,
     * in the order a reader should meet them: the writes it must catch, the
     * leaks it used to walk past, and the legitimate code it must not touch.
     *
     * Every row carries its reason, because a row without one is a number
     * waiting to be deleted quietly. Where a row is a deliberate EXCLUSION
     * rather than a safety, its reason says so in those words — see the bare
     * `??=` row, which is legal because it cannot assign, and the `=>` rows,
     * which are legal because they are array keys.
     *
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function propertyPatternCaseProvider(): array
    {
        return [
            // ---- the writes it must catch: one per property, plus the shapes a
            // ---- person actually types around them.
            'offset write into the encrypting property' => [
                '@this->defaultCookies[@k] = @t;', true,
                'THE line that was measured dead: the switch is on, the value goes through encrypt(), and the request answers 401 out of the JWT singleton.',
            ],
            'offset write into the plaintext property' => [
                '@this->unencryptedCookies[@k] = @t;', true,
                'The same jar written through the property that skips encrypt() — with the switch this is a WORKING channel, which is the case the single-writer rule exists for.',
            ],
            'the credentials switch, written by hand' => [
                '@this->withCredentials = true;', true,
                'The switch is not a method call; the only sanctioned spelling of it is the one inside withJwtCookie().',
            ],
            'the encrypt flag, written by hand' => [
                '@this->encryptCookies = false;', true,
                'MEASURED 200: this alone turns the encrypting property into a working plaintext channel. A second open door, not a helper — which is why the row is in the ban at all.',
            ],
            'whole-array write, no offset' => [
                '@this->defaultCookies = [@name => @t];', true,
                'A reset line and a one-shot write share a shape, and a reset is a write too — the exemption table counts it as one.',
            ],
            'reset to an empty array' => [
                '@this->unencryptedCookies = [];', true,
                'Taking a value back off the wire is a write. Without it a test cannot prove the cookie was the only source of auth.',
            ],
            'append to an offset that does not exist yet' => [
                '@this->unencryptedCookies[] = @t;', true,
                'A bare [] offset is still an offset and the value ends up in the jar. The round-3 brief listed this shape among the "near misses that are safe"; MEASURED, it is a write, the previous tail caught it, and it stays caught.',
            ],
            'alias receiver' => [
                '@h = @this; @h->defaultCookies = @x;', true,
                'The receiver is any object, not necessarily this one — a local copy of the test instance writes the same property.',
            ],
            'spaces inside the offset' => [
                '@this->defaultCookies [ \'a\' ] = @t;', true,
                'The offset allows horizontal whitespace around the brackets, so indentation cannot smuggle a write past it.',
            ],
            'spaces before the operator' => [
                '@this->withCredentials   =   true;', true,
                'Same on the other side of the operator: horizontal whitespace, never a newline, and never a bare = glued to the name.',
            ],
            'tab before the operator' => [
                "@this->withCredentials\t=\ttrue;", true,
                'A tab counts too. Pint writes spaces, but a pasted line or an unformatted branch does not.',
            ],
            'the switch set back to false' => [
                '@this->withCredentials = false;', true,
                'A write is a write; what sits on the right-hand side is not the pattern\'s business.',
            ],

            // ---- the leaks: writes the previous tail walked past. Each was
            // ---- MEASURED going through, not read off the pattern.
            'nested offset' => [
                '@this->defaultCookies[\'a\'][\'b\'] = @t;', true,
                'LEAK (round 3): with a single OPTIONAL offset the pattern consumed the first bracket pair and then needed an = where the second bracket stood, and gave up. The quantifier is * for exactly this reason.',
            ],
            'dot-append into the jar' => [
                '@this->defaultCookies[@k] .= @x;', true,
                'LEAK (round 3): .= is a write, and the previous tail knew only a bare =. The operator letter is what makes it visible.',
            ],
            'compound assignment into the jar' => [
                '@this->defaultCookies[@k] += @x;', true,
                'LEAK (round 3), and the same cause as the row above for every member of the list: += - *= /= %= &= |= ^=.',
            ],
            'exponent assignment on the switch' => [
                '@this->withCredentials **= 2;', true,
                'Two-character operators, which is why the two-character alternatives come before the single-letter class. PCRE would recover by backtracking, but a pattern should say what it means.',
            ],
            'shift assignment into the jar' => [
                '@this->defaultCookies[@k] <<= 2;', true,
                'Also two characters, also covered by the branch above.',
            ],
            'modulo assignment into the plaintext jar' => [
                '@this->unencryptedCookies[@k] %= 2;', true,
                'A single-letter operator on the OTHER jar: the ban is on the property, not on the value behind it.',
            ],
            'null-coalescing assignment on an offset' => [
                '@this->defaultCookies[\'a\'] ??= @t;', true,
                'LEAK (round 3, and not in the brief): a write whenever the key is absent, i.e. the whole defect again, and it went through. It needs a branch of its own because the operator starts with a question mark — and that branch demands at least one offset, which is what keeps the plain ?? read below legal.',
            ],

            // ---- what it must NOT touch. Some of these are legitimate code and
            // ---- some are the over-broad direction, which is the one that makes
            // ---- a guard get disabled on its first false positive.
            'the one legal call' => [
                '@this->withCredentials#', false,
                'The single sanctioned use of the switch, inside withJwtCookie(). The parenthesis is masked with # because pattern 1 matches this spelling in ANY file — including this one. A parenthesis is not an operator, so index 1 and index 4 do not double-count it.',
            ],
            'loose equality read' => [
                '@this->defaultCookies == @other', false,
                'A read. A ban that matches this is worse than the gap it closes: it teaches people to work around the guard.',
            ],
            'strict equality read' => [
                '@this->defaultCookies === @other', false,
                'Same, and the pair with the row above is what the acceptance mutation attacks.',
            ],
            'strict inequality read' => [
                '@this->defaultCookies !== @other', false,
                'A read. The operator class holds no exclamation mark, so the = never gets a chance to match.',
            ],
            'inequality read' => [
                '@this->defaultCookies != @other', false,
                'A read, same mechanism.',
            ],
            'inequality read after an offset' => [
                '@this->defaultCookies[\'a\'] != @other', false,
                'The offset branch must not change this: the offsets are consumed, then the operator still has to be one.',
            ],
            'strict inequality read after an offset' => [
                '@this->unencryptedCookies[\'a\'] !== @other', false,
                'Same, on the other jar.',
            ],
            'a longer property name' => [
                '@this->withCredentialsX = true;', false,
                'The name is a whole alternative, not a prefix: the next character after it has to be the operator.',
            ],
            'static property on a class' => [
                'Foo::$withCredentials = true;', false,
                'A static property is not the trait property, and the lookbehind is the same distinction rows 0-3 draw between a call and a :: reference.',
            ],
            'static property, short class' => [
                'A::$withCredentials = true;', false,
                'Same, shorter — the length of the class name changes nothing.',
            ],
            'static property, qualified class' => [
                '\Foo::$withCredentials = true;', false,
                'Same, with a namespace in front.',
            ],
            'variable property name' => [
                '@obj->$defaultCookies = [];', false,
                'A variable property name is a different write, and the second lookbehind keeps it out. MEASURED: no such object exists in tests/, so the exclusion costs nothing today.',
            ],
            'bare local of the same name' => [
                '$defaultCookies = [];', false,
                'A local, not the property — which is what requiring a receiver buys.',
            ],
            'assertCookieExpired' => [
                '@this->assertCookieExpired(@response)', false,
                'Asserts on a RESPONSE and transports nothing. It is the reason row 0 needs its assert lookbehind.',
            ],
            'callAsApi' => [
                '@this->callAsApi(\'get\', @uri)', false,
                'The supported wrapper — the whole reason row 2 forbids the raw entry point without taking the wrapper with it.',
            ],
            'withJwtCookie' => [
                '@this->withJwtCookie(@token)', false,
                'The sanctioned channel. A pattern that flagged it would flag the fix.',
            ],
            'singular spelling' => [
                '@this->defaultCookie = @x;', false,
                'Not one of the four names: the alternation is anchored to the full name, trailing s included.',
            ],
            'lowercase spelling' => [
                '@this->defaultcookies = @x;', false,
                'The pattern is case-sensitive on purpose — PHP property names are, and a case-insensitive ban would flag an unrelated property.',
            ],
            'an unrelated cookie property' => [
                '@this->cookieJar = @x;', false,
                'Contains the word cookie and none of the four names. MEASURED: no such property exists in tests/, and a name like this is the obvious false positive a guard collects on its first bad day.',
            ],
            'bare read of the switch' => [
                '@this->withCredentials;', false,
                'A read with no operator at all — the tail requires an =, so the optional operator letter cannot be the last thing in the match.',
            ],
            'read inside a ternary' => [
                '@x = @this->withCredentials ? 1 : 0;', false,
                'The read MakesHttpRequests.php:747-750 performs, spelled the way it is spelled there.',
            ],
            'read as an array value' => [
                '[\'c\' => @this->withCredentials]', false,
                'A read. The fat arrow is on the other side of the property here, which is also why the tail cannot be loosened to a bare =.',
            ],
            'read out of the jar' => [
                '@seen = @this->defaultCookies[\'a\'];', false,
                'A read with an offset and no operator.',
            ],
            'concatenation read' => [
                '@x = @this->defaultCookies . @y;', false,
                'The operator class contains a dot so that .= is caught, and this is the row that proves the two are told apart: a dot before an = is a write, a dot before anything else is a read.',
            ],
            'fat arrow on the switch' => [
                '@x = @this->withCredentials => @y;', false,
                'An array key, not an assignment — which is why the tail ends in a lookahead on = rather than a letter class that would have to exclude the arrow.',
            ],
            'fat arrow after an offset' => [
                '@x = @this->defaultCookies[\'a\'] => @y;', false,
                'Same, with an offset, and one of the four rows that go red on the acceptance mutation.',
            ],
            'null-coalescing read' => [
                '@t = @this->defaultCookies[\'a\'] ?? null;', false,
                'The read that keeps the ??= branch honest: that branch demands an assignment, this is a lookup.',
            ],
            'null-coalescing read of the switch' => [
                '@t = @this->withCredentials ?? false;', false,
                'Same, on a property that is never null.',
            ],
            'null-coalescing assignment on the bare property' => [
                '@this->withCredentials ??= true;', false,
                'DELIBERATE EXCLUSION, and the only exclusion in this table: all four properties are initialised to a non-null value (MakesHttpRequests.php:30, :37, :58, :67 — [], [], true, false), so a null-coalescing assignment on the property itself can never assign. A test that first wrote null into one of them is caught by that plain = line. The OFFSET form is a write, and it is matched, one row above.',
            ],
            'less-than-or-equal read' => [
                '@x = @this->withCredentials <= 1;', false,
                'A comparison: the operator class holds no <, so the = never matches it.',
            ],
        ];
    }

    /**
     * The sigils back to the characters they stand for: `@` for `$`, `#` for `(`.
     *
     * Two, for two different patterns — the test method explains why the second
     * one exists. A single `str_replace`, and the second assertion in every row
     * of the test above is what keeps it honest: this file must contain the
     * masked form, or the guard has exempted its own fixtures.
     */
    private static function unmask(string $masked): string
    {
        return str_replace(['@', '#'], ['$', '('], $masked);
    }

    /**
     * The exemptions are pinned numbers, and the pin is checked.
     *
     * Without this, `EXEMPT` would be a list of files nobody re-reads: adding a
     * fourth `withCookie()` to the channel test — or a second one — would keep
     * passing, and the guard would slowly stop guarding the one file that is
     * allowed to contain the very thing it forbids everywhere else. So the count
     * per exempt file is asserted against the declared number here, and a
     * mismatch names the file.
     *
     * The check is driven by `forbiddenCalls()` itself, so a pattern added
     * without a decision about it is caught here: an exempt file that does not
     * name a pattern at all is a hole, and the reciprocal loop below is what
     * says so. That is precisely how the fourth spelling survived — it was
     * added to the scan list conceptually (it was never scanned for at all)
     * and no exempt file was ever asked whether it may spell it.
     */
    public function test_the_exemptions_are_pinned_to_an_exact_count(): void
    {
        $cases = self::forbiddenCalls();

        $this->assertNotEmpty(
            self::EXEMPT,
            'PREMISE: the exemption table must not be emptied to silence this guard.'
        );

        $this->assertSame(
            array_keys($cases),
            range(0, count($cases) - 1),
            'PREMISE: forbiddenCalls() is addressed by pattern index, so its indices must be 0..n-1 with no gaps.'
        );

        foreach (self::EXEMPT as $file => $expectedPerPattern) {
            $this->assertFileExists(base_path($file), "Exempt file {$file} does not exist.");

            $source = (string) file_get_contents(base_path($file));
            $code = $this->stripComments($source);

            foreach ($expectedPerPattern as $patternIndex => $expectedCount) {
                $this->assertArrayHasKey(
                    $patternIndex,
                    $cases,
                    "PREMISE: EXEMPT names pattern index {$patternIndex}, which forbiddenCalls() does not define."
                );

                $pattern = $cases[$patternIndex][1];

                $this->assertSame(
                    $expectedCount,
                    preg_match_all($pattern, $code),
                    "{$file} must contain exactly {$expectedCount} occurrence(s) of the forbidden".
                    " call `{$pattern}`. It is exempt so the broken spellings can be MEASURED, not so\n".
                    'new tests can use them. If the count changed on purpose, update EXEMPT in '.
                    __CLASS__.' — silently, that is how a guard gets disabled.'
                );
            }

            // The reciprocal, and the half that actually caught the fourth
            // pattern: every forbidden spelling needs a DECISION for this file
            // — a number (it may be spelled here that often) or an explicit 0
            // (it may not). `findOffences()` would still scan for a pattern this
            // file says nothing about, so the guard is not broken by the
            // omission; what is missing is the reasoning, and a missing
            // decision is exactly how a spelling slips through a guard.
            foreach (array_keys($cases) as $patternIndex) {
                $this->assertArrayHasKey(
                    $patternIndex,
                    $expectedPerPattern,
                    "PREMISE: {$file} says nothing about pattern index {$patternIndex}".
                    " (`{$cases[$patternIndex][1]}`). Every forbidden spelling needs a number here —\n".
                    'how often this file may spell it, or 0 for "never". Silence is a decision nobody made.'
                );
            }
        }
    }

    /**
     * The reporting contract: a `path:line` in a failure message is a REAL line
     * of the REAL file.
     *
     * `findOffences()` reads the comment-stripped copy, so that is what the line
     * number comes from — and for years `stripComments()` replaced each comment
     * with one run of spaces, which destroyed every line break inside a
     * multi-line docblock. The stripped copy had fewer lines than the file, so
     * every reported number was short by however many lines had already been
     * collapsed above it. MEASURED: 989 lines became 834 on
     * `MandantMembershipTest.php`, and an offence on line 977 was reported as
     * `MandantMembershipTest.php:822`.
     *
     * That is why this is a test and not just a fixed implementation. The
     * failure message is this guard's only product, and the docblock next to
     * `stripComments()` claimed line numbers survived when they did not — a map
     * that says it is a map. A contract with no test is a comment, and the
     * previous comment is what the bug lived in.
     */
    public function test_a_reported_line_number_is_a_real_line_of_the_file(): void
    {
        $source = (string) file_get_contents(base_path(self::CHANNEL_HELPER));
        $stripped = $this->stripComments($source);

        $this->assertSame(
            substr_count($source, "\n"),
            substr_count($stripped, "\n"),
            'stripComments() must preserve the line count of the file it reads, because that count is what a'.
            " reported line number is taken from.\n".
            'It previously did not: one run of spaces per comment collapsed every docblock, and the'.
            ' numbers were silently short.'
        );

        // The count alone would pass for a masking that dropped a line and
        // added one somewhere else, so the real claim is spot-checked: the line
        // that holds the one legal channel helper must hold the SAME line in the
        // stripped copy. That is precisely what shifts when a docblock above it
        // collapses.
        $originalLines = explode("\n", $source);
        $strippedLines = explode("\n", $stripped);

        $index = null;

        foreach ($originalLines as $position => $line) {
            if (str_contains($line, 'protected function withJwtCookie')) {
                $index = $position;

                break;
            }
        }

        $this->assertNotNull(
            $index,
            'PREMISE: '.self::CHANNEL_HELPER.' must still declare withJwtCookie() — the channel has one owner.'
        );

        $this->assertSame(
            $originalLines[$index],
            $strippedLines[(int) $index],
            'Line '.((int) $index + 1).' of '.self::CHANNEL_HELPER.' is not the same line after stripping, so a'.
            ' reported line number points somewhere other than the offence.'
        );
    }

    /**
     * The exemption is asserted, not assumed.
     *
     * Without this, a future edit could simply widen the "helper" to a whole
     * file — or, worse, move a `withCookie()` into `TestCase.php` on the theory
     * that file is exempt. The allowance is exactly ONE call each, on the
     * documented lines of `withJwtCookie()`.
     */
    public function test_the_channel_helper_owns_exactly_one_use_of_each_switch(): void
    {
        $source = file_get_contents(base_path(self::CHANNEL_HELPER));

        $this->assertIsString($source);

        $withoutDocblocks = $this->stripComments($source);

        // The messages deliberately spell the calls without the trailing `(`
        // this file's own guard forbids — a message that quotes the forbidden
        // spelling verbatim is itself an offence, which is a fine way to make a
        // guard unusable.
        $this->assertSame(
            1,
            preg_match_all('/(?<!::)withCredentials\s*\(/', $withoutDocblocks),
            'The credentials switch must be called exactly once in TestCase.php — inside withJwtCookie.'
        );

        $this->assertSame(
            0,
            preg_match_all('/(?<!assert)(?<!::)withCookie\s*\(/', $withoutDocblocks),
            'TestCase.php must never use the encrypting cookie call: nothing on /api/* decrypts it.'
        );

        $this->assertSame(
            1,
            preg_match_all('/withUnencryptedCookie\s*\(/', $withoutDocblocks),
            'The unencrypted cookie call must appear exactly once in TestCase.php — the plaintext channel.'
        );

        $this->assertSame(
            0,
            preg_match_all('/(?<!::)->call\s*\(/', $withoutDocblocks),
            'TestCase.php must not reach for the raw HTTP entry point either; callAsApi() is the wrapper.'
        );

        // And the same file must not open a second door inside itself by writing
        // the four transport properties directly. The pattern is REUSED from
        // `forbiddenCalls()` rather than retyped, so the two cannot drift — a
        // guard that keeps its own copy of its own rule is a guard with two
        // opinions. The message names the properties but never writes one of
        // them followed by an assignment, because a guard's own failure text is
        // not allowed to be an offence.
        $this->assertSame(
            0,
            preg_match_all(self::forbiddenCalls()[4][1], $withoutDocblocks),
            'TestCase.php must reach the cookie transport through the calls inside withJwtCookie() only, never by writing defaultCookies / unencryptedCookies / withCredentials / encryptCookies itself.'
        );
    }

    /**
     * THE PAIRING, checked where it can be exact.
     *
     * The ban on `withUnencryptedCookie(` outside this helper is a
     * single-writer rule, deliberately NOT a proximity rule — see "Ban or
     * pairing?" in the class docblock for the measurement that rules proximity
     * out. A ban cannot express one thing, though: whether the two calls that
     * make the channel work are still TOGETHER. Both are forbidden on their
     * own, and a `TestCase.php` that satisfied both counts with the credentials
     * switch in one method and the plaintext setter in another would leave the
     * suite with no working channel and no red test — the channel test would
     * still see one of each somewhere in the file.
     *
     * So the pairing is asserted on the METHOD rather than on the file, and by
     * tokenising rather than by regex: a method-body regex breaks the first
     * time the formatter reindents the file, and a guard that fails on
     * formatting is a guard that gets commented out.
     */
    public function test_the_two_channel_switches_live_in_the_same_method(): void
    {
        $body = $this->bodyOfMethod(
            (string) file_get_contents(base_path(self::CHANNEL_HELPER)),
            'withJwtCookie'
        );

        $this->assertNotNull(
            $body,
            'withJwtCookie() must exist in '.self::CHANNEL_HELPER.' — the channel has exactly one owner.'
        );

        $this->assertSame(
            1,
            preg_match_all('/(?<!::)withUnencryptedCookie\s*\(/', (string) $body),
            'The plaintext cookie setter belongs inside withJwtCookie().'
        );

        $this->assertSame(
            1,
            preg_match_all('/(?<!::)withCredentials\s*\(/', (string) $body),
            'The credentials switch belongs inside withJwtCookie() too, and in the SAME method as the'
            .' plaintext setter: without it the cookie is not transported at all (MEASURED: 401).'
        );
    }

    /**
     * The source of one method's body, brace-matched, or null when there is
     * no such method.
     *
     * Token-based rather than regex-based for the reason above: this feeds a
     * guard, and tokens know which `{` opens the method whatever the
     * indentation is.
     */
    private function bodyOfMethod(string $source, string $method): ?string
    {
        $tokens = token_get_all($source);
        $last = count($tokens) - 1;

        for ($i = 0; $i < $last; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $nameAt = null;

            for ($j = $i + 1; $j < $last; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $nameAt = $j;

                    break;
                }

                if ($tokens[$j] === '{') {
                    break;
                }
            }

            if ($nameAt === null || $tokens[$nameAt][1] !== $method) {
                continue;
            }

            $depth = 0;
            $body = '';

            for ($k = $nameAt; $k < $last; $k++) {
                $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];

                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;

                    if ($depth === 0) {
                        return $body;
                    }
                }

                $body .= $text;
            }

            return null;
        }

        return null;
    }

    /**
     * Every `.php` file under `tests/`, sorted, so a failure lists offenders in
     * a stable order across runs.
     *
     * @return list<string>
     */
    private function phpFilesUnderTests(): array
    {
        $root = base_path('tests');

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * `path:line` for every match of `$pattern` in executable code, EXCLUDING
     * files whose allowance for that exact pattern index has not been used up.
     *
     * Comments and docblocks are stripped FIRST, and that is not cosmetic: the
     * tests that document this defect quote the forbidden calls in their prose.
     * Matching those would make the guard impossible to satisfy without
     * deleting the documentation of the bug it guards against — and a guard
     * that must be silenced is a guard that gets disabled.
     *
     * The stripping is done with `token_get_all()` and a T_COMMENT /
     * T_DOC_COMMENT filter, not with a regex: a regex cannot tell a comment
     * from a string literal, and this suite's tests legitimately contain both
     * the word `withCookie(` and comment delimiters inside strings. Spaces
     * replace the comment text so line numbers and column offsets survive,
     * which is what lets the failure message point at a real line.
     *
     * @return list<string>
     */
    private function findOffences(string $pattern, int $patternIndex): array
    {
        $offences = [];

        foreach ($this->phpFilesUnderTests() as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                $this->fail("Could not read {$file}.");
            }

            $code = $this->stripComments($source);

            foreach (explode("\n", $code) as $index => $line) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }

                $relative = str_replace(base_path().'/', '', $file);

                // An exempt file may hold its pinned number of occurrences. The
                // budget is consumed per hit, not per file, so the N+1'th hit is
                // an offence even inside an exempt file — that is what stops the
                // exemption from being a blank cheque.
                $used = $this->exemptionsUsed[$relative][$patternIndex] ?? 0;
                $allowed = self::EXEMPT[$relative][$patternIndex] ?? 0;

                if ($used < $allowed) {
                    $this->exemptionsUsed[$relative][$patternIndex] = $used + 1;

                    continue;
                }

                $offences[] = $relative.':'.($index + 1).'  '.trim($line);
            }
        }

        return $offences;
    }

    /**
     * How many of each exempt file's pinned occurrences have been spent, reset
     * per test method so the five data-provider cases of the scan cannot steal
     * from one another's budget.
     *
     * @var array<string, array<int, int>>
     */
    private array $exemptionsUsed = [];

    /**
     * Replace every comment and docblock with spaces, preserving line numbers
     * AND column offsets so the reported `path:line` still points at the real
     * line.
     *
     * ## The newlines are load-bearing, and getting that wrong was a real bug
     *
     * The straightforward version of this is
     * `str_repeat(' ', strlen($token[1]))` — one run of spaces per comment
     * token. It keeps the byte count, so the *offsets* look right, and it was
     * here for months. It destroys every line break inside a multi-line
     * comment, so the stripped source has FEWER lines than the file and every
     * reported line number is short by the number of lines already collapsed
     * above it.
     *
     * The historical measurement, on the tree where the bug was found
     * (`791db7a`, RELAXED): `MandantMembershipTest.php` stripped to 834 lines
     * instead of its own, and an offence on real line 977 was reported as
     * `MandantMembershipTest.php:822` — 155 lines off, pointing at a line that
     * has nothing to do with it. The docblock right here claimed the opposite,
     * which is the worst version of that bug: a map that says it is a map.
     *
     * MEASURED 2026-09-30 on this tree, because the figures that paragraph was
     * written with do not belong to it: `MandantMembershipTest.php` is **986
     * lines with 24 test methods** today, not the 989 and 32 the same commit
     * claimed — the file had already been changed by `00a9248` when those
     * numbers were written down, so they were wrong on their own tree and not
     * merely out of date. The stripped copy is 986 lines, i.e. equal, which is
     * the property `test_a_reported_line_number_is_a_real_line_of_the_file`
     * asserts on every run; the historical 977 → 822 pair cannot be re-measured
     * because the bug it belongs to is fixed, and is quoted here only as the
     * reason the test exists.
     *
     * A failure message that names the right FILE but the wrong LINE sends the
     * next person to the wrong place, and this guard's entire value is where it
     * points. So the replacement preserves `\r` and `\n` and spaces everything
     * else, which keeps both the line count and the per-line columns.
     *
     * `preg_replace('/[^\r\n]/', ' ', …)` and not `strtr()`: `strtr()` with a
     * map COPIES every character the map does not mention, so masking a
     * comment with it leaves the comment in place — which is not a subtle
     * degradation but the guard flagging its own documentation (21 hits, as
     * measured on the tree where that variant was tried; like the pair above,
     * a number belonging to a version of the code that no longer exists).
     * Replacing each non-line-break CHARACTER with a space is a mask, and it
     * keeps the length by construction.
     */
    private function stripComments(string $source): string
    {
        $tokens = token_get_all($source);

        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $result .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? preg_replace('/[^\r\n]/', ' ', $token[1])
                    : $token[1];

                continue;
            }

            $result .= $token;
        }

        return $result;
    }
}
