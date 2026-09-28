<?php

namespace Tests\Feature;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * THE REGRESSION GUARD: the three broken spellings of "send a JWT cookie"
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
 *     `withUnencryptedCookie()`. A test that calls it directly has one of the
 *     four broken combinations (see `JwtCookieChannelTest` for the measured
 *     table). It belongs inside `withJwtCookie()`, alone.
 *  3. `->call(` — `MakesHttpRequests::call()` takes `$cookies` as its third
 *     parameter, defaulting to `[]`; the verb helpers are what fill it in. A
 *     direct `->call($method, $uri)` therefore transports no cookie, and an
 *     access-matrix test written that way asserts 403 while the wire says 401.
 *     MEASURED: `callAsApi()`/`getJson()` → 403, raw `call()` → 401.
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
     * The helper that owns the one legal use of both forbidden calls.
     */
    private const CHANNEL_HELPER = 'tests/TestCase.php';

    /**
     * Files allowed to CALL the forbidden spellings, each pinned to an exact
     * occurrence count per pattern.
     *
     * `JwtCookieChannelTest` has to call all three: its whole purpose is to
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
        // 1 → 1: `withJwtCookie()` itself. The one legal use in the whole
        // suite, and the reason `test_the_channel_helper_owns_exactly_one_use_of_
        // each_switch` counts it separately instead of the file being exempt by
        // path — a file-wide exemption would also cover any `withCookie()` that
        // later appeared in the same file, which is the hole this avoids.
        'tests/Feature/JwtCookieChannelTest.php' => [0 => 2, 1 => 1, 2 => 1],
        self::CHANNEL_HELPER => [0 => 0, 1 => 1, 2 => 0],
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
     */
    public function test_the_exemptions_are_pinned_to_an_exact_count(): void
    {
        $cases = self::forbiddenCalls();

        $this->assertNotEmpty(
            self::EXEMPT,
            'PREMISE: the exemption table must not be emptied to silence this guard.'
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
