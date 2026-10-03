<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Dotenv\Parser\Lines;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Befund L5 (2026-10-03): the shell scripts resolve one environment variable to
 * print a NOTE about which queue connection will actually be in effect, and
 * nothing in the repo held them to the truth.
 *
 * ## Why this test exists
 *
 * `scripts/e2e-up.sh` says whether a mail-dependent Playwright spec will find its
 * message, and `scripts/dev-worker.sh` says whether the mail idempotency claim is
 * process-local. Both answers come from reading one key out of `backend/.env` —
 * and both got it wrong, twice, in the same way:
 *
 *  - F5: `dev-worker.sh` used `grep -E '^CACHE_STORE='`, which recognises only a
 *    key in column zero and no `export`. phpdotenv accepts both, so `  CACHE_STORE=array`
 *    and `export CACHE_STORE=array` went unread and the script fell back to
 *    `database` — printing nothing about a stack whose claim it had just proved
 *    is process-local.
 *  - L1: `e2e-up.sh` had grown its own `last_env_value` with the same shape, so
 *    `export QUEUE_CONNECTION=sync` and `  QUEUE_CONNECTION=sync` were read as a
 *    value that is not `sync`, and the note told the reader to start a worker
 *    for a stack that delivers inline. A quoted `QUEUE_CONNECTION="sync"` in a
 *    developer's own `.env` failed the same way.
 *
 *    An earlier version of this paragraph called the quoted form "the form the
 *    CI E2E job itself writes". That was false (Befund B3): the job writes and
 *    re-checks the UNQUOTED `QUEUE_CONNECTION=sync`
 *    (`.github/workflows/ci.yml:522` sed, `:529` `grep -q '^QUEUE_CONNECTION=sync$'`),
 *    which the old `grep -E '^QUEUE_CONNECTION='` reader answered correctly. No
 *    committed `.env`, compose file or CI step in this repo assigns either key in
 *    the quoted form; it is a form a developer's own `.env` can carry, and it
 *    lives in the differential bodies below.
 *    `test_the_ci_job_writes_the_unquoted_form_this_note_names()` keeps the two
 *    apart, so the sentence cannot quietly become wrong again.
 *
 * A note that answers wrongly is worse than no note: it is confident, and it
 * sends the reader down the wrong branch. So the reader is pinned.
 *
 * ## What "pinned" means here: DIFFERENTIAL, against the library itself
 *
 * The oracle is `Dotenv\Dotenv::parse()` — the very code Laravel runs. The shell
 * reader in `scripts/lib/dotenv-value.sh` re-implements that grammar, and this
 * test asserts the two agree on every input. A test that only listed the forms
 * somebody remembered would be a list of the forms that were thought of; the
 * point of a differential is that it also covers the one nobody did.
 *
 * It is deliberately NOT implemented by shelling out to PHP. A reader that both
 * shells out to `Dotenv::parse()` and is compared against `Dotenv::parse()`
 * cannot fail: the comparison would be PHP with PHP, and a completely broken
 * reader would still pass. The pin has to be able to go red, so the grammar is
 * reimplemented in the shell and the two implementations meet here.
 *
 * `test_the_reader_can_go_red()` proves the pin is SENSITIVE to its input: it
 * drives both sides of the differential — `Dotenv::parse()` and the extracted
 * shell function — a second time with a body whose answer differs, and requires
 * the oracle to tell the two bodies apart. Without it, a comparison that read
 * nothing from the `.env` at all would look exactly like a comparison that
 * passed. (It used to compare two string literals, which cannot fail and proved
 * nothing — Befund B8.)
 *
 * ## The reader under test is the SHARED one
 *
 * `scripts/lib/dotenv-value.sh` is sourced by both scripts. That is the point of
 * L1: the two copies had already drifted apart, which is the whole failure mode.
 * `test_both_scripts_source_the_one_reader()` keeps it that way — if a script ever
 * regains a private reader, this fails even though every parsing assertion below
 * would still pass.
 *
 * ## Scope: TEN measured divergence classes (and one named residue), not one
 *
 * This file once claimed the reader differed from phpdotenv in exactly ONE
 * input. An independent fuzz — 6000 bodies, a different seed AND a different
 * alphabet — found five more classes, and a targeted probe a sixth (Befund B1).
 * The claim is replaced by the list in the header of
 * `scripts/lib/dotenv-value.sh`, and `test_the_documented_divergence_classes()`
 * pins every class in BOTH directions: the reader still diverges, phpdotenv
 * still says what it said, and the header still NAMES the class. A difference
 * outside the list is a bug in the reader, not a documented boundary.
 *
 * The eighth is M2, a BALANCED multiline (Befund R7-1); the ninth is U, an
 * invalid UTF-8 byte together with a `$`, and the tenth is W, a line that is a
 * bare NAME with no `=` at all — both of those from Befund R8-1, and both out of
 * the SAME re-fuzz over an invalid-UTF-8 alphabet. Every alphabet this file was
 * fed until R7 was ASCII, which made "0 unattributed divergences" a true
 * statement about bytes the file never named; R7's alphabet was non-ASCII and
 * still contained no byte that is invalid UTF-8, and R8's does — which is the
 * second half of the same lesson. R7's fuzz also produced a difference which is
 * deliberately NOT in the list: the reader's OWN answer depended on `LC_ALL` for
 * 209 of 4000 bodies, because `[[:space:]]` is locale-dependent and phpdotenv's
 * trim is a byte set. That one is not a class and no class list can hold it: it is
 * not about the grammar, it is the environment deciding for the reader. It was
 * removed at the source (the trims use an explicit byte set now) and pinned by
 * `test_the_reader_does_not_depend_on_the_locale()`.
 *
 * R8's re-fuzz left TWO further differences, and their fates differ — which is
 * the point of writing both down rather than only the one that was convenient.
 * (1) `read` itself is locale-dependent when a line ends in an incomplete
 * multibyte sequence. (2) A bare NAME line with no `=` clears the key for
 * phpdotenv and not for the reader — and that one is pure ASCII, which is why it
 * took a THIRD fuzz run and not a cleverer alphabet to surface. (2) FITS the
 * shape of a class entry — one oracle answer, one reader answer, one locale — and
 * is therefore the TENTH class, pinned below like the other nine. (1) needs a row
 * carrying TWO reader answers, one per locale, which the table has no shape for;
 * it is the one that stays a named, unpinned residue. So "ten classes, all
 * pinned" is not read as "ten, and nothing is left over" — eleven differences
 * have been found, and the eleventh is named in the reader's header.
 *
 * The lesson is written here because this file keeps re-learning it: a
 * differential is only as complete as the ALPHABET it was fed, and once past the
 * alphabet it is only as complete as the RUNS. All three of the claims it has
 * made — "exactly one difference", "0 unattributed", "0 locale-dependent" — were
 * true and useless.
 *
 * Reachability is stated where it belongs — per class, per key, and measured
 * rather than assumed. For the two keys these scripts ask for
 * (`QUEUE_CONNECTION`, `CACHE_STORE`) exactly one class is reachable by a legal
 * `backend/.env`: the `${VAR}` interpolation, class V. The rest need a byte a
 * connection name does not contain — or, for W, a line that is not an assignment
 * at all, which is measured to occur in none of this repo's writers. That is a
 * statement about THESE TWO KEYS and not a property of the reader, which is why
 * it is written as a per-class `reachable` note and not as a general assertion;
 * W's note also states what would go wrong if that ever stopped being true.
 */
class DotenvReaderMatchesPhpDotenvTest extends TestCase
{
    /**
     * The reader, as a path relative to the repository root.
     */
    private const READER = 'scripts/lib/dotenv-value.sh';

    /* ------------------------------------------------------------------ */
    /* The differential */
    /* ------------------------------------------------------------------ */

    /**
     * Every form the reader must handle, each as a complete `.env` BODY.
     *
     * Grouped by why the old readers failed, because a reader that handles only
     * "plain" passes a test written only in plain — the groups are the regression
     * list, not decoration.
     *
     * @return array<string, array{string}>
     */
    public static function dotenvBodies(): array
    {
        return [
            /* --- what the F6 matrix already covered --- */
            'plain' => ["QUEUE_CONNECTION=database\n"],
            'sync' => ["QUEUE_CONNECTION=sync\n"],
            'a different key first' => ["APP_ENV=local\nQUEUE_CONNECTION=sync\n"],
            'duplicate, last wins' => ["QUEUE_CONNECTION=database\nQUEUE_CONNECTION=sync\n"],
            'duplicate three times, last wins' => ["QUEUE_CONNECTION=database\nQUEUE_CONNECTION=redis\nQUEUE_CONNECTION=sync\n"],
            'key absent' => ["APP_ENV=local\n"],
            'empty value' => ["QUEUE_CONNECTION=\n"],
            'commented out' => ["# QUEUE_CONNECTION=sync\nQUEUE_CONNECTION=database\n"],
            'a key that merely starts with ours' => ["QUEUE_CONNECTION_EXTRA=nope\nQUEUE_CONNECTION=sync\n"],

            /* --- the F5 forms: `export` and indentation --- */
            'export prefix' => ["export QUEUE_CONNECTION=sync\n"],
            'export plus two spaces' => ["export  QUEUE_CONNECTION=sync\n"],
            'indented with spaces' => ["  QUEUE_CONNECTION=sync\n"],
            'indented with a tab' => ["\tQUEUE_CONNECTION=sync\n"],
            'indented and exported' => ["  export QUEUE_CONNECTION=sync\n"],

            /* --- quoting --- */
            'double quoted' => ["QUEUE_CONNECTION=\"sync\"\n"],
            'single quoted' => ["QUEUE_CONNECTION='sync'\n"],
            'double quoted with a trailing comment' => ["QUEUE_CONNECTION=\"sync\" # for CI\n"],
            'single quoted with a trailing comment' => ["QUEUE_CONNECTION='sync' # for CI\n"],
            'double quoted with trailing whitespace' => ["QUEUE_CONNECTION=\"sync\"   \n"],
            'quoted, then a comment with no space' => ["QUEUE_CONNECTION=\"sync\"#x\n"],
            'quoted empty string' => ["QUEUE_CONNECTION=\"\"\n"],

            /* --- comments --- */
            'inline comment after a space' => ["QUEUE_CONNECTION=sync # inline delivery\n"],
            'inline comment after a tab' => ["QUEUE_CONNECTION=sync\t# inline delivery\n"],
            'inline comment with no leading space' => ["QUEUE_CONNECTION=sync#inline\n"],
            'comment containing a quote' => ["QUEUE_CONNECTION=sync # it says \"mail\"\n"],
            'comment on its own line first' => ["# queue note\nQUEUE_CONNECTION=sync\n"],

            /* --- whitespace around the separator --- */
            'space before =' => ["QUEUE_CONNECTION =sync\n"],
            'space after =' => ["QUEUE_CONNECTION= sync\n"],
            'spaces on both sides' => ["QUEUE_CONNECTION  =  sync\n"],
            'trailing whitespace' => ["QUEUE_CONNECTION=sync   \n"],

            /* --- shapes that must NOT read as a value --- */
            'export is a prefix only before whitespace' => ["exportQUEUE_CONNECTION=sync\n"],
            'the name is quoted' => ["\"QUEUE_CONNECTION\"=sync\n"],
            'value contains an = sign' => ["QUEUE_CONNECTION=a=b\n"],
            'quoted value contains an = sign' => ["QUEUE_CONNECTION=\"a=b\"\n"],
            'escaped quote inside double quotes' => ["QUEUE_CONNECTION=\"a\\\"b\"\n"],
            'escape sequence inside double quotes' => ["QUEUE_CONNECTION=\"a\\nb\"\n"],
            'backslash is literal inside single quotes' => ["QUEUE_CONNECTION='a\\nb'\n"],
            'hash inside quotes is not a comment' => ["QUEUE_CONNECTION='a#b'\n"],

            /* --- line endings and file shape --- */
            'CRLF line endings' => ["QUEUE_CONNECTION=sync\r\n"],
            'CRLF with a duplicate' => ["QUEUE_CONNECTION=database\r\nQUEUE_CONNECTION=sync\r\n"],
            'no trailing newline' => ['QUEUE_CONNECTION=sync'],
            'blank lines around it' => ["\n\nQUEUE_CONNECTION=sync\n\n"],
        ];
    }

    #[DataProvider('dotenvBodies')]
    public function test_the_shell_reader_returns_exactly_what_phpdotenv_returns(string $body): void
    {
        $key = 'QUEUE_CONNECTION';

        $expected = Dotenv::parse($body)[$key] ?? '';
        $actual = $this->readWithShellReader($body, $key);

        $this->assertSame(
            $expected,
            $actual,
            "scripts/lib/dotenv-value.sh read a different value than Dotenv\\Dotenv::parse() for this .env body:\n"
            ."--- body ---\n".$body
            ."\nBoth scripts print this value in a NOTE about which queue connection is live, so a\n"
            .'difference here means the note names a connection the app never resolves.',
        );
    }

    /**
     * The same comparison, over the OTHER key these scripts read.
     *
     * `dev-worker.sh` asks for `CACHE_STORE`, and it is the reader whose warning
     * is about a security-shaped guarantee (the duplicate-delivery claim). A pin
     * that only ever asked for one key would leave the other unmeasured.
     */
    #[DataProvider('cacheStoreBodies')]
    public function test_the_shell_reader_also_agrees_on_the_cache_store_key(string $body): void
    {
        $key = 'CACHE_STORE';

        $this->assertSame(
            Dotenv::parse($body)[$key] ?? '',
            $this->readWithShellReader($body, $key),
            "scripts/lib/dotenv-value.sh diverged from Dotenv\\Dotenv::parse() on CACHE_STORE:\n".$body,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cacheStoreBodies(): array
    {
        return [
            'the stack e2e-up.sh pins' => ["CACHE_STORE=array\n"],
            'exported, the F5 case' => ["export CACHE_STORE=array\n"],
            'indented, the F5 case' => ["  CACHE_STORE=array\n"],
            'quoted' => ["CACHE_STORE=\"array\"\n"],
            'with a comment' => ["CACHE_STORE=array # rate-limiter determinism\n"],
            'a shared store, which must produce no warning' => ["CACHE_STORE=database\n"],
            'duplicate, last wins' => ["CACHE_STORE=database\nCACHE_STORE=array\n"],
            'absent' => ["APP_ENV=local\n"],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* The pin has to be able to go red */
    /* ------------------------------------------------------------------ */

    /**
     * A negative control: the comparison must be SENSITIVE to its input.
     *
     * Without this, a comparison that compared nothing — a `parse()` that
     * returned an empty array because the key never matched, say — would look
     * identical to a comparison that passed, and the whole file would be a
     * green assertion about nothing.
     *
     * The control used to be `assertNotSame('sync', '"sync"')`, i.e. a comparison
     * of two literals. It could not fail: those two strings do not depend on
     * anything this test does. Comparing literals proves that PHP's `!==`
     * operator works (Befund B8). What is actually in doubt is whether the two
     * SIDES of the differential — `Dotenv::parse()` and the extracted shell
     * function — answer from the `.env` body at all, so the control drives
     * both sides a SECOND time with a body whose answer differs, and requires
     * the oracle to tell the two bodies apart. If either side ignored its
     * input, this fails while the differential above would still be green.
     */
    public function test_the_reader_can_go_red(): void
    {
        // phpdotenv reads this as `sync`; a reader that ignored the quotes would
        // return `"sync"`, which is a different connection name and a different
        // branch in the note.
        $body = "QUEUE_CONNECTION=\"sync\"\n";

        $this->assertSame(
            'sync',
            Dotenv::parse($body)['QUEUE_CONNECTION'] ?? '',
            'PREMISE: phpdotenv must read the quoted value as `sync` — otherwise this body proves nothing.',
        );

        $readerResult = $this->readWithShellReader($body, 'QUEUE_CONNECTION');

        $this->assertSame(
            Dotenv::parse($body)['QUEUE_CONNECTION'] ?? '',
            $readerResult,
            'If this fails, the reader and phpdotenv DISAGREE and the differential below is red too — '
            .'which is the behaviour this test exists to prove the comparison can detect.',
        );

        // The second body, driven through the SAME two sides.
        $other = "QUEUE_CONNECTION=database\n";

        $this->assertNotSame(
            Dotenv::parse($body)['QUEUE_CONNECTION'] ?? '',
            Dotenv::parse($other)['QUEUE_CONNECTION'] ?? '',
            'PREMISE: the two bodies must read differently. If the oracle cannot tell them apart, "both '
            .'returned `sync`" above says nothing about whether it read this body at all.',
        );

        $this->assertSame(
            Dotenv::parse($other)['QUEUE_CONNECTION'] ?? '',
            $this->readWithShellReader($other, 'QUEUE_CONNECTION'),
            'PREMISE: the shell reader must read the second body the same way the oracle does. A reader '
            .'that answered one fixed value would pass the differential above on some forms and fail on '
            .'all the rest; this is the assertion that notices before the 50 do.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* One reader, two scripts */
    /* ------------------------------------------------------------------ */

    /**
     * Both scripts must SOURCE the shared reader, UNCOMMENTED and BEFORE they
     * first use it — and neither may define its own.
     *
     * The parsing assertions above pass either way — a script that grew a private
     * reader would still be tested through the shared one. L1 exists precisely
     * because two copies drifted, so the copy itself has to be the failure.
     *
     * The order is part of that, and a substring scan cannot hold it (Befund B2):
     * commenting the source line out leaves the substring in the file, and
     * moving the source below the first call leaves it in the file too. Both
     * mutations are green under a `assertStringContainsString` guard and both
     * are broken at runtime — the first dies on `command not found` (exit 127
     * under `set -euo pipefail`), the second calls a function that does not
     * exist yet. So the guard works on the COMMENT-STRIPPED lines and compares
     * two line numbers: the source line must be there at all, and it must come
     * before the first `dotenv_value` reference.
     */
    public function test_both_scripts_source_the_one_reader(): void
    {
        foreach (['scripts/e2e-up.sh', 'scripts/dev-worker.sh'] as $script) {
            $source = $this->repositoryFile($script);

            // Comments are stripped FIRST, and that is the point, not a
            // convenience: both scripts DISCUSS `grep -E '^CACHE_STORE='` in
            // prose (they name the defect they fixed), and both name the reader
            // in prose too. What must survive is CODE — and a commented-out
            // source line is prose as far as this guard is concerned, which is
            // precisely the mutation B2 used to get a green test.
            $code = $this->strippedOfShellComments($source);

            $sourceLine = null;
            $firstUseLine = null;

            foreach (explode("\n", $code) as $index => $line) {
                // `. "$ROOT_DIR/scripts/lib/dotenv-value.sh"` as CODE: nothing
                // may precede it on the line (no `#`, no `;`), and nothing may
                // follow it. The reader path uses a hyphen, so this cannot match
                // a line that merely MENTIONS the function.
                if ($sourceLine === null && preg_match(
                    '/^\s*\.\s+"\$ROOT_DIR\/'.preg_quote(self::READER, '/').'"\s*$/',
                    $line,
                ) === 1) {
                    $sourceLine = $index + 1;
                }

                if ($firstUseLine === null && preg_match('/\bdotenv_value\b/', $line) === 1) {
                    $firstUseLine = $index + 1;
                }
            }

            $this->assertNotNull(
                $sourceLine,
                "{$script} must SOURCE the shared reader as an UNCOMMENTED line reading exactly "
                .'`. "$ROOT_DIR/'.self::READER.'"`. Two copies of this rule drifted apart once already '
                .'(L1): e2e-up.sh read `export QUEUE_CONNECTION=sync` as a connection that is not `sync`, '
                .'and told the reader to start a worker for a stack that delivers inline. A source line '
                .'behind a `#` satisfies a substring scan and then dies at runtime with '
                .'`dotenv_value: command not found` (exit 127).',
            );

            $this->assertNotNull(
                $firstUseLine,
                "{$script} must CALL dotenv_value — otherwise this file's parsing assertions test a "
                .'function no script ever reaches.',
            );

            $this->assertLessThan(
                $firstUseLine,
                $sourceLine,
                "{$script} uses dotenv_value on line {$firstUseLine} but sources it only on line "
                ."{$sourceLine}. Under `set -euo pipefail` that is `dotenv_value: command not found` — "
                .'exit 127, and the note this script exists to print never gets printed.',
            );

            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(?:function\s+)?(?:last_env_value|dotenv_value)\s*\(\s*\)/m',
                $code,
                "{$script} must not define a private dotenv reader — that is the duplication L1 removed.",
            );

            $this->assertDoesNotMatchRegularExpression(
                '/grep\s+-E\s+[\'"]\^?\$?\{?[A-Za-z_]+\}?=/',
                $code,
                "{$script} must not read a .env key with grep: that column-zero pattern is the F5 defect "
                .'and it is what this reader replaced. It was found in THREE places — dev-worker.sh, the '
                .'queue note in e2e-up.sh, and the DB-mismatch warning in e2e-up.sh — and the warning was '
                .'the one that stayed silent on the mismatch it exists to announce.',
            );
        }
    }

    /**
     * The CI E2E job writes the UNQUOTED `QUEUE_CONNECTION=sync`, and it says so
     * twice — once by writing it, once by asserting what it wrote.
     *
     * Befund B3: the note in `scripts/e2e-up.sh` and this class' docblock both
     * claimed the job writes `QUEUE_CONNECTION="sync"`. It does not, the quoted
     * form is written nowhere in this repo, and — the part that matters — the old
     * `grep -E '^QUEUE_CONNECTION='` reader answered the job's actual form
     * CORRECTLY. Naming the quoted form as the CI one therefore credited L1 with
     * a failure it did not have and hid which forms really failed (`export`,
     * indentation, quotes, inline comment).
     *
     * So both halves are pinned from the file itself: the job's own line must
     * still be there, and the note must not claim the quoted form as CI's.
     */
    public function test_the_ci_job_writes_the_unquoted_form_this_note_names(): void
    {
        $ci = $this->repositoryFile('.github/workflows/ci.yml');

        $this->assertMatchesRegularExpression(
            "/^\s*-e 's\|\^QUEUE_CONNECTION=\.\*\|QUEUE_CONNECTION=sync\|'/m",
            $ci,
            'PREMISE: the CI E2E job must pin the queue connection by rewriting the key to the '
            .'UNQUOTED `QUEUE_CONNECTION=sync`. If it stops doing so, the form this note has to read '
            .'changes with it and the differential test\'s premise about the job\'s own line is stale.',
        );

        $this->assertStringContainsString(
            "grep -q '^QUEUE_CONNECTION=sync\$' .env",
            $ci,
            'PREMISE: the job must also RE-CHECK what it wrote, in the same unquoted spelling — a job '
            .'that asserted the quoted form would prove a different claim than the one it writes.',
        );

        $note = $this->repositoryFile('scripts/e2e-up.sh');

        // The precise shape of the false claim: a line that puts the QUOTED form
        // and the CI job in the same sentence. Checked per line rather than as a
        // fixed phrase, because the note has to be free to DISCUSS the quoted form
        // — it is a legal local `.env` case and it sits in the differential bodies.
        // What it may not do is hand that form to the CI job.
        foreach (explode("\n", $note) as $number => $line) {
            if (! str_contains($line, 'CI E2E job')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'QUEUE_CONNECTION="',
                $line,
                'scripts/e2e-up.sh:'.($number + 1).' hands the QUOTED `QUEUE_CONNECTION="…"` to the CI E2E '
                .'job. The job writes and re-checks the unquoted form (asserted above), so this sentence is '
                .'false — and it was false in exactly this shape (Befund B3).',
            );
        }

        $this->assertStringContainsString(
            'QUEUE_CONNECTION=sync in backend/.env like the CI E2E job',
            $note,
            'The note\'s own instruction must name the CI job\'s actual spelling, `QUEUE_CONNECTION=sync`, so '
            .'the sentence a reader acts on is the one this test verified.',
        );
    }

    /**
     * The note must name three stages, and the third one must be the framework's
     * default — not the template file.
     *
     * Befund L2: the note claimed `environment > backend/.env > .env.example`.
     * `.env.example` is a template, never a runtime source — Laravel reads
     * `backend/.env` and nothing else. Measured before the correction: with the
     * key absent from `.env` and `.env.example` set to `redis`, the note said
     * `redis` while the app resolved `database`.
     */
    public function test_the_note_names_the_three_stages_the_app_actually_has(): void
    {
        $source = $this->repositoryFile('scripts/e2e-up.sh');

        $this->assertStringContainsString(
            'config/queue.php default',
            $source,
            'The note must name the real third resolution stage (the config default), not `.env.example`.',
        );

        $this->assertStringNotContainsString(
            '.env.example).',
            $source,
            'The note must not claim `.env.example` is a resolution stage: Laravel never reads it at runtime.',
        );

        // The premise the corrected note rests on: the third stage really is the
        // framework default, and it really is what the note falls back to. Read
        // from `config/queue.php` rather than restated, so this test cannot drift
        // away from the config it is about.
        $config = $this->repositoryFile('backend/config/queue.php');
        $this->assertSame(
            1,
            preg_match("/env\(\s*'QUEUE_CONNECTION'\s*,\s*'([^']+)'\s*\)/", $config, $matches),
            'PREMISE: config/queue.php must read QUEUE_CONNECTION with a literal default — '
            .'the note falls back to that value, so it cannot be pinned without it.',
        );

        $this->assertStringContainsString(
            'QUEUE_CONNECTION_EFFECTIVE="'.$matches[1].'"',
            $source,
            "The note must fall back to config/queue.php's default ({$matches[1]}), which is the stage Laravel "
            .'actually reaches when neither the environment nor backend/.env sets the key.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* The documented boundary: ten classes, one named residue */
    /* ------------------------------------------------------------------ */

    /**
     * Every class in which this reader is KNOWN to differ from phpdotenv.
     *
     * Befund B1: this file used to name ONE class (the multiline case) and call
     * the boundary "exactly one difference". An independent fuzz over 6000
     * bodies with a different seed AND a different alphabet found five more
     * classes; a targeted probe a sixth. The list below replaces the claim
     * rather than narrowing it, and every entry is measured on both sides:
     *
     * Befund R7-1 added the EIGHTH, M2 — a BALANCED multiline — and R8-1 the
     * NINTH, U (an invalid UTF-8 byte together with a `$`) and the TENTH, W (a
     * bare NAME line with no `=`). The last two came from re-fuzzing over one
     * different alphabet, not from thinking harder about the same one. R7's fuzz
     * ALSO produced a difference which is deliberately NOT here: the reader's
     * answer depended on `LC_ALL` for 209 of 4000 non-ASCII bodies, because
     * `[[:space:]]` is locale-dependent and phpdotenv's trim is a byte set. That
     * one is not a class — it was the environment deciding for the reader — and
     * it was removed at the source. `test_the_reader_does_not_depend_on_the_locale()`
     * is what holds that half of the contract, because a class list cannot.
     *
     * ONE measured difference is still outside this list, and saying so is the
     * point: bash's `read`, not the grammar, delivers fewer lines under a UTF-8
     * locale when a line ends in an incomplete multibyte sequence (MEASURED: 73
     * of 3000 bodies locale-dependent, 62 of 3000 on a second seed, 87 of 3000
     * on a `CACHE_STORE` run; 23 of the first seed's are divergent only under
     * `C.UTF-8`). Pinning that needs a row carrying two reader answers, one per
     * locale, which is a change to the SHAPE of this table and is not made here.
     * Its sibling from the same fuzz — a line that is a bare NAME with no `=`,
     * phpdotenv `NULL` (`EntryParser.php:76-78`, `Loader/Loader.php:36-37`) and
     * this reader the earlier value — DID fit the existing shape and is class W
     * below. The two differ only in how many answers a row has to carry, which
     * is the whole reason one is pinned and the other is not; neither is
     * skipped.
     *
     *  - `$oracle` is what `Dotenv\Dotenv::parse()` answers (or, for M, that it
     *    emits nothing at all). These values were measured, not read off the
     *    vendor source; they are the pin.
     *  - `$reader` is what `scripts/lib/dotenv-value.sh` answers for the same
     *    body, read exactly as the scripts read it — through command
     *    substitution, which is why class A exists at all.
     *  - `$label` must still occur in the reader's header. A class that is
     *    measured but unnamed is a hidden boundary, and the header is where the
     *    reader of the script learns where not to trust it.
     *  - `$mechanism` names the phpdotenv code that produces the oracle's
     *    answer, so "this is the multiline feature" is checked, not asserted.
     *
     * The last element of each entry is that class's REACHABILITY statement for
     * the two keys these scripts ask for. It lives here, per class, because
     * reachability is a property of the class — not of the reader.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: string}>
     */
    public static function divergenceClasses(): array
    {
        return [
            'M — an UNBALANCED multiline swallows the file' => [
                "QUEUE_CONNECTION=\";\t\n",
                '',
                ';',
                'M — MULTILINE, UNBALANCED',
                'multiline',
                'UNREACHABLE for both keys: a value that opens a multiline cannot be a connection name. '
                .'Note the reader returns `;` and NOT `;` + a tab — the tab is trimmed off with the rest '
                .'of the value before it is parsed. An earlier version of this file and of the header '
                .'recorded `;` + a tab, and that was wrong (Befund B7).',
            ],
            'M2 — a BALANCED multiline: the value closes on a LATER line' => [
                "QUEUE_CONNECTION=\"a\nb\"\n",
                "a\nb",
                'a',
                'M2 — MULTILINE, BALANCED',
                null,
                'UNREACHABLE for both keys: a connection name does not span two lines. This class is a '
                .'SIBLING of M and not a footnote to it, and the difference is measured, not argued. '
                .'In M phpdotenv REJECTS nothing and emits no key at all, because the `="` is never '
                .'closed; here phpdotenv ACCEPTS the file and joins the lines (`Lines::multilineProcess()` '
                .'implodes the buffer with a newline), while `read` hands this function one line at a time '
                .'so the value state machine simply runs out of input and stops at the first line end. '
                .'MEASURED: `K="a<NL>b"` → phpdotenv `a<NL>b`, this reader `a`. And the ESCAPE form '
                .'agrees — `K="a\\nb"` with a backslash is `a<NL>b` on BOTH sides — which is exactly why '
                .'the class-A note here used to be read as "an interior newline agrees": it was written '
                .'with an escape and read as a statement about newlines. Befund R7-1.',
            ],
            'A — a value whose last byte is a newline' => [
                "QUEUE_CONNECTION=\"sync\\n\"\n",
                "sync\n",
                'sync',
                'A — A VALUE WHOSE LAST BYTE IS A NEWLINE',
                null,
                'UNREACHABLE for both keys: no connection name ends in a newline. This class is not a bug '
                .'in dotenv_value — it is what command substitution does to its output, and BOTH callers '
                .'use it that way. The class is specifically the LAST byte. Mind the distinction this '
                .'sentence used to blur: an interior newline spelled as the ESCAPE `\n` agrees on both '
                .'sides, an interior REAL newline does not and is class M2.',
            ],
            'E — a quote byte inside an unquoted value' => [
                "QUEUE_CONNECTION=x\"y\n",
                'x"y',
                'xy',
                'E — A QUOTE BYTE INSIDE AN UNQUOTED VALUE',
                null,
                'UNREACHABLE for both keys: a connection name contains no quote byte. Mind the direction — '
                .'for CACHE_STORE the reader\'s answer is the one a human wants (`<FF>array` → `array`), '
                .'so this class is not uniformly "the reader is wrong".',
            ],
            'V — ${VAR} interpolation' => [
                "FOO=sync\nQUEUE_CONNECTION=\${FOO}\n",
                'sync',
                '${FOO}',
                'V — `${VAR}` INTERPOLATION',
                null,
                'REACHABLE for both keys, and the only reachable class. `.env` is legal phpdotenv input, so '
                .'`QUEUE_CONNECTION=${SOME_VAR}` with `SOME_VAR` set earlier in the same file is legal, '
                .'Laravel resolves it and this reader does not — the note then names a connection the app '
                .'never resolves. Pinned rather than implemented; the header says why.',
            ],
            'B — a lone CR is a line separator to phpdotenv' => [
                "QUEUE_CONNECTION=sync\rV\n",
                'sync',
                "sync\rV",
                'B — A LONE `\r` IS A LINE SEPARATOR',
                null,
                'UNREACHABLE for both keys: an ordinary CRLF file is fine (every CR precedes an LF and '
                .'lands at end of line, where the reader strips it), and the CI job rewrites the line '
                .'with `sed -i`, which keeps no CR.',
            ],
            'C — a control whitespace byte (form feed, vertical tab)' => [
                "QUEUE_CONNECTION=\ffoo\n",
                "\ffoo",
                'foo',
                'C — CONTROL WHITESPACE',
                null,
                'UNREACHABLE for both keys: no connection name carries a form feed or a vertical tab. '
                .'phpdotenv trims ` \\n\\r\\t\\0\\x0B` — not `\\f` — and its value lexer treats any '
                .'`ctype_space()` byte as the whitespace that may precede an inline `#`.',
            ],
            'N — a NUL byte' => [
                "QUEUE_CONNECTION=sy\0nc\n",
                "sy\0nc",
                'sync',
                'N — A NUL BYTE',
                null,
                'UNREACHABLE for both keys: a bash variable cannot hold a NUL at all, so `read` DROPS the '
                .'byte and carries on — this class is structural, not a parsing choice.',
            ],
            'U — an invalid UTF-8 byte together with a `$`' => [
                // PHP double quotes: `\x80` is the byte, `\$` is a literal dollar.
                // MEASURED (R8-1): phpdotenv answers `3f24` — `?` (`0x3F` is
                // `mb_substitute_character()`) plus `$` — and the reader `8024`.
                "QUEUE_CONNECTION=\x80\$\n",
                '?$',
                "\x80\$",
                'U — AN INVALID UTF-8 BYTE TOGETHER WITH A `$`',
                null,
                'UNREACHABLE for both keys: it needs BOTH halves, a byte that is not valid UTF-8 AND a '
                .'`$` in the value, and a connection name written as text carries neither. Each half alone '
                .'AGREES, and that is what makes this one class instead of "invalid bytes are mangled": '
                .'MEASURED `K=\\x80` → `80` on both sides (`Resolver::resolve()` returns the value while '
                .'`$vars === []`, `Loader/Resolver.php:43-45`), and MEASURED `K=\'\\x80$\'` → `8024` on '
                .'both sides (a `$` inside single quotes is not a var position). NOT class V either: no '
                .'`${…}` is resolved here, one byte is merely falsified. '
                .'MEASURED `mb_substr("\\x80$", 0, 2, \'UTF-8\')` → `3f24`.',
            ],
            'W — a bare NAME line with no `=` CLEARS the key' => [
                "QUEUE_CONNECTION=sync\nQUEUE_CONNECTION\n",
                '',
                'sync',
                'W — A BARE NAME LINE CLEARS THE KEY',
                null,
                'UNREACHABLE for both keys, and this verdict is about the WRITERS of a `.env` rather than '
                .'about the keys themselves — MEASURED 2026-10-03: zero lines matching '
                .'`^\s*(export\s+)?(QUEUE_CONNECTION|CACHE_STORE)\s*$` in `backend/.env.example`, in '
                .'`deployment/dev.env`, and in this host\'s own gitignored `backend/.env`. What is NOT '
                .'true is that it could not matter, and the distance between those two sentences is why '
                .'this note is this long. MEASURED through Laravel\'s own resolution chain — '
                .'`LoadEnvironmentVariables::createDotenv()` hands `Env::getRepository()` to '
                .'`Dotenv::create()`, and `config/queue.php:16` / `config/cache.php:18` read '
                .'`env($key, \'database\')` — a bare line AFTER a real assignment yields NULL from '
                .'phpdotenv, `PhpOption\\Option::fromValue(null)` is `None::create()`, and `env()` hands '
                .'back the CONFIG DEFAULT `database`, while this reader keeps the stale earlier value. '
                .'The note then takes the WRONG BRANCH: a `.env` holding `QUEUE_CONNECTION=sync` plus a '
                .'bare `QUEUE_CONNECTION` makes `scripts/e2e-up.sh` print `sync` and say the mail is '
                .'delivered inline, while the app resolves `database` and needs a worker. Two shapes do '
                .'NOT do that, both MEASURED: a bare line ALONE answers `\'\'` on BOTH sides and the note '
                .'falls back to the same config default, and an earlier value that happens to BE the '
                .'config default agrees by coincidence. The only mechanical writer that could produce '
                .'the form is a `sed` replacement that lost its `=VALUE`, and every one of those '
                .'re-checks the line on the next statement (`scripts/e2e-up.sh:139-140`, '
                .'`.github/workflows/ci.yml:521-529`), so it cannot pass unnoticed. THE GAP, stated '
                .'because it reads as covered and is not: '
                .'`test_the_forms_this_repo_writes_for_those_two_keys_are_not_divergent()` collects '
                .'writers with `^\s*(?:export\s+)?(QUEUE_CONNECTION|CACHE_STORE)\s*=(.*)$` — the `=` is '
                .'IN that pattern, so a bare line in a committed writer is not collected and would not '
                .'make that test red. This verdict rests on the scan quoted above, not on a pin. '
                .'Befund R8-1; reported as "residue 2" first, promoted here because it fits the one-row '
                .'shape (one oracle answer, one reader answer, one locale) that the locale residue does '
                .'not.',
            ],
        ];
    }

    /**
     * The reader's answer must not DEPEND ON THE LOCALE — and that is a claim a
     * differential against phpdotenv cannot make, because phpdotenv does not run
     * in the shell's locale.
     *
     * Befund R7-1: the trims used `[[:space:]]`, which glibc DECODES through the
     * locale's charset table. Under `LC_ALL=C.UTF-8` it matches U+3000, U+2028,
     * U+00A0, NEL and an overlong encoding of TAB; phpdotenv trims the byte set
     * `" \n\r\t\0\x0B"`, which contains none of them. MEASURED over 4000 bodies
     * from an alphabet containing exactly those bytes: **209 of 4000 were answered
     * differently under C.UTF-8 than under C** (seed 20261003; 196 of 4000 on a
     * second seed, 424242), and 44 of those agreed with phpdotenv under C and
     * disagreed under C.UTF-8 — the same function, the same bytes, a different
     * locale, a different answer. The old fuzz alphabet was ASCII-only, so "0
     * unattributed" was a true statement about an alphabet this file never named.
     * After the fix: 0 and 0, with not one answer changed under `LC_ALL=C`.
     *
     * So this is pinned as its own test, and the bodies here are chosen to FAIL a
     * `[[:space:]]` implementation and PASS the byte-set one: each carries a
     * non-ASCII byte at a trim position.
     *
     * NOT each of them, though, and the difference is measured (Befund R8-3).
     * Against the pre-fix reader (`bac5f41~1`, `[[:space:]]` everywhere) exactly
     * THREE of the SIX `$accepted` bodies change answer between `LC_ALL=C` and
     * `LC_ALL=C.UTF-8` — `U+3000 before the value`, `U+2028 after the value` and
     * `U+205F before the value`. The other three do not, because this glibc does
     * not decode NEL, U+00A0 or an overlong TAB as `[[:space:]]`: NEL → `c28573…`
     * and U+00A0 → `c2a0…` on both sides, unchanged. Nor does the overlong-TAB
     * body carry the sentence the old comment put on it: phpdotenv DOES trim
     * the real TAB in `sync\xC0\x09` (MEASURED: the oracle is `73796e63c0` — the
     * `\x09` is gone, the `\xC0` stays), so "where phpdotenv does not trim it" is
     * false of that one body. The three that stay still are not filler, and the
     * reason is a DIFFERENT assertion: a reader that matched every one of the six
     * would not merely flip, it would fail against phpdotenv on NEL and U+00A0.
     *
     * The bodies split in two, and the split is measured rather than tidiness.
     * Where phpdotenv ACCEPTS the file — a non-ASCII byte at a VALUE position —
     * the reader must agree with it in both locales. Where phpdotenv REJECTS it —
     * a non-ASCII byte in front of the NAME is an invalid name
     * (`EntryParser::parseName()`, `:107-109`, with `isValidName()` at
     * `:140-147`; `Parser.php:30` is only the `mapError` that turns that into an
     * `InvalidFileException`) — there is no oracle, and the only claim available
     * is that the reader agrees with ITSELF across locales. That second group is
     * not filler: MEASURED under the old `[[:space:]]`, five of those seven bodies
     * were answered EMPTY under `LC_ALL=C` and `sync` under `LC_ALL=C.UTF-8`, so
     * the name-side trims were exactly as locale-dependent as the value-side one,
     * and a pin built only from the accepted group would have left them
     * unwatched. The first cut of this test was in fact value-side only, and
     * mutations M2/M3/M4 below are the measurement that says so: all three are
     * GREEN, i.e. unwatched.
     *
     * The control matters as much as the cases: the byte set must not have
     * changed anything under `C`, or "0 locale-dependent" would be bought by a
     * reader that is uniformly different instead of one that is locale-free.
     *
     * What this test does NOT hold, stated because it was measured rather than
     * assumed: the `export` prefix check on its own. Reverting just that check to
     * `=~ ^[[:space:]]` (mutation M3) leaves this test GREEN, and the reason is
     * structural, not a gap in the bodies. `export<U+3000>FOO=1` asks for key
     * `FOO`; under the old reader both the check AND the name trim were
     * locale-aware, so under C.UTF-8 the prefix was stripped and then the same
     * byte was trimmed off the name and the key was found (MEASURED: `1` under
     * C.UTF-8, empty under C — locale-dependent). With the trims fixed but the
     * check reverted, the byte-set trim leaves the non-ASCII byte in the name, the
     * name never becomes `FOO`, and BOTH locales answer empty (MEASURED). So the
     * check's locale dependence is only observable together with the trim's, and
     * the trim is pinned. The check is kept on the byte set anyway: it is the same
     * defect waiting for the next person who reverts the trim.
     */
    public function test_the_reader_does_not_depend_on_the_locale(): void
    {
        // Each entry is the BODY ITSELF, not a one-element list — the list form is
        // the shape a DATA PROVIDER takes, and mixing the two here is what made
        // the first cut of this test hand `Dotenv::parse()` an array.
        //
        // Every body carries its non-ASCII byte at a TRIM POSITION, which is
        // where the two implementations can disagree: phpdotenv's trim is the
        // byte set `" \n\r\t\0\x0B"` and glibc's `[[:space:]]` decodes through
        // the locale's charset table. Three of the six `$accepted` bodies below
        // actually flip under the old reader — U+3000, U+2028, U+205F — and the
        // other three do not; the docblock says so with the numbers. They are
        // chosen so phpdotenv ACCEPTS the file — a non-ASCII byte in front of the
        // NAME is an invalid name (`EntryParser::parseName()`, `:107-109`, with
        // `isValidName()` at `:140-147`; MEASURED, `Dotenv::parse()` throws
        // `Encountered an invalid name`), and a rejected file has no answer to
        // compare. `U+3000 before the name` and its two siblings were in the
        // first cut of this test and had to come out.
        // Two groups, and the split is MEASURED, not tidiness.
        //
        // phpdotenv ACCEPTS: the reader must agree with it in both locales.
        $accepted = [
            'U+3000 IDEOGRAPHIC SPACE before the value' => "QUEUE_CONNECTION=\u{3000}sync\n",
            'U+2028 LINE SEPARATOR after the value' => "QUEUE_CONNECTION=sync\u{2028}\n",
            'U+205F MEDIUM MATHEMATICAL SPACE before the value' => "QUEUE_CONNECTION=\u{205F}sync\n",
            'NEL U+0085 before the value' => "QUEUE_CONNECTION=\u{0085}sync\n",
            'overlong encoding of TAB after the value' => "QUEUE_CONNECTION=sync\xC0\x09\n",
            'U+00A0 NO-BREAK SPACE on both sides of the value' => "QUEUE_CONNECTION=\u{00A0}sync\u{00A0}\n",
        ];

        // phpdotenv REJECTS — a non-ASCII byte in front of the NAME is an invalid
        // name (`EntryParser::parseName()`, `:107-109`, `isValidName()` at
        // `:140-147`; MEASURED), so there is no oracle to compare against and the
        // differential cannot speak about these bodies. They are here because the
        // reader is STILL locale-dependent on them under the old `[[:space:]]`, and
        // a name-side trim that silently depends on the caller's environment is the
        // same defect as a value-side one — it would just have no test watching it.
        //
        // MEASURED, and both halves of the old sentence here were wrong (Befund
        // R8-4): the direction is INVERTED — U+3000, space-then-U+3000, U+205F,
        // `export`+U+3000 and `export `+U+3000 were answered EMPTY under
        // `LC_ALL=C` and `sync` under `LC_ALL=C.UTF-8`, because the byte was
        // trimmed off the name only where the class matched it — and the overlong
        // TAB does not flip AT ALL (` \xC0\x09` answers empty under both locales),
        // so it is not among the triggers. FIVE of the seven flip; the two that do
        // not are NEL and the overlong TAB, neither of which this glibc decodes
        // as `[[:space:]]`. The conclusion this group was built for stands, and
        // U+3000 and U+205F carry it on their own.
        $rejected = [
            'U+3000 before the name' => "\u{3000}QUEUE_CONNECTION=sync\n",
            'space then U+3000 before the name' => " \u{3000}QUEUE_CONNECTION=sync\n",
            'U+205F before the name' => " \u{205F}QUEUE_CONNECTION=sync\n",
            'NEL before the name' => " \u{0085}QUEUE_CONNECTION=sync\n",
            'overlong encoding of TAB before the name' => " \xC0\x09QUEUE_CONNECTION=sync\n",
            'U+3000 after an `export` prefix' => "export\u{3000}QUEUE_CONNECTION=sync\n",
            'U+3000 after `export` and a space' => "export \u{3000}QUEUE_CONNECTION=sync\n",
        ];

        foreach ($accepted as $label => $body) {
            // Against phpdotenv: the reader must agree, in BOTH locales.
            foreach (['C', 'C.UTF-8'] as $locale) {
                $this->assertSame(
                    Dotenv::parse($body)['QUEUE_CONNECTION'] ?? '',
                    $this->readWithShellReaderInLocale($body, 'QUEUE_CONNECTION', $locale),
                    'scripts/lib/dotenv-value.sh read a different value than Dotenv\\Dotenv::parse() for this '
                    ."body under LC_ALL={$locale} (case: {$label}), so its answer is decided by the locale "
                    .'rather than by the grammar.'.self::readableHex($body),
                );
            }
        }

        foreach ($accepted + $rejected as $label => $body) {
            // And the two locales must agree with each other, which is the claim
            // no amount of comparing against phpdotenv can establish on its own —
            // and for the rejected group it is the ONLY claim available.
            $this->assertSame(
                $this->readWithShellReaderInLocale($body, 'QUEUE_CONNECTION', 'C'),
                $this->readWithShellReaderInLocale($body, 'QUEUE_CONNECTION', 'C.UTF-8'),
                'The reader answered this body ('.$label.') differently under LC_ALL=C than under '
                .'LC_ALL=C.UTF-8. A connection name read two ways depending on the caller\'s environment is '
                .'not a note, it is a coin toss.'.self::readableHex($body),
            );
        }

        // PREMISE for the group split, asserted rather than assumed: it is easy to
        // move a body from `rejected` to `accepted` (or the reverse) without
        // noticing, and then this test would be asserting less than it reads as.
        foreach ($rejected as $label => $body) {
            $threw = false;
            try {
                Dotenv::parse($body);
            } catch (\Throwable $e) {
                $threw = true;
            }

            $this->assertTrue(
                $threw,
                "This body is in the REJECTED group (case: {$label}), so it must still be one phpdotenv "
                .'refuses. If phpdotenv now accepts it, move it into the differential group above — otherwise '
                .'it is pinned here without an oracle, which is weaker than it looks.'
                .self::readableHex($body),
            );
        }

        // The control for "locale-independent": a divergence that exists in BOTH
        // locales identically. A REAL interior newline closes on a LATER line, so
        // this is class M2 — phpdotenv accepts the file and joins the lines
        // (`Lines::multilineProcess()` implodes the buffer with a newline) while
        // `read` hands the reader one line and the value state machine runs out
        // of input. MEASURED, same answer in both locales: phpdotenv `a<NL>b`,
        // reader `a`.
        //
        // It is here because "the reader does not depend on the locale" is a claim
        // that a test full of AGREEING cases cannot support: a reader that answered
        // nothing at all would pass every case above. This one is pinned as a
        // divergence in `divergenceClasses()`; what is asserted here is that the
        // divergence is the SAME in both locales.
        $m2 = "QUEUE_CONNECTION=\"a\nb\"\n";
        $this->assertSame(
            "a\nb",
            Dotenv::parse($m2)['QUEUE_CONNECTION'] ?? '',
            'PREMISE: phpdotenv must JOIN the lines of a balanced multiline value. If it stopped, class M2 '
            .'describes something that no longer happens.',
        );
        foreach (['C', 'C.UTF-8'] as $locale) {
            $this->assertSame(
                'a',
                $this->readWithShellReaderInLocale($m2, 'QUEUE_CONNECTION', $locale),
                'PREMISE: the reader must still STOP at the first line end — that is class M2, pinned in '
                ."divergenceClasses(). Measured under LC_ALL={$locale}.",
            );
        }
    }

    /**
     * The control for the locale pin: the byte set must not have changed ANY
     * answer under `LC_ALL=C`.
     *
     * Without this, "0 locale-dependent bodies" is also what you measure from a
     * reader that simply answers something else in both locales. The ASCII forms
     * below are the ones every other test in this file already pins, so if the
     * byte set ever drifts away from `[[:space:]]` on ASCII input — the `\f` in
     * class C, the `\v`, the `export` check — one of these goes red.
     *
     * MEASURED, and this control is load-bearing in its own right: deleting `\f`
     * from the byte set (`$ws`) is GREEN here (M4) even though it removes the
     * whole trim half of class C. Why: every body in this file is ASCII, and on
     * ASCII the two sets are indistinguishable EXCEPT at a form feed, and the
     * committed bodies contain none — the divergence needs `\f` INSIDE an
     * unquoted value, and `divergenceClasses()` is where that is pinned. So the
     * honest statement about this test is: it holds the byte set to ASCII
     * behaviour, and `\f` specifically is held by class C, not here.
     */
    public function test_the_byte_set_did_not_change_the_ascii_answers(): void
    {
        // `dotenvBodies()` and `cacheStoreBodies()` are DATA PROVIDERS: each entry is
        // `[$label => [$body, …]]`, so the bodies are the provider's VALUES, not
        // the provider itself. Iterating it directly — the first cut at this test
        // did — hands `Dotenv::parse()` an array (measured: `TypeError` at
        // `Dotenv.php:204`) and proves nothing about the locale at all.
        foreach ([$this->dotenvBodies(), $this->cacheStoreBodies()] as $key => $provider) {
            foreach ($provider as $bodies) {
                foreach ($bodies as $body) {
                    $this->assertSame(
                        $this->readWithShellReaderInLocale($body, $key === 0 ? 'QUEUE_CONNECTION' : 'CACHE_STORE', 'C.UTF-8'),
                        $this->readWithShellReaderInLocale($body, $key === 0 ? 'QUEUE_CONNECTION' : 'CACHE_STORE', 'C'),
                        'The reader answers this committed body differently per locale. Every body in this '
                        .'file is ASCII by construction — except where a case deliberately is not — so a '
                        .'difference here means the byte set and `[[:space:]]` have stopped agreeing on '
                        ."ASCII, which would silently change class C.\n--- body ---\n".self::readableHex($body),
                    );
                }
            }
        }
    }

    /**
     * A body as a hex dump, so a failure names BYTES and not just characters.
     */
    private static function readableHex(string $body): string
    {
        $out = '';
        foreach (str_split($body, 16) as $chunk) {
            $out .= "\n  ".bin2hex($chunk).'  '.$chunk;
        }

        return $out;
    }

    #[DataProvider('divergenceClasses')]
    public function test_the_documented_divergence_classes_are_documented(
        string $body,
        string $oracle,
        string $reader,
        string $label,
        ?string $mechanism,
        string $reachability,
    ): void {
        $key = 'QUEUE_CONNECTION';

        // Side one: phpdotenv. For M the answer is ABSENCE, which `?? ''` cannot
        // express, so the whole array is asserted for that one case.
        if ($mechanism === 'multiline') {
            $this->assertSame(
                [],
                Dotenv::parse($body),
                'PREMISE: phpdotenv must treat this unbalanced quote as an unterminated multiline and emit '
                .'nothing at all — that absence is what makes it class M.',
            );

            $m = new \ReflectionMethod(Lines::class, 'looksLikeMultilineStart');
            $m->setAccessible(true);
            $this->assertTrue(
                $m->invoke(null, rtrim($body, "\n")),
                'PREMISE: the divergence must be the multiline feature, not an unaccounted-for difference.',
            );
        } else {
            $this->assertSame(
                $oracle,
                Dotenv::parse($body)[$key] ?? '',
                "PREMISE: phpdotenv's answer for this body is what the header records for class {$label}. "
                .'If phpdotenv changed, the class is stale and the header must change with it.',
            );
        }

        // Side two: the reader, read the way the scripts read it.
        $this->assertSame(
            $reader,
            $this->readWithShellReader($body, $key),
            "The reader's answer for class {$label} is what the header records. If the reader changed, "
            .'this divergence is gone or has become another one — either way the header is now wrong.',
        );

        // The class must still BE a divergence, or it is not a class.
        $this->assertNotSame(
            $oracle,
            $reader,
            "Class {$label} no longer differs. A documented boundary that stopped existing has to be "
            .'removed from the header, not left standing — that is how a boundary becomes a lie.',
        );

        // And it must still be NAMED. Measured but unnamed is a hidden boundary.
        $this->assertStringContainsString(
            $label,
            $this->repositoryFile(self::READER),
            "Class {$label} is still a divergence, but its label is gone from the reader's header. "
            .'Undocumented differences are worse than documented ones: whoever reads the script has no '
            .'way to learn where not to trust it.',
        );

        // And it must carry an explicit reachability verdict for these two keys.
        $this->assertMatchesRegularExpression(
            '/^(REACHABLE|UNREACHABLE) for both keys/',
            $reachability,
            "Class {$label} has no reachability statement for the two keys these scripts read. Silence "
            .'about reachability is what made the old "exactly one difference" claim sound safer than it was.',
        );
    }

    /**
     * REACHABILITY, measured over the forms this repo actually writes.
     *
     * The header says class V — and only class V — can be reached for
     * `QUEUE_CONNECTION`/`CACHE_STORE`. A claim is only as good as its evidence,
     * so here it is checked against the WRITERS: every committed `.env`-format
     * line that assigns either key, plus the values the CI job's `sed -i`
     * writes, is fed to both sides and must come out identical. If somebody
     * writes `QUEUE_CONNECTION=${QUEUE_CONNECTION:-sync}` into `.env.example`,
     * or into a `sed` replacement in the E2E job, this goes red on the spot —
     * and that is exactly the day the note starts lying.
     *
     * A line is fed as the FILE UP TO AND INCLUDING IT, never alone (Befund R7-2).
     * The measurement that forced this: mutating `.env.example` to
     * `QUEUE_CONNECTION=${QUEUE_SRC}` plus `QUEUE_SRC=sync` one line above was
     * GREEN when the body was the single line, because `${QUEUE_SRC}` then has no
     * sibling entry to resolve against and both sides answer the literal text —
     * while the docblock promised exactly that mutation to go red. It is red now.
     *
     * `backend/.env` is deliberately NOT scanned: it is gitignored and differs
     * per machine, so asserting on it would make the suite's result depend on
     * whose checkout runs it. The committed writers are what decide what
     * everybody else gets.
     */
    public function test_the_forms_this_repo_writes_for_those_two_keys_are_not_divergent(): void
    {
        $assignments = [];

        foreach (['backend/.env.example', 'deployment/dev.env'] as $writer) {
            if (! file_exists($this->repositoryPathOf($writer))) {
                continue;
            }

            $lines = file($this->repositoryPathOf($writer), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $number => $line) {
                if (preg_match('/^\s*(?:export\s+)?(QUEUE_CONNECTION|CACHE_STORE)\s*=(.*)$/', $line, $m) !== 1) {
                    continue;
                }

                // The body is the file UP TO AND INCLUDING this line — not this
                // line alone, and that is the whole point of this test.
                //
                // Befund R7-2: it used to hand the reader `rtrim($line)."\n"`, one
                // line in isolation. That choice destroys class V's PRECONDITION:
                // `${NAME}` resolves only when `NAME` is among the SAME entries,
                // and a body consisting of one line has no other entries, so
                // `QUEUE_CONNECTION=${QUEUE_SRC}` with `QUEUE_SRC=sync` in the same
                // file came out IDENTICAL on both sides and the guard stayed green
                // while the docblock promised red. The whole slice is cheap — the
                // reader scans the file anyway — and it carries the precondition
                // with it.
                $assignments[] = [
                    "{$writer}:".($number + 1),
                    $m[1],
                    implode("\n", array_slice($lines, 0, $number + 1))."\n",
                ];
            }
        }

        // The E2E job's own writer is a `sed -i` replacement, not a line in a file.
        preg_match_all(
            "/^\s*-e 's\|\^[A-Z_]+=\.\*\|([A-Z_]+)=([^|']*)\|'/m",
            $this->repositoryFile('.github/workflows/ci.yml'),
            $sed,
            PREG_SET_ORDER,
        );

        foreach ($sed as $replacement) {
            if (! in_array($replacement[1], ['QUEUE_CONNECTION', 'CACHE_STORE'], true)) {
                continue;
            }

            $assignments[] = [
                '.github/workflows/ci.yml (sed replacement)',
                $replacement[1],
                $replacement[1].'='.$replacement[2]."\n",
            ];
        }

        $this->assertEqualsCanonicalizing(
            ['CACHE_STORE', 'QUEUE_CONNECTION'],
            array_values(array_unique(array_column($assignments, 1))),
            'PREMISE: this test must find the committed writers of BOTH keys. If it finds none, or only one '
            .'of them, the reachability claim it measures would rest on nothing — the failure mode of a '
            .'guard that passes because its search came up empty.',
        );

        foreach ($assignments as [$origin, $key, $body]) {
            $this->assertSame(
                Dotenv::parse($body)[$key] ?? '',
                $this->readWithShellReader($body, $key),
                "{$origin} writes a line for {$key} that the reader reads differently from phpdotenv. That "
                .'is one of the documented divergence classes reaching a key these two scripts read, which '
                .'the header says cannot happen — so either the writer changes or the header does.',
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* The 16 scenarios the note has to get right */
    /* ------------------------------------------------------------------ */

    /**
     * The note in `scripts/e2e-up.sh` is a BRANCH, not a value: it either tells
     * the reader that no worker is needed or that one is. That branch was
     * described by numbers in commit messages ("11 of 16 scenarios wrong",
     * "16/16 in the right branch, before 5/16") that nobody could reproduce —
     * the scenarios were never in the repo (Befund B9). They are here.
     *
     * What this matrix asserts is the thing a reader of the script actually
     * does: it runs the REAL block from `scripts/e2e-up.sh` — extracted from
     * the file, not retyped — in a temporary directory, with a controlled
     * environment and a controlled `.env`, and then reads the NOTE it printed.
     * Both the named connection and the branch are taken out of that text, so a
     * test cannot pass while the note says the wrong thing.
     *
     * The truth for each scenario is not a constant: it is `environment` first,
     * then `Dotenv::parse()` on the `.env` body, then `config/queue.php`'s
     * default — read from that config, so this test cannot drift from the
     * fallback the script actually uses.
     *
     * @return array<string, array{0: ?string, 1: ?string, 2: string}>
     */
    public static function queueNoteScenarios(): array
    {
        return [
            'environment wins over .env' => ['sync', "QUEUE_CONNECTION=database\n", 'sync'],
            'environment wins, no .env at all' => ['sync', null, 'sync'],
            'environment is not sync, .env says sync' => ['redis', "QUEUE_CONNECTION=sync\n", 'redis'],
            '.env plain — the form the CI job writes' => [null, "QUEUE_CONNECTION=sync\n", 'sync'],
            '.env plain, not sync' => [null, "QUEUE_CONNECTION=database\n", 'database'],
            '.env with an export prefix' => [null, "export QUEUE_CONNECTION=sync\n", 'sync'],
            '.env indented with spaces' => [null, "  QUEUE_CONNECTION=sync\n", 'sync'],
            '.env double quoted' => [null, "QUEUE_CONNECTION=\"sync\"\n", 'sync'],
            '.env single quoted' => [null, "QUEUE_CONNECTION='sync'\n", 'sync'],
            '.env quoted with an inline comment' => [null, "QUEUE_CONNECTION=\"sync\" # CI\n", 'sync'],
            '.env unquoted with an inline comment' => [null, "QUEUE_CONNECTION=sync # inline delivery\n", 'sync'],
            '.env duplicated, sync last' => [
                null,
                "QUEUE_CONNECTION=database\nQUEUE_CONNECTION=sync\n",
                'sync',
            ],
            '.env duplicated, database last' => [
                null,
                "QUEUE_CONNECTION=sync\nQUEUE_CONNECTION=database\n",
                'database',
            ],
            '.env has the key, but empty' => [null, "QUEUE_CONNECTION=\n", ''],
            '.env has no such key' => [null, "APP_ENV=local\n", ''],
            'no .env file at all' => [null, null, ''],
        ];
    }

    #[DataProvider('queueNoteScenarios')]
    public function test_the_queue_note_takes_the_right_branch(?string $environment, ?string $envBody, string $inEnv): void
    {
        $key = 'QUEUE_CONNECTION';

        // The truth: environment first, then phpdotenv on the `.env`, then the
        // framework default read out of config/queue.php.
        $configDefault = $this->queueConnectionConfigDefault();

        $fromEnvFile = '';
        if ($envBody !== null) {
            try {
                $fromEnvFile = Dotenv::parse($envBody)[$key] ?? '';
            } catch (\Throwable $e) {
                $this->fail("PREMISE: scenario .env body must be one phpdotenv ACCEPTS, or it proves nothing: {$e->getMessage()}");
            }
        }

        $expected = ($environment !== null && $environment !== '')
            ? $environment
            : ($fromEnvFile !== '' ? $fromEnvFile : $configDefault);

        $note = $this->runQueueNoteBlock($environment, $envBody);

        $printed = preg_match("/resolves to\n\s+'([^']*)'/", $note, $m) === 1 ? $m[1] : null;
        $this->assertNotNull(
            $printed,
            "PREMISE: the note must name the connection it resolved.\n--- note as printed ---\n{$note}",
        );
        $this->assertSame(
            $expected,
            $printed,
            "The note names `{$printed}` where the app resolves `{$expected}`.\n--- note as printed ---\n{$note}",
        );

        // The branch, read out of the note rather than recomputed from the value.
        $takesSyncBranch = str_contains($note, "With 'sync' the mail is delivered inline");
        $takesWorkerBranch = str_contains($note, "With a connection other than 'sync'");

        $this->assertSame(
            $expected === 'sync',
            $takesSyncBranch,
            "The note takes the WRONG branch for a resolved connection of `{$expected}`.\n"
            ."--- note as printed ---\n{$note}",
        );
        $this->assertSame(
            $expected !== 'sync',
            $takesWorkerBranch,
            "The note must print exactly one of its two branches.\n--- note as printed ---\n{$note}",
        );
    }

    /**
     * `config/queue.php`'s literal default for QUEUE_CONNECTION.
     *
     * Read from the config rather than restated here, for the reason the L2 fix
     * gives: a test that repeats the value it is about cannot notice when the
     * config changes.
     */
    private function queueConnectionConfigDefault(): string
    {
        $config = $this->repositoryFile('backend/config/queue.php');

        $this->assertSame(
            1,
            preg_match("/env\(\s*'QUEUE_CONNECTION'\s*,\s*'([^']+)'\s*\)/", $config, $matches),
            'PREMISE: config/queue.php must read QUEUE_CONNECTION with a literal default — that value is the '
            .'third resolution stage of the 16 scenarios, and the note falls back to it.',
        );

        return $matches[1];
    }

    /**
     * Run the note's real block from `scripts/e2e-up.sh` and return what it printed.
     *
     * The block is EXTRACTED from the script (first line matching
     * `^QUEUE_CONNECTION_EFFECTIVE=` through the matching `^fi`), never retyped:
     * a copy in this test would be a second description of the note, and the
     * note is the thing under test. The reader is sourced before the block
     * exactly as `scripts/e2e-up.sh` sources it — and that order is itself
     * pinned, by `test_both_scripts_source_the_one_reader()`.
     *
     * The environment is genuinely EMPTY apart from PATH, HOME and whatever the
     * scenario sets (`env -i`), so an ambient `QUEUE_CONNECTION` — and this
     * suite's own `phpunit.xml` sets one — cannot decide a scenario.
     */
    private function runQueueNoteBlock(?string $environment, ?string $envBody): string
    {
        $script = $this->repositoryPathOf('scripts/e2e-up.sh');

        $start = $this->lineMatching($script, '/^QUEUE_CONNECTION_EFFECTIVE=/m');
        $end = $this->lineMatching($script, '/^fi$/m', $start);
        $this->assertNotNull($start, "PREMISE: {$script} must resolve QUEUE_CONNECTION_EFFECTIVE.");
        $this->assertNotNull($end, 'PREMISE: the note block must be closed by a line `fi`.');

        $block = implode("\n", $this->lines($script, $start, $end));

        // The reader first, then the block: the same order the script uses.
        $source = 'set -euo pipefail; source "$1"; '.$block;

        $work = sys_get_temp_dir().'/queue-note-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($work, 0777, true), "PREMISE: a working directory {$work} must be creatable.");

        try {
            if ($envBody !== null) {
                file_put_contents($work.'/.env', $envBody);
            }

            // `env -i` is not fussiness: `phpunit.xml` pins `QUEUE_CONNECTION=sync`
            // for the backend suite, and Symfony's Process MERGES the env it is
            // given over the inherited one (`Process.php:333`,
            // `$env += $this->getDefaultEnv()`), so a scenario cannot un-set it.
            // With the inherited value in place FIVE of the sixteen scenarios
            // answered from the test runner instead of from their `.env` — and the
            // first version of this test reported that as 10 of 16 wrong.
            //
            // FIVE, measured (Befund R7-3): removing the `-i` and re-running
            // names exactly these five as red — `.env plain, not sync`, `duplicated,
            // database last`, `has the key, but empty`, `has no such key`, and `no
            // .env file at all`. The three whose environment argument is non-null
            // are unaffected, because Process merges the scenario's own value over
            // the inherited one; and the eleven whose `.env` says `sync` cannot tell
            // the two sources apart, because both say `sync`. An earlier version of
            // this comment said six, and the sixth does not exist — which is only
            // visible because the number is checkable by deleting one argument.
            $command = ['/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'HOME='.$work];

            if ($environment !== null) {
                $command[] = 'QUEUE_CONNECTION='.$environment;
            }

            array_push($command, '/bin/bash', '-c', $source, 'queue-note', $this->repositoryPathOf(self::READER));

            $process = new Process($command, $work);
            $process->setTimeout(20);

            try {
                $process->run();
            } finally {
                $process->stop(1);
            }

            $this->assertTrue(
                $process->isSuccessful(),
                "Running the note block failed (exit {$process->getExitCode()}):\n".$process->getErrorOutput(),
            );

            return $process->getOutput();
        } finally {
            // Nothing survives a scenario: the harness owns the directory it made.
            // `scandir`, not `glob` — `glob` skips dotfiles, and the `.env` this
            // test just wrote is exactly one.
            foreach (scandir($work) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($work.'/'.$entry);
                }
            }
            @rmdir($work);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Run `dotenv_value` from the real script file and return what it printed.
     *
     * The function is EXTRACTED from `scripts/lib/dotenv-value.sh` — read with
     * `file()`, located with `lineMatching()`, cut with `array_slice()` — rather
     * than retyped here, so the test cannot drift away from the file the scripts
     * actually source: a copy in this test would be a third copy, and the third
     * thing that can be wrong. (An earlier version of this docblock said the
     * extraction used `sed`; it does not, and it never did — only the
     * DESCRIPTION was wrong, not the code (Befund B4).)
     */
    private function readWithShellReader(string $body, string $key): string
    {
        $reader = $this->repositoryPathOf(self::READER);
        $this->assertFileIsReadable($reader, "PREMISE: {$reader} must be readable.");

        return $this->readExtractedReader($this->extractReaderFunction($reader), $body, $key, null);
    }

    /**
     * The same extraction, but the reader runs under an EXPLICIT `LC_ALL`.
     *
     * `Process` MERGES the env it is given over the inherited one, so passing
     * `LC_ALL` as an entry does not by itself guarantee it wins — hence the
     * explicit `export` inside the script text as well, and the two together are
     * what make the locale pin a measurement rather than a request.
     */
    private function readWithShellReaderInLocale(string $body, string $key, string $locale): string
    {
        $reader = $this->repositoryPathOf(self::READER);
        $this->assertFileIsReadable($reader, "PREMISE: {$reader} must be readable.");

        $function = $this->extractReaderFunction($reader);

        return $this->readExtractedReader($function, $body, $key, $locale);
    }

    /**
     * The two reader functions, cut out of the file — `file()`, located with
     * `lineMatching()`, taken with `array_slice()`.
     */
    private function extractReaderFunction(string $reader): string
    {
        $start = $this->lineMatching($reader, '/^dotenv_value\(\)/m');
        $end = $this->lineMatching($reader, '/^}/m', $start);
        $this->assertNotNull($start, "PREMISE: {$reader} must define dotenv_value().");
        $this->assertNotNull($end, "PREMISE: the dotenv_value() body in {$reader} must be closed by a line `}`.");

        // dotenv_parse_value() is the helper the extraction needs; take it too,
        // or the extracted dotenv_value() cannot run.
        $parseStart = $this->lineMatching($reader, '/^dotenv_parse_value\(\)/m');
        $parseEnd = $this->lineMatching($reader, '/^}/m', $parseStart);
        $this->assertNotNull($parseStart, "PREMISE: {$reader} must define dotenv_parse_value().");
        $this->assertNotNull($parseEnd, 'PREMISE: the dotenv_parse_value() body must be closed by a line `}`.');

        return implode("\n", array_merge(
            $this->lines($reader, $start, $end),
            $this->lines($reader, $parseStart, $parseEnd),
        ));
    }

    /**
     * Run one extracted function over one body and return what it printed.
     *
     * The function goes in over STDIN, not as an argument: it is shell source of
     * arbitrary length, and `Process` treats its 4th constructor argument as
     * input rather than as argv. The file and the key follow the `-c` script as
     * real positional parameters, so a value containing shell metacharacters
     * cannot be interpolated into code.
     */
    private function readExtractedReader(string $function, string $body, string $key, ?string $locale): string
    {
        $envFile = tempnam(sys_get_temp_dir(), 'dotenv-reader-');
        $this->assertIsString($envFile, 'PREMISE: a temporary file for the .env body must be creatable.');
        file_put_contents($envFile, $body);

        $prefix = $locale === null ? '' : 'export LC_ALL='.escapeshellarg($locale).'; ';

        $process = new Process(
            ['bash', '-c', 'set -euo pipefail; '.$prefix.'source /dev/stdin; dotenv_value "$1" "$2"', 'dotenv-reader', $envFile, $key],
            $this->repositoryPath(),
            [],
            $function,
        );
        $process->setTimeout(20);

        try {
            $process->run();
        } finally {
            // A reader that looped forever would outlive the test otherwise, and
            // this file is about a shell function: leaving one behind is the
            // failure mode the sibling test documents.
            $process->stop(1);
            @unlink($envFile);
        }

        $this->assertTrue(
            $process->isSuccessful(),
            "Running the reader failed (exit {$process->getExitCode()}):\n".$process->getErrorOutput(),
        );

        return $process->getOutput();
    }

    /**
     * The absolute path of a repository file, or fail with a readable message.
     */
    private function repositoryPathOf(string $relative): string
    {
        $path = $this->repositoryPath().'/'.$relative;
        $this->assertFileExists($path, "PREMISE: {$relative} must exist.");

        return $path;
    }

    /**
     * The CONTENTS of a repository file — what an assertion about a script's
     * text has to be given. Separate from `repositoryPathOf()` on purpose: the
     * two are easy to confuse, and confusing them makes an assertion about a
     * file's text quietly assert about its PATH instead (which is how this test
     * first failed: `assertStringContainsString` reported that the file did not
     * contain text that a `grep` showed in it).
     */
    private function repositoryFile(string $relative): string
    {
        $contents = file_get_contents($this->repositoryPathOf($relative));
        $this->assertIsString($contents, "PREMISE: {$relative} must be readable.");

        return $contents;
    }

    /**
     * The repository root — the parent of the Laravel application directory.
     */
    private function repositoryPath(): string
    {
        return dirname(base_path());
    }

    /**
     * The script with its full-line comments removed.
     *
     * Only whole-line comments (`#` in the first non-blank column) — an inline
     * `#` in shell starts a word only when it begins a word, and none of these
     * scripts rely on that distinction, so the simpler rule is enough and
     * errs towards keeping more text, i.e. towards a scan that finds MORE.
     *
     * The point is that both scripts DOCUMENT the defect they fixed by naming
     * `grep -E '^CACHE_STORE='` in prose. A guard that read the raw file would
     * forbid the explanation of the fix.
     */
    private function strippedOfShellComments(string $source): string
    {
        $kept = [];

        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * The 1-based number of the first line matching `$pattern` after `$from`.
     */
    private function lineMatching(string $file, string $pattern, ?int $from = null): ?int
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if ($from !== null && $number <= $from) {
                continue;
            }

            if (preg_match($pattern, $line) === 1) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function lines(string $file, int $from, int $to): array
    {
        return array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], $from - 1, $to - $from + 1);
    }
}
