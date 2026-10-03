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
 * ## Scope: TEN measured divergence classes (one of them pinned over TWO forms), and one named residue
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
 * Befund NEU-1 made that sentence true in a way it had not been, which is worth
 * stating because the count did not move: an unbalanced `="` AFTER an earlier
 * assignment is an ELEVENTH difference, and it is pinned as class M's second
 * FORM — a second row under M's one label — not as an eleventh class. "Another
 * class" and "another form of a pinned class" are told apart by what a row has
 * to carry, and this one needed nothing the table lacked: one body, one oracle,
 * one reader, one locale. What it did need was a second vendor ASSERTION per
 * class, because M's single row asserted `parse() === []`, and the finding is
 * precisely the body where that is false. Hence the mechanism column is a LIST
 * now. Eleven differences, ten classes, one residue, M pinned twice over.
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
    /* The documented boundary: ten classes (M pinned over two forms), one named residue */
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
     * That "how many answers a row carries" is the SHAPE, and Befund NEU-1 is
     * what showed it is not the same question as "how many rows a class has".
     * The finding was an eleventh difference: an unbalanced `="` AFTER an
     * earlier assignment, where phpdotenv keeps the EARLIER value (the
     * unterminated line never leaves `$multilineBuffer`,
     * `Lines::multilineProcess()`) and this reader takes the LATER truncated
     * one. It is NOT an eleventh class and it is not an eleventh row: it is
     * class M's second FORM, and the table's shape already had room for it —
     * one body, one oracle, one reader, one locale. What it did not have room
     * for was a second vendor ASSERTION per class, because the mechanism column
     * held a single token, and M's single row asserted
     * `Dotenv::parse($body) === []` — true of the body it held, false of the
     * finding's. So the fix is two rows under one label plus a mechanism LIST,
     * and it needs no second reader answer anywhere. That is the difference
     * between this and the locale residue, and it is why the counts below are
     * unchanged: TEN classes and ONE residue, with class M pinned twice over.
     *
     *  - `$oracle` is what `Dotenv\Dotenv::parse()` answers. These values were
     *    measured, not read off the vendor source; they are the pin. One
     *    companion case carries what `?? ''` cannot express — a key that is not
     *    there at all, which is class M's first form.
     *  - `$reader` is what `scripts/lib/dotenv-value.sh` answers for the same
     *    body, read exactly as the scripts read it — through command
     *    substitution, which is why class A exists at all.
     *  - `$label` must still occur in the reader's header. A class that is
     *    measured but unnamed is a hidden boundary, and the header is where the
     *    reader of the script learns where not to trust it.
     *  - `$mechanism` names the phpdotenv code that produces the oracle's
     *    answer, so "this is the multiline feature" is checked, not asserted.
     *    It is a LIST and may be empty, and the list is not decoration: Befund
     *    NEU-1 is the measurement that forced it. One row per class could hold
     *    only ONE vendor assertion, so class M's single row asserted
     *    `Dotenv::parse($body) === []` — true of the body it carried, false of
     *    the shape where an earlier assignment survives the unterminated line.
     *    Class M now has TWO rows under ONE label, each carrying the mechanisms
     *    that are true of it, and neither can be deleted as a duplicate: drop
     *    the earlier assignment from the second and its `earlier-value-survives`
     *    premise goes red, because it would then be describing the first.
     *    This is NOT the locale residue's problem, though — that one needs TWO
     *    READER ANSWERS per row, one per locale, and no amount of extra
     *    mechanisms supplies a second answer.
     *
     * The last element of each entry is that class's REACHABILITY statement for
     * the two keys these scripts ask for. It lives here, per class, because
     * reachability is a property of the class — not of the reader.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: list<string>, 5: string}>
     */
    public static function divergenceClasses(): array
    {
        return [
            'M — an UNBALANCED multiline with NO earlier assignment emits no key' => [
                "QUEUE_CONNECTION=\";\t\n",
                '',
                ';',
                'M — MULTILINE, UNBALANCED',
                ['multiline-buffered', 'no-key-at-all'],
                'UNREACHABLE for both keys: a value that opens a multiline cannot be a connection name. '
                .'Note the reader returns `;` and NOT `;` + a tab — the tab is trimmed off with the rest '
                .'of the value before it is parsed. An earlier version of this file and of the header '
                .'recorded `;` + a tab, and that was wrong (Befund B7). THIS ROW IS ONLY HALF THE CLASS. '
                .'The other half — an EARLIER assignment that survives — is the next row, and Befund '
                .'NEU-1 is what turned it from a footnote into a row: this row\'s mechanism assertion '
                .'(`no-key-at-all`) is only true because the body carries no earlier assignment, and the '
                .'header\'s sentence "phpdotenv emits no key at all" was true of this row and FALSE of '
                .'its sibling, which is the failure a single row per class cannot see.',
            ],
            'M — an UNBALANCED multiline AFTER an earlier assignment LEAVES that value standing' => [
                // FOUR lines, and each of the two after the unbalanced one is
                // load-bearing for the mechanism check rather than decoration.
                // Without them, `firstMultilineStartLine()` could be reverted to
                // the old inline `rtrim($body, "\n")` and the suite would stay
                // green — MEASURED, because the predicate happens to hold on the
                // joined string too. The COMMENT line is what finally separates
                // the two forms: `looksLikeMultilineStart()` returns FALSE for a
                // string whose `#` precedes the `="`, so a body with a comment in
                // front of the unbalanced line answers `true` line-wise and
                // `false` as one joined string (MEASURED both). That is the
                // difference this row exists to catch, and it is why the reader
                // and phpdotenv still disagree here: the comment is dropped, the
                // unbalanced line never reaches `$output`, and only the FIRST
                // assignment survives on the oracle side.
                "QUEUE_CONNECTION=v\n# c\nQUEUE_CONNECTION=\"x\nQUEUE_CONNECTION=w\n",
                'v',
                'w',
                'M — MULTILINE, UNBALANCED',
                ['multiline-buffered', 'earlier-value-survives'],
                'UNREACHABLE for both keys, and for the same reason as its sibling: it needs a value that '
                .'opens a multiline, which a connection name is not in any form. WHAT THE READER WOULD '
                .'THEN NAME is written down because it is the OPPOSITE direction from class W, and two '
                .'differences in opposite directions are the pair a reader of this table is most likely '
                .'to collapse into one. MEASURED through the note\'s OWN block, extracted from '
                .'scripts/e2e-up.sh and not retyped: a `.env` holding `QUEUE_CONNECTION=sync` followed by '
                .'`QUEUE_CONNECTION="` makes the note print `database` and say a worker is needed, while '
                .'the app resolves `sync` and delivers inline. That body is no longer only this sentence — it is a ROW in '
                .'`queueNoteScenarios()` (Befund R10-3), so the consequence is something a test RUNS now, not a '
                .'claim this table cell makes. The mechanism is that the unterminated '
                .'line contributes NO entry at all — `Lines::multilineProcess()` leaves it in '
                .'`$multilineBuffer` and it never reaches `$output` — so phpdotenv answers with what an '
                .'EARLIER line left behind while this reader answers with the truncated value of a line '
                .'phpdotenv never read. W is the mirror image: there the reader keeps a value phpdotenv '
                .'CLEARED, here the reader takes a value phpdotenv DISCARDED. It is UNREACHABLE for both keys '
                .'for the same reason as its sibling, and the header now says so for BOTH forms rather than '
                .'for M2 alone. Befund NEU-1.',
            ],
            'M2 — a BALANCED multiline: the value closes on a LATER line' => [
                "QUEUE_CONNECTION=\"a\nb\"\n",
                "a\nb",
                'a',
                'M2 — MULTILINE, BALANCED',
                [],
                'UNREACHABLE for both keys: a connection name does not span two lines. This class is a '
                .'SIBLING of M and not a footnote to it, and the difference is measured, not argued. '
                .'In M phpdotenv REJECTS nothing and the unterminated line produces NO entry, so the '
                .'answer is an earlier assignment or no key at all; here phpdotenv ACCEPTS the file and '
                .'joins the lines (`Lines::multilineProcess()` '
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
                [],
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
                [],
                'UNREACHABLE for both keys: a connection name contains no quote byte. Mind the direction — '
                .'for CACHE_STORE the reader\'s answer is the one a human wants (`<FF>array` → `array`), '
                .'so this class is not uniformly "the reader is wrong".',
            ],
            'V — ${VAR} interpolation' => [
                "FOO=sync\nQUEUE_CONNECTION=\${FOO}\n",
                'sync',
                '${FOO}',
                'V — `${VAR}` INTERPOLATION',
                [],
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
                [],
                'UNREACHABLE for both keys: an ordinary CRLF file is fine (every CR precedes an LF and '
                .'lands at end of line, where the reader strips it), and the CI job rewrites the line '
                .'with `sed -i`, which keeps no CR.',
            ],
            'C — a control whitespace byte (form feed, vertical tab)' => [
                "QUEUE_CONNECTION=\ffoo\n",
                "\ffoo",
                'foo',
                'C — CONTROL WHITESPACE',
                [],
                'UNREACHABLE for both keys: no connection name carries a form feed or a vertical tab. '
                .'phpdotenv trims ` \\n\\r\\t\\0\\x0B` — not `\\f` — and its value lexer treats any '
                .'`ctype_space()` byte as the whitespace that may precede an inline `#`.',
            ],
            'N — a NUL byte' => [
                "QUEUE_CONNECTION=sy\0nc\n",
                "sy\0nc",
                'sync',
                'N — A NUL BYTE',
                [],
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
                [],
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
                [],
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
        array $mechanism,
        string $reachability,
    ): void {
        $key = 'QUEUE_CONNECTION';

        // The mechanism column is a LIST, and Befund NEU-1 is why. One row per
        // class could carry only ONE vendor assertion, which forced class M's
        // single row to assert `Dotenv::parse($body) === []` — TRUE of the body
        // it holds and FALSE of its sibling, where an earlier assignment
        // survives the unterminated line. A pin that asserts half a class
        // cannot see the other half, and the header prose written from that
        // row ("phpdotenv emits no key at all") was measurably wrong. Two rows
        // sharing one LABEL, each carrying the mechanisms that are true of it,
        // is the shape that holds both halves — and it does so WITHOUT a second
        // reader answer per row, which is the thing the locale residue needs
        // and cannot have.
        foreach ($mechanism as $how) {
            match ($how) {
                // "this divergence IS phpdotenv's multiline feature", rather
                // than an unaccounted-for difference that happens to look like
                // one. Asserted on the line that actually starts the multiline,
                // which for a multi-line body is not the body itself.
                'multiline-buffered' => $this->assertTrue(
                    $this->looksLikeMultilineStart($this->firstMultilineStartLine($body)),
                    'PREMISE: the divergence must be the multiline feature, not an unaccounted-for '
                    .'difference. `Lines::looksLikeMultilineStart()` is what sends the line into '
                    .'`$multilineBuffer`, and it must still say yes for this body.',
                ),
                // The absence itself, which `[$key] ?? \'\'` cannot express:
                // a key that is NOT there and a key that is there and empty
                // are the same string.
                'no-key-at-all' => $this->assertSame(
                    [],
                    Dotenv::parse($body),
                    'PREMISE: with NO earlier assignment for this key, phpdotenv must emit nothing at all '
                    .'for it — that ABSENCE is one of the two halves of class M, and it is not the '
                    .'whole of it (Befund NEU-1: its sibling emits one key, the earlier value).',
                ),
                // The other half, and the two premises that make it a separate
                // ROW rather than a duplicate: the unterminated line on its own
                // contributes nothing, and the surviving answer comes from the
                // lines in front of it. Delete the earlier assignment and this
                // row's oracle changes — which is exactly why it cannot be
                // folded into the row above.
                'earlier-value-survives' => $this->assertSiblingProvenance(
                    $body,
                    $key,
                    $oracle,
                ),
                default => $this->fail(
                    "Class {$label} names the mechanism `{$how}`, which this test does not know. A "
                    .'mechanism token nobody checks is a comment in a table cell.',
                ),
            };
        }

        // And the oracle's answer for the key, for EVERY row — including class
        // M's, whose absence is asserted above and whose value here is `''`.
        $this->assertSame(
            $oracle,
            Dotenv::parse($body)[$key] ?? '',
            "PREMISE: phpdotenv's answer for this body is what the header records for class {$label}. "
            .'If phpdotenv changed, the class is stale and the header must change with it.',
        );

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
        //
        // The MECHANISM TOKEN is required too, not just the label, and this is
        // not decoration: the label is a fixed string, so a rename is caught,
        // but nothing above checks that the header still explains WHICH vendor
        // behaviour produces the answer. Befund NEU-1's prose was half-true
        // because it named the right class and the wrong consequence
        // ("emits no key at all"), and MEASURED, restating that wrong sentence
        // or deleting the sibling's paragraph outright both leave this suite
        // green. So the header has to carry the SENTENCE, and the sentence is
        // what makes the class usable by whoever reads the script.
        $header = $this->repositoryFile(self::READER);

        $this->assertStringContainsString(
            $label,
            $header,
            "Class {$label} is still a divergence, but its label is gone from the reader's header. "
            .'Undocumented differences are worse than documented ones: whoever reads the script has no '
            .'way to learn where not to trust it.',
        );

        //
        // EVERY sentence the row's tokens name, not the FIRST one that
        // matches. Befund R10-2, MEASURED: the helper this replaces returned
        // the first hit out of its own map, so the order in which a row wrote
        // its tokens down decided which half of the claim was checked —
        // swapping the sibling's two tokens left the suite green. Which
        // sentences a row owes the header is a property of its TOKENS.
        foreach ($this->headerSentencesFor($mechanism) as $sentence) {
            $this->assertStringContainsString(
                $sentence,
                $header,
                "Class {$label} is pinned on the mechanism `".implode('`, `', $mechanism).'`, but the reader\'s '
                .'header no longer states `'.$sentence.'`. The label is still there, so this reads as covered while the '
                .'sentence a reader of the script acts on is the wrong one — which is what Befund NEU-1 '
                .'measured: the header named class M correctly and described only HALF of what it does.',
            );
        }

        // And it must carry an explicit reachability verdict for these two keys.
        $this->assertMatchesRegularExpression(
            '/^(REACHABLE|UNREACHABLE) for both keys/',
            $reachability,
            "Class {$label} has no reachability statement for the two keys these scripts read. Silence "
            .'about reachability is what made the old "exactly one difference" claim sound safer than it was.',
        );

        // And it must assert EXACTLY the mechanisms its own provenance rests
        // on — same tokens, same ORDER — rather than at least those tokens in
        // any order.
        //
        // MEASURED, and it is Befund R10-2: the per-token `assertContains` this
        // replaces held MEMBERSHIP and nothing else, so the sibling's list could
        // be written `['earlier-value-survives', 'multiline-buffered']` and the
        // suite stayed green — 11 passed, nothing red. The order is not free
        // text: `requiredMechanismsFor()` answers shared-mechanism-first and
        // form-second, and the header's two sentences are written in that order
        // too, so a row that lists them the other way round is asserting about
        // its provenance in an order nothing else uses.
        //
        // EXACT, not "at least", and the tightening is deliberate: a token the
        // derivation does not ask for is the "mechanism token nobody checks is a
        // comment in a table cell" this file rejects, and the vocabulary itself
        // is already fail-closed by the `match` above, which fails an unknown
        // token with a message that says what to do about it. One `assertSame`
        // covers membership, order and completeness; it REPLACES the loop
        // instead of standing beside it, because a premise that cannot fail
        // where the one beside it holds is the silent restatement this file
        // documents its opinion about in `assertSiblingProvenance()`.
        [$required, $requiredRows] = $this->requiredMechanismsFor($body);

        $this->assertSame(
            $required,
            $mechanism,
            "Class {$label} must assert exactly the mechanisms its body's provenance rests on, in the order "
            .'`requiredMechanismsFor()` derives them'
            .($required === [] ? ' (none for this body)' : ': `'.implode('`, `', $required).'`')
            .', and it asserts '.($mechanism === [] ? 'NONE' : '`'.implode('`, `', $mechanism).'`')
            .'. Asserting the wrong mechanism is worse than asserting none: the row then reads as '
            .'checked while checking something else.',
        );

        // And a class with a SECOND form must have a row for it. Without this,
        // deleting the sibling row is green too — and class M's prose is
        // half-pinned again, which is the state Befund NEU-1 measured.
        //
        // COUNTED OVER EVERY ROW SHARING THE LABEL, not over the ones carrying a
        // mechanism: a class whose single form names no mechanism still has to
        // be counted, or `requiredRows = 1` would be satisfied by zero rows and
        // the whole assertion would be vacuous for every class it was written
        // around. It stays vacuous for a class with NO rows at all, though —
        // there is no row to run it from, and that is Befund R10-1.
        $rows = array_filter(
            self::divergenceClasses(),
            static fn (array $row): bool => $row[3] === $label,
        );

        $this->assertCount(
            $requiredRows,
            $rows,
            "Class {$label} has a body whose provenance rests on `".($required[count($required) - 1] ?? 'a second mechanism')
            .'`, so the table must hold that many rows asserting mechanisms under this label. A class with '
            .'only one of its two forms pinned has prose that is half-true — which is the state Befund '
            .'NEU-1 found and measured.',
        );
    }

    /**
     * Every class this file claims still HAS a row — counted here, and not in
     * the test above.
     *
     * Befund R10-1 (medium), and the shape of the hole is the reason it is a
     * separate test rather than one more assertion in the per-row one:
     * `test_the_documented_divergence_classes_are_documented()` is driven by a
     * DataProvider, so every assertion inside it is reached THROUGH a row. A
     * class whose rows were all deleted has no row left to run them from, and
     * the count it wanted — `requiredRows` — was computed from a body, not read
     * out of the table, so nothing noticed the absence.
     *
     * MEASURED, all three deletions, each on its own, against `ea9ecf0` and
     * nothing else touched: deleting BOTH of class M's rows → 9 passed, green;
     * deleting class W's row → 10 passed, green; deleting class V's row → 10
     * passed, green. So it is PRE-EXISTING, not something the round that found
     * it introduced — and that half is measured too, on `71946f3` (M was a
     * single row then): 10 passed at the baseline, and 9 passed, green, for
     * each of its three deletions. The two halves were NOT equally closed: the
     * comment above `assertCount($requiredRows, …)` had already named the
     * vacuity for the row that REMAINS, and `assertContains` had closed the
     * mechanism side for every surviving row. What was missing is only the
     * question "how many classes are there", which no row can ask.
     *
     * TEN, over ELEVEN rows: class M is pinned over two forms under one label,
     * which is the whole point of the mechanism column being a list (Befund
     * NEU-1). The distinct-label form is deliberate — `array_column(…, 3)` then
     * `array_unique` is what makes the assertion a statement about CLASSES; a
     * plain `count()` would be a statement about rows and would have been
     * satisfied by deleting one of M's two rows while adding a duplicate of
     * another class.
     *
     * A number is a weaker guard than the rows themselves, and it is written
     * as one: the intent is that adding a measured class raises this number in
     * the same commit that adds the row and the header sentence. Deleting a
     * class now fails here instead of quietly shortening the list, which is the
     * half of "a documented boundary that stopped existing has to be removed
     * from the header, not left standing" that the per-row test structurally
     * cannot reach.
     */
    public function test_every_documented_divergence_class_still_has_a_row(): void
    {
        $labels = array_column(self::divergenceClasses(), 3);

        $this->assertCount(
            10,
            array_unique($labels),
            'PREMISE: this file pins TEN divergence classes, one of them (M) over TWO forms. A class that '
            .'has lost its row — or a label renamed into an eleventh — must change this number in the same '
            .'commit, because nothing else can notice a class that no longer has a row at all: the '
            .'per-row test above is driven by those rows, so a class with none of them is never run '
            .'(Befund R10-1: MEASURED green for both M rows, for W and for V, each deleted on its own, on '
            .'`ea9ecf0` and on `71946f3` alike). '
            .'Classes now in the table: '.implode(' / ', array_unique($labels)).'.',
        );
    }

    /**
     * The sentences the reader's header must carry for a row's mechanisms.
     *
     * Per TOKEN rather than per class, because the thing that was measurably
     * wrong was never the label — it was the SENTENCE. A header that says
     * "MULTILINE, UNBALANCED" and then describes only the form with no earlier
     * assignment is exactly the failure this guards, so the guard names the two
     * sentences the two forms are told apart by.
     *
     * EVERY applicable sentence, and that is the fix for Befund R10-2 rather
     * than a tidiness change: the version this replaces returned the FIRST map
     * entry that matched, so of a two-token list exactly one sentence was ever
     * checked, and which one depended on the map — MEASURED, writing the
     * sibling's tokens the other way round (`['earlier-value-survives',
     * 'multiline-buffered']`) left the suite green, 11 passed. The claim a row
     * makes about the header is a function of its TOKENS; the order somebody
     * wrote them down in is not part of it, and the token order itself is now
     * pinned separately by `assertSame($required, $mechanism)` in the caller.
     * The list this returns is walked in the MAP's order, and that order is
     * the header's order RUN BACKWARDS — MEASURED, the three entries land on
     * `scripts/lib/dotenv-value.sh:107`, `:102`, `:96`, strictly descending,
     * so M's first form yields `NO EARLIER ASSIGNMENT` and then `MULTILINE,
     * UNBALANCED`, and its second yields `WITH AN EARLIER ASSIGNMENT` and then
     * that same shared sentence. The caller's failure message therefore walks
     * the header BOTTOM-TO-TOP, not in the order a reader of the script meets
     * the sentences; an earlier version of this sentence claimed it did, and
     * the claim was false of every list this helper returns (Befund B2, Runde
     * 11). Nothing pins the map's order — that is precisely the freedom R10-2
     * measured — so only the SET is a contract here: if a header has lost two
     * of the three sentences, which one the message names first is not
     * something a reader may rely on.
     *
     * `$multiline-buffered` maps to the sentence shared by both M rows: it is
     * the mechanism they have in common. Both rows therefore check that one
     * shared sentence — not "twice for the same row", which would say nothing,
     * but once per row, which is what makes a header that keeps the shared
     * sentence while dropping one of the two form sentences red in BOTH forms.
     *
     * An UNKNOWN token yields no sentence and is deliberately not this
     * helper's business: the `match` in the test fails it first, naming the
     * token and the remedy, which is a better message than a lookup miss.
     *
     * @param  list<string>  $mechanism
     * @return list<string>
     */
    private function headerSentencesFor(array $mechanism): array
    {
        $sentences = [];

        foreach (['earlier-value-survives' => 'WITH AN EARLIER ASSIGNMENT: phpdotenv keeps the EARLIER value',
            'no-key-at-all' => 'NO EARLIER ASSIGNMENT: phpdotenv emits no key at all',
            'multiline-buffered' => 'MULTILINE, UNBALANCED (`Lines::looksLikeMultilineStart()`)',
        ] as $token => $sentence) {
            if (in_array($token, $mechanism, true)) {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
    }

    /**
     * The mechanism tokens a row MUST assert, keyed by the row's own PROVENANCE.
     *
     * A row whose body is M's SECOND form must carry `earlier-value-survives` and
     * must have a sibling row in the table — that is what makes deleting the
     * sibling red (MEASURED green before this). A row whose body is M's FIRST
     * must carry `no-key-at-all`, which is what makes dropping that token red
     * (also MEASURED green before this). Every other class gets `[[], 1]`: its
     * mechanism is already fully expressed by its oracle and reader answers, and
     * one row under its label is all it is required to have.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function requiredMechanismsFor(string $body): array
    {
        // MEASURED, not keyed off the body's BYTES, and the earlier byte-keyed
        // version is the reason this helper exists in this form. Keyed off bytes
        // it was satisfied by exactly the shape of body the table happened to
        // hold — and MEASURED, that made premise 2 below UNLOAD-BEARING:
        // deleting the earlier assignment from the sibling's body turned the
        // helper's answer from "second form" to "not mine", so the ROW-COUNT
        // assertion went red and the provenance assertion was never consulted.
        // Two guards, one of which watches the body, and the weaker one covers
        // for the stronger.
        //
        // So the question is asked of phpdotenv instead: does the unterminated
        // block contribute nothing AND does the key still resolve afterwards?
        // That is the second form, whatever the body is written as, and a body
        // edit that changes it has to change this answer too — which is the
        // property a byte comparison did not have.
        $lines = $this->phpdotenvLines($body);
        $start = null;
        foreach ($lines as $index => $line) {
            if ($this->looksLikeMultilineStart($line)) {
                $start = $index;

                break;
            }
        }

        if ($start === null) {
            return [[], 1];
        }

        $blockContributesNothing = Dotenv::parse(implode("\n", array_slice($lines, $start))) === [];
        $parsed = Dotenv::parse($body);
        $earlierValueSurvives = $blockContributesNothing
            && array_key_exists('QUEUE_CONNECTION', $parsed);

        if ($earlierValueSurvives) {
            return [['multiline-buffered', 'earlier-value-survives'], 2];
        }

        return $blockContributesNothing
            ? [['multiline-buffered', 'no-key-at-all'], 2]
            : [[], 1];
    }

    /**
     * The lines phpdotenv splits a body into, the way `Parser::parse()` does.
     *
     * `"/(\r\n|\n|\r)/"` — a BYTE rule, which is class B's whole mechanism.
     * Written here rather than reused from the reader so a change to either
     * side shows up as a red premise instead of as agreement.
     *
     * @return list<string>
     */
    private function phpdotenvLines(string $body): array
    {
        return preg_split('/(\r\n|\n|\r)/', $body) ?: [];
    }

    /**
     * `Lines::looksLikeMultilineStart()`, read from the vendor code rather than
     * restated — it is a private static, so via reflection, and a rename in
     * phpdotenv turns into a red premise instead of a silently-passing one.
     */
    private function looksLikeMultilineStart(string $line): bool
    {
        $m = new \ReflectionMethod(Lines::class, 'looksLikeMultilineStart');
        $m->setAccessible(true);

        return $m->invoke(null, $line);
    }

    /**
     * The line that opens the multiline, or `''` when none does.
     *
     * For a single-line body that is the body; for a multi-line one it is a
     * LATER line than `rtrim($body, "\n")` would name, which is why this exists
     * rather than the inline `rtrim` the first cut of the mechanism column used.
     *
     * MEASURED, and that is why it is a method rather than an inline
     * expression: on class M's FIRST form — a single-line body — reverting it to
     * `rtrim($body, "\n")` leaves the suite green, because the predicate
     * happens to hold on the whole string too. The sibling's body is THREE lines
     * for exactly this reason, and with them present the same revert goes red.
     * A helper that only one of its own class's two bodies can exercise is a
     * helper whose correctness is untested on the other.
     */
    private function firstMultilineStartLine(string $body): string
    {
        foreach ($this->phpdotenvLines($body) as $line) {
            if ($this->looksLikeMultilineStart($line)) {
                return $line;
            }
        }

        return '';
    }

    /**
     * Class M's second form: the unterminated line contributes NOTHING, and the
     * answer that survives comes from the lines in FRONT of it.
     *
     * Two premises, and each is what keeps this row from being a duplicate of
     * the one above it:
     *
     *  1. The unterminated line ALONE parses to nothing. That is the mechanism
     *     (`Lines::multilineProcess()` keeps it in `$multilineBuffer` and it
     *     never reaches `$output`), and it is why the surviving value is an
     *     EARLIER one rather than a truncated one.
     *  2. The body WITHOUT the unterminated line parses to exactly the oracle
     *     answer — the provenance claim: the answer comes from in front of the
     *     unbalanced line.
     *
     * And the honest status of (2), MEASURED rather than assumed: it is NOT an
     * independent guard. Deleting it leaves the suite fully GREEN, because (1)
     * plus the per-row oracle assertion already imply it — if the unterminated
     * block contributes no entry, then the whole parse equals the parse of the
     * lines in front of it, so `parse($body) === parse($prefix)` follows and
     * asserting it separately can only repeat what has been shown. It is kept
     * because it NAMES the provenance in the place a reader of this class comes
     * to learn it, and because the cost of a redundant premise is one parse
     * while the cost of a reader inferring "the answer might come from the
     * swallowed lines" is a wrong inference about a documented boundary. Its
     * docblock says plainly that it is a restatement, not a guard: a comment
     * claiming to be load-bearing where a measurement says it is not is worse
     * than no comment.
     *
     * What DOES catch "somebody deleted the earlier assignment" is worth naming,
     * because it is not this premise: it is the per-row oracle assertion.
     * MEASURED — that body then has no key at all, so the row's recorded oracle
     * `v` no longer matches and the row goes red there.
     *
     * The earlier assignment's own VALUE is never restated as a literal here;
     * both sides read it out of `$body`, so a changed body cannot leave a stale
     * premise behind.
     */
    private function assertSiblingProvenance(string $body, string $key, string $oracle): void
    {
        $lines = $this->phpdotenvLines($body);
        $start = null;
        foreach ($lines as $index => $line) {
            if ($this->looksLikeMultilineStart($line)) {
                $start = $index;

                break;
            }
        }

        $this->assertNotNull(
            $start,
            'PREMISE: this body must contain an unbalanced `="` — the mechanism that puts it in '
            .'class M. Without one, the earlier-value assertion below would be measuring a different '
            .'class (V, W or E) while claiming to be about this one.',
        );

        $unterminated = implode("\n", array_slice($lines, $start));
        $this->assertSame(
            [],
            Dotenv::parse($unterminated),
            'PREMISE: the unterminated line, taken on its own, must contribute NO entry at all. That '
            .'is the mechanism of class M (`Lines::multilineProcess()` never returns it from '
            .'`$multilineBuffer`), and it is what makes the surviving answer an EARLIER value instead '
            .'of the reader\'s truncated one. If phpdotenv ever started emitting it, this row would '
            .'describe a divergence that no longer exists in this form.',
        );

        $prefix = implode("\n", array_slice($lines, 0, $start));
        $this->assertSame(
            $oracle,
            Dotenv::parse($prefix)[$key] ?? '',
            'PREMISE: the surviving answer must come from the lines BEFORE the unbalanced one. Delete '
            .'that earlier assignment and this body stops being the second form of class M and becomes '
            .'the first — a row that already exists, and one whose oracle is absence rather than a '
            .'value. That is the whole difference between the two rows sharing this label.',
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
    /* The 17 scenarios the note has to get right */
    /* ------------------------------------------------------------------ */

    /**
     * The note in `scripts/e2e-up.sh` is a BRANCH, not a value: it either tells
     * the reader that no worker is needed or that one is. That branch was
     * described by numbers in commit messages ("11 of 16 scenarios wrong",
     * "16/16 in the right branch, before 5/16") that nobody could reproduce —
     * the scenarios were never in the repo (Befund B9). They are here, and the
     * matrix has grown since those numbers were written: SIXTEEN rows then,
     * SEVENTEEN now, the seventeenth being the one body class M's own note
     * prose claims and nothing pinned (Befund R10-3).
     *
     * What this matrix asserts is the thing a reader of the script actually
     * does: it runs the REAL block from `scripts/e2e-up.sh` — extracted from
     * the file, not retyped — in a temporary directory, with a controlled
     * environment and a controlled `.env`, and then reads the NOTE it printed.
     * Both the named connection and the branch are taken out of that text, so a
     * test cannot pass while the note says the wrong thing.
     *
     * FOUR values per row, and the fourth is the one Befund R10-3 added:
     * `$environment`, the `.env` BODY, what the APP resolves (`$inEnv`), and
     * what the NOTE prints (`$notePrints`). The first three existed before and
     * the third was DECORATIVE — it was passed into the test and never read,
     * while the truth was recomputed in the test body and compared only with
     * the note. Sixteen rows therefore agreed with themselves and nothing
     * checked the recorded number. It is checked now, and it is what lets a
     * row say the two DIFFER: `$inEnv` is the app's answer — environment first,
     * then `Dotenv::parse()` on the `.env` body — while `$notePrints` is what
     * the reader and the note's own fallback chain produce, environment first,
     * then `dotenv-value.sh`, then `config/queue.php`'s default. For the first
     * sixteen rows the two literals are the same string, written out twice on
     * purpose — the repeat IS the claim, and the seventeenth row is the case
     * where it stops being one.
     *
     * Neither truth is a constant, and neither is restated here: the app's is
     * recomputed through `Dotenv::parse()`, the note's through the extracted
     * reader plus a default read out of `config/queue.php` — so this test cannot
     * drift from the resolution the scripts actually perform.
     *
     * @return array<string, array{0: ?string, 1: ?string, 2: string, 3: string}>
     */
    public static function queueNoteScenarios(): array
    {
        return [
            'environment wins over .env' => ['sync', "QUEUE_CONNECTION=database\n", 'sync', 'sync'],
            'environment wins, no .env at all' => ['sync', null, 'sync', 'sync'],
            'environment is not sync, .env says sync' => ['redis', "QUEUE_CONNECTION=sync\n", 'redis', 'redis'],
            '.env plain — the form the CI job writes' => [null, "QUEUE_CONNECTION=sync\n", 'sync', 'sync'],
            '.env plain, not sync' => [null, "QUEUE_CONNECTION=database\n", 'database', 'database'],
            '.env with an export prefix' => [null, "export QUEUE_CONNECTION=sync\n", 'sync', 'sync'],
            '.env indented with spaces' => [null, "  QUEUE_CONNECTION=sync\n", 'sync', 'sync'],
            '.env double quoted' => [null, "QUEUE_CONNECTION=\"sync\"\n", 'sync', 'sync'],
            '.env single quoted' => [null, "QUEUE_CONNECTION='sync'\n", 'sync', 'sync'],
            '.env quoted with an inline comment' => [null, "QUEUE_CONNECTION=\"sync\" # CI\n", 'sync', 'sync'],
            '.env unquoted with an inline comment' => [null, "QUEUE_CONNECTION=sync # inline delivery\n", 'sync', 'sync'],
            '.env duplicated, sync last' => [
                null,
                "QUEUE_CONNECTION=database\nQUEUE_CONNECTION=sync\n",
                'sync',
                'sync',
            ],
            '.env duplicated, database last' => [
                null,
                "QUEUE_CONNECTION=sync\nQUEUE_CONNECTION=database\n",
                'database',
                'database',
            ],
            '.env has the key, but empty' => [null, "QUEUE_CONNECTION=\n", '', 'database'],
            '.env has no such key' => [null, "APP_ENV=local\n", '', 'database'],
            'no .env file at all' => [null, null, '', 'database'],

            // The one row where `$notePrints` and `$inEnv` DIFFER, and it is
            // here rather than in `divergenceClasses()` because the thing to
            // pin is not a reader answer but a NOTE (Befund R10-3). MEASURED
            // through the note's own block, extracted from `scripts/e2e-up.sh`:
            // phpdotenv keeps the EARLIER assignment and the app therefore
            // delivers inline, while the reader takes the truncated value of
            // the unbalanced line — that answer is empty — and the note falls
            // back to the config default and says a worker is needed.
            //
            // A `divergenceClasses()` row could not carry it: that table's shape
            // is one body, one oracle answer, one reader answer, and what is
            // claimed here is what a SCRIPT then DOES with the two different
            // answers. Two facts make it worth a row here instead: the note is
            // what a developer acts on, and the fallback it falls back to is
            // read out of `config/queue.php` by `queueConnectionConfigDefault()`
            // rather than restated, so neither the fallback nor its value is a
            // second copy in this file.
            'class M, second form — the earlier assignment phpdotenv keeps' => [
                null,
                "QUEUE_CONNECTION=sync\nQUEUE_CONNECTION=\"\n",
                'sync',
                'database',
            ],
        ];
    }

    #[DataProvider('queueNoteScenarios')]
    public function test_the_queue_note_agrees_with_phpdotenv_or_names_the_divergence(
        ?string $environment,
        ?string $envBody,
        string $inEnv,
        string $notePrints,
    ): void {
        $key = 'QUEUE_CONNECTION';

        // The framework default, read out of the file rather than restated —
        // it is the LAST stage of the note's resolution and the reason an
        // empty answer becomes `database` instead of nothing.
        $configDefault = $this->queueConnectionConfigDefault();

        $fromEnvFile = '';
        if ($envBody !== null) {
            try {
                $fromEnvFile = Dotenv::parse($envBody)[$key] ?? '';
            } catch (\Throwable $e) {
                $this->fail("PREMISE: scenario .env body must be one phpdotenv ACCEPTS, or it proves nothing: {$e->getMessage()}");
            }
        }

        // TWO truths, and conflating them is what made the seventeenth row
        // unwritable (Befund R10-3). The single `$expected` this replaces was
        // computed as "environment, else phpdotenv's value if it is not empty,
        // else the config default" — and that is the NOTE's rule, not the app's:
        // `config/queue.php` reads `env($key, 'database')`, whose default
        // applies to a MISSING value, so a `.env` holding `QUEUE_CONNECTION=`
        // resolves to the empty string in the app while the note prints
        // `database`. Correcting the record rather than the expectation.
        //
        // The APP, through phpdotenv: an empty value stays empty.
        $appResolves = ($environment !== null && $environment !== '')
            ? $environment
            : $fromEnvFile;

        // The NOTE, through ITS OWN reader — not through phpdotenv, which the
        // first version of this test used in the reader's place and could get
        // away with only because the two agreed on every one of its sixteen
        // bodies. Asking the reader directly is what lets a row say "these two
        // answers differ" instead of only "this one answer".
        $readerAnswer = $envBody === null ? '' : $this->readWithShellReader($envBody, $key);
        $noteShouldPrint = ($environment !== null && $environment !== '')
            ? $environment
            : ($readerAnswer !== '' ? $readerAnswer : $configDefault);

        // Both recorded truths are LOAD-BEARING. Neither was read before
        // (Befund R10-3): the third column was passed into this method and never
        // touched, while the truth was recomputed here and compared only against
        // the note — so a row could have recorded anything and stayed green.
        $this->assertSame(
            $inEnv,
            $appResolves,
            "PREMISE: the row records that the app resolves `{$inEnv}` here and phpdotenv says "
            ."`{$appResolves}`. One of the two is wrong, and the divergence guard below compares against the "
            .'recorded truth.',
        );
        $this->assertSame(
            $notePrints,
            $noteShouldPrint,
            "PREMISE: the row records that the note prints `{$notePrints}` here, and the note's own resolution "
            ."rule — environment, else `dotenv-value.sh`'s answer, else the config default `{$configDefault}` — "
            ."says `{$noteShouldPrint}`.",
        );

        $note = $this->runQueueNoteBlock($environment, $envBody);

        $printed = preg_match("/resolves to\n\s+'([^']*)'/", $note, $m) === 1 ? $m[1] : null;
        $this->assertNotNull(
            $printed,
            "PREMISE: the note must name the connection it resolved.\n--- note as printed ---\n{$note}",
        );
        $this->assertSame(
            $notePrints,
            $printed,
            "The note names `{$printed}` where this row records `{$notePrints}`.\n--- note as printed ---\n{$note}",
        );

        // The branch, read out of the note rather than recomputed from the value
        // — and taken against WHAT THE NOTE PRINTED, not against what the app
        // resolves. That is the distinction the seventeenth row exists for: the
        // note is self-consistent either way, and only the comparison below
        // says whether that self-consistency agrees with the app.
        $takesSyncBranch = str_contains($note, "With 'sync' the mail is delivered inline");
        $takesWorkerBranch = str_contains($note, "With a connection other than 'sync'");

        $this->assertSame(
            $printed === 'sync',
            $takesSyncBranch,
            "The note takes the WRONG branch for the connection it printed, `{$printed}`.\n"
            ."--- note as printed ---\n{$note}",
        );
        $this->assertSame(
            $printed !== 'sync',
            $takesWorkerBranch,
            "The note must print exactly one of its two branches.\n--- note as printed ---\n{$note}",
        );

        // And the question the sixteen agreeing rows cannot ask: does the reader
        // agree with phpdotenv on THIS `.env`, or does it differ in a shape this
        // file names. Not "if they differ, that is fine" — a difference nobody
        // can name is a bug in the reader, which is the sentence this file's
        // whole scope rests on.
        //
        // MEASURED (Befund R10-3) on the seventeenth row: the reader answers
        // `''` where phpdotenv answers `sync`, so the note prints the config
        // default and says a worker is needed while the app delivers inline.
        if ($readerAnswer !== $fromEnvFile) {
            $this->assertSame(
                ['multiline-buffered', 'earlier-value-survives'],
                $this->requiredMechanismsFor((string) $envBody)[0],
                'PREMISE: a `.env` the note reads differently from phpdotenv has to be one of the DOCUMENTED '
                .'divergences — class M\'s second form, an unbalanced `="` after an earlier assignment. '
                .'`requiredMechanismsFor()` asks phpdotenv about the body itself instead of trusting a label, '
                .'so a body that starts disagreeing for some other reason goes red here instead of quietly '
                .'joining the rows that disagree by design.',
            );

            $this->assertSame(
                '',
                $readerAnswer,
                'PREMISE: class M\'s second form answers with the TRUNCATED value of the unbalanced line, which '
                ."is empty here — this row's reader answer is `{$readerAnswer}`. The consequence the note then "
                .'draws (an empty answer, therefore the config default) belongs to that shape; a different '
                .'answer would mean this row had stopped measuring the divergence class M\'s prose names.',
            );
        }
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
            .'third resolution stage of all 17 scenarios, and the note falls back to it.',
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
            // With the inherited value in place SIX of the seventeen scenarios
            // answered from the test runner instead of from their `.env` — and
            // the first version of this test reported that as 10 of 16 wrong,
            // over the sixteen rows the matrix had then.
            //
            // SIX, re-measured (Befund R10-3): removing the `-i` and re-running
            // names exactly these — `.env plain, not sync`, `duplicated,
            // database last`, `has the key, but empty`, `has no such key`, `no
            // .env file at all`, and the seventeenth row, which is the note-body
            // of class M's second form: its recorded note answer is `database`,
            // the inherited `sync` decides it instead, and the row goes red on
            // its recorded value. Befund R7-3 measured FIVE of the sixteen before
            // that row existed. The three whose environment argument is non-null
            // are unaffected, because Process merges the scenario's own value over
            // the inherited one; ELEVEN rows still cannot tell the two sources
            // apart — those eight whose `.env` says `sync` on both sides, plus the
            // three with an environment of their own — which is the same eleven as
            // then, since the seventeenth row lands in neither group. An earlier
            // version of this comment said six of sixteen were affected and named
            // the wrong sixth, which is only visible because the number is
            // checkable by deleting one argument.
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
