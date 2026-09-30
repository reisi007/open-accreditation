<?php

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * THE REGRESSION GUARD: the four broken spellings of "send a JWT cookie"
 * may not come back into `tests/`.
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
 * other three patterns already enforce. Nothing legitimate needs the pairing
 * spelled out anywhere else: production does not use these helpers at all, and
 * the one test that MEASURES the variants does it one variant per method
 * (the stickiness above would make a shared method unmeasurable).
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
     * Files allowed to CALL the forbidden spellings, each pinned to an exact
     * occurrence count per pattern.
     *
     * `JwtCookieChannelTest` has to call all four: its whole purpose is to
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
        self::MEASUREMENT_FILE => [0 => 2, 1 => 1, 2 => 1, 3 => 1],

        // 0 → 0 is a POSITIVE statement, not an omission: TestCase.php must
        // never use the encrypting setter at all.
        //
        // 1 → 1 and 3 → 1 are the two halves of the one legal channel, and
        // `test_the_channel_helper_owns_exactly_one_use_of_each_switch` counts
        // them separately and additionally asserts they live in the SAME
        // method — instead of the file being exempt by path, which would also
        // cover any `withCookie()` that later appeared in the same file.
        self::CHANNEL_HELPER => [0 => 0, 1 => 1, 2 => 0, 3 => 1],
    ];

    /**
     * Call spellings that must not appear in test code, with the reason.
     *
     * Matched as a whole word so `withCookieExpired`/`assertCookieExpired` and
     * `$this->callAsApi(` are not caught: `assertCookieExpired` asserts on a
     * RESPONSE and transports nothing, and `callAsApi(` is the supported
     * wrapper. That distinction is deliberate — a guard that cannot tell the
     * two apart gets disabled on its first false positive.
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
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Per-test-method, not per-process: the three data-provider cases run
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
            'Found '.count($offences)." forbidden call(s) matching `{$pattern}` in tests/.\n".
            "Each of these silently stops transporting the JWT, after which the request is\n".
            "answered by the JWT::\$token singleton and the suite stays GREEN for the wrong\n".
            "reason. Use Tests\\TestCase::withJwtCookie() for the cookie and ::callAsApi()\n".
            "for a verb-driven request. Measured behaviour of each forbidden spelling is\n".
            "documented in tests/Feature/JwtCookieChannelTest.php.\n".
            implode("\n", $offences)
        );
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
     * per test method so the three data-provider cases cannot steal from one
     * another's budget.
     *
     * @var array<string, array<int, int>>
     */
    private array $exemptionsUsed = [];

    /**
     * Replace every comment and docblock with spaces, preserving line numbers
     * and column offsets so the reported `path:line` still points at the real
     * line.
     */
    private function stripComments(string $source): string
    {
        $tokens = token_get_all($source);

        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $result .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat(' ', strlen($token[1]))
                    : $token[1];

                continue;
            }

            $result .= $token;
        }

        return $result;
    }
}
