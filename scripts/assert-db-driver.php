<?php

declare(strict_types=1);

/**
 * Gegenprobe für das Postgres-Portabilitäts-Gate (AGENTS.md §2).
 *
 * Bootet die App und bricht ab, wenn der von Laravel **aufgelöste** DB-Driver
 * nicht der erwartete ist. Geprüft wird bewusst nicht der ENV-String: das Gate
 * schaltet die Engine über `DB_CONNECTION`/`DB_HOST`/… um, und ein Gate, das
 * nur seine eigene Konfiguration abliest, beweist nichts. Fiele die
 * Umleitung aus irgendeinem Grund auf SQLite zurück, wäre der Testlauf ein
 * zweiter SQLite-Lauf — grün und ohne jede Prüfwirkung. Das ist der
 * schlimmste Ausgang, weil er als Absicherung missverstanden wird.
 *
 * Als eigenes Skript statt `php artisan tinker --execute`, weil ein `exit(1)`
 * im Tinker-Eval **geschluckt** wird (verifiziert: der Prozess endet mit 0, auch
 * wenn das Flag gar nicht greift) — als Exit-Code-Gate also unbrauchbar.
 *
 * Aufruf (muss aus `backend/` heraus erfolgen, damit die Laravel-Pfade auflösen):
 *     php ../scripts/assert-db-driver.php pgsql
 *
 * Exit-Code: 0 = erwarteter Driver, 1 = falscher Driver, 2 = Aufruf-/Boot-Fehler.
 *
 * @see .github/workflows/ci.yml (Job `backend-pgsql`) und scripts/test-pgsql.sh —
 *       beide benutzen diese Datei, damit CI und lokal dieselbe Prüfung fahren.
 */

$expected = $argv[1] ?? 'pgsql';

$autoload = 'vendor/autoload.php';
$bootstrap = 'bootstrap/app.php';

if (! is_file($autoload) || ! is_file($bootstrap)) {
    fwrite(STDERR, "assert-db-driver: laravel bootstrap not found in ".getcwd()." — run this from backend/\n");

    exit(2);
}

require $autoload;

/** @var Illuminate\Foundation\Application $app */
$app = require $bootstrap;

try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $resolved = Illuminate\Support\Facades\DB::connection()->getDriverName();
} catch (Throwable $e) {
    fwrite(STDERR, 'assert-db-driver: boot/connect failed: '.$e->getMessage()."\n");

    exit(2);
}

fwrite(STDERR, sprintf("resolved driver: %s (expected: %s)\n", $resolved, $expected));

if ($resolved !== $expected) {
    fwrite(STDERR, "assert-db-driver: FAILED — the gate would not test what it claims to test.\n");

    exit(1);
}

exit(0);
