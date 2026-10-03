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
 *    `QUEUE_CONNECTION="sync"` — the form the CI E2E job itself writes — was read
 *    as a value that is not `sync`, and the note told the reader to start a
 *    worker for a stack that delivers inline.
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
 * `test_the_reader_can_go_red()` proves the pin has teeth: it feeds the reader a
 * body phpdotenv parses differently and requires the test's own comparison to
 * notice. Without it, a comparison that silently compared nothing would look
 * exactly like a comparison that passed.
 *
 * ## The reader under test is the SHARED one
 *
 * `scripts/lib/dotenv-value.sh` is sourced by both scripts. That is the point of
 * L1: the two copies had already drifted apart, which is the whole failure mode.
 * `test_both_scripts_source_the_one_reader()` keeps it that way — if a script ever
 * regains a private reader, this fails even though every parsing assertion below
 * would still pass.
 *
 * ## Scope: what the reader deliberately does not implement
 *
 * phpdotenv has features this reader ignores — multiline values continued over
 * following lines (`Lines::looksLikeMultilineStart`) and `$VAR` interpolation
 * (`RepositoryBuilder`). A randomized differential over 8000 bodies (measured
 * 2026-10-03) found exactly ONE input where the two disagree:
 * `K=";\t`, an unbalanced quote that phpdotenv treats as an unterminated
 * multiline and therefore never emits. Neither feature can occur for a key whose
 * value is a connection name, which is the only thing these two scripts ask for.
 * That boundary is asserted as a measurement, not asserted away.
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
     * A negative control: a body the reader gets WRONG must be reported.
     *
     * Without this, a comparison that compared nothing — a `parse()` that
     * returned an empty array because the key never matched, say — would look
     * identical to a comparison that passed, and the whole file would be a
     * green assertion about nothing.
     *
     * The counter-probe is the mirror image: for the bodies above, the reader
     * and phpdotenv DO agree, so the same comparison distinguishes the two.
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

        // The control that makes the first assertion meaningful: hand the
        // comparison a deliberately wrong reader result and require the same
        // `assertSame` to reject it. Without this, "both returned `sync`" could
        // also be explained by a comparison that never ran.
        $this->assertNotSame(
            'sync',
            '"sync"',
            'PREMISE: the quoted and unquoted readings must differ, or the comparison above cannot distinguish them.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* One reader, two scripts */
    /* ------------------------------------------------------------------ */

    /**
     * Both scripts must SOURCE the shared reader, and neither may define its own.
     *
     * The parsing assertions above pass either way — a script that grew a private
     * reader would still be tested through the shared one. L1 exists precisely
     * because two copies drifted, so the copy itself has to be the failure.
     */
    public function test_both_scripts_source_the_one_reader(): void
    {
        foreach (['scripts/e2e-up.sh', 'scripts/dev-worker.sh'] as $script) {
            $source = $this->repositoryFile($script);

            $this->assertStringContainsString(
                '. "$ROOT_DIR/'.self::READER.'"',
                $source,
                "{$script} must source the shared reader. Two copies of this rule drifted apart once already "
                .'(L1): e2e-up.sh read QUEUE_CONNECTION="sync" as a connection that is not `sync`, and told the '
                .'reader to start a worker for a stack that delivers inline.',
            );

            // Strip comments first: both scripts DISCUSS `grep -E '^CACHE_STORE='` in
            // prose (they name the defect they fixed), and a scan that matched
            // the documentation would forbid the explanation. What must not
            // survive is a line of CODE that reads a key that way.
            $code = $this->strippedOfShellComments($source);

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
    /* The documented boundary */
    /* ------------------------------------------------------------------ */

    /**
     * The one measured disagreement is the multiline feature, and it is named.
     *
     * A randomized differential over 8000 bodies (measured 2026-10-03) found
     * exactly one input where the reader and phpdotenv part ways: an unbalanced
     * `="` that `Lines::looksLikeMultilineStart()` swallows as an unterminated
     * multiline value, so phpdotenv never emits the key at all.
     *
     * This test pins that boundary in BOTH directions — the divergence is real
     * and reproduced here, and the reader's own documentation names it. If either
     * side is renamed or the behaviour changes, one of these fails, so the
     * measurement in the docblock cannot quietly become a lie.
     */
    public function test_the_only_measured_divergence_is_the_documented_multiline_case(): void
    {
        $body = "QUEUE_CONNECTION=\";\t\n";

        $this->assertSame(
            [],
            Dotenv::parse($body),
            'PREMISE: phpdotenv must treat this unbalanced quote as an unterminated multiline and emit '
            .'nothing — that is what makes it the documented divergence.',
        );

        $m = new \ReflectionMethod(Lines::class, 'looksLikeMultilineStart');
        $m->setAccessible(true);
        $this->assertTrue(
            $m->invoke(null, rtrim($body, "\n")),
            'PREMISE: the divergence must be the multiline feature, not an unaccounted-for difference.',
        );

        // The reader's answer for the same body, and the reason the difference is
        // safe: a value that cannot be a connection name.
        $this->assertNotSame(
            Dotenv::parse($body)['QUEUE_CONNECTION'] ?? '',
            $this->readWithShellReader($body, 'QUEUE_CONNECTION'),
            'The reader and phpdotenv are EXPECTED to differ here. If they no longer do, the divergence '
            .'recorded in the docblock and in this class is stale and must be updated, not left standing.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Run `dotenv_value` from the real script file and return what it printed.
     *
     * The function is EXTRACTED from `scripts/lib/dotenv-value.sh` with `sed`
     * rather than retyped here, so the test cannot drift away from the file the
     * scripts actually source — a copy in this test would be a third copy, and
     * the third thing that can be wrong.
     */
    private function readWithShellReader(string $body, string $key): string
    {
        $reader = $this->repositoryPathOf(self::READER);
        $this->assertFileIsReadable($reader, "PREMISE: {$reader} must be readable.");

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

        $function = implode("\n", array_merge(
            $this->lines($reader, $start, $end),
            $this->lines($reader, $parseStart, $parseEnd),
        ));

        $envFile = tempnam(sys_get_temp_dir(), 'dotenv-reader-');
        $this->assertIsString($envFile, 'PREMISE: a temporary file for the .env body must be creatable.');
        file_put_contents($envFile, $body);

        // The extracted function goes in over STDIN, not as an argument: it is
        // shell source of arbitrary length, and `Process` treats its 4th
        // constructor argument as input rather than as argv. The file and the key
        // follow the `-c` script as real positional parameters, so a value
        // containing shell metacharacters cannot be interpolated into code.
        $process = new Process(
            ['bash', '-c', 'set -euo pipefail; source /dev/stdin; dotenv_value "$1" "$2"', 'dotenv-reader', $envFile, $key],
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
