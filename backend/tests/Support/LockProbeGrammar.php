<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Tests\Feature\AllocationAtomicityTest;

/**
 * A SQLite grammar that records every row lock the application *requests* and
 * then behaves exactly like the real one.
 *
 * Why this exists: `SQLiteGrammar::compileLock()` returns `''`, so a
 * `SELECT … FOR UPDATE` is silently downgraded to a plain `SELECT` on the test
 * engine. A test that asserts "the emitted SQL contains FOR UPDATE" is
 * therefore worthless on SQLite — it passes with and without the lock, which
 * is exactly the false confidence the allocation-atomicity work must avoid.
 *
 * Hooking `compileLock()` observes the *request* instead: the test sees that
 * the production code path really called `lockForUpdate()`, on which table and
 * with which strength, while the statement still executes normally. The
 * companion assertion in `AllocationAtomicityTest` shows that
 * `PostgresGrammar` turns the recorded value into a real `for update`.
 *
 * @see AllocationAtomicityTest
 */
final class LockProbeGrammar extends SQLiteGrammar
{
    /**
     * Every lock request the code under test made, in order.
     *
     * @var list<array{table: string, value: bool|string, compiled: string}>
     */
    public array $locks = [];

    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
    }

    /**
     * Record the request, then delegate — the statement stays valid SQLite.
     *
     * @param  bool|string  $value
     */
    protected function compileLock(Builder $query, $value)
    {
        $compiled = parent::compileLock($query, $value);

        $this->locks[] = [
            'table' => (string) $query->from,
            'value' => $value,
            'compiled' => $compiled,
        ];

        return $compiled;
    }
}
