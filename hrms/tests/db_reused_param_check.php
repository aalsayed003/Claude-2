<?php
/**
 * Regression guard for the pdo_sqlsrv "reused named parameter" fix.
 *
 * pdo_sqlsrv rejects a :name used more than once in a statement (SQLSTATE
 * 07002). Database::run() rewrites repeats to unique placeholders bound to the
 * same value. sqlite/mysql tolerate reuse, so this asserts the rewrite directly
 * and then proves a reused-param query still executes.
 *
 * Run:  HRMS_TEST_DB=... php tests/db_reused_param_check.php
 */
require __DIR__ . '/../app/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\Database;

$db = Database::app();
$fail = 0;
$ok = function (string $l, bool $c, string $x = '') use (&$fail) {
    echo ($c ? "  ok    " : "  FAIL  ") . str_pad($l, 46) . " $x\n"; if (!$c) $fail++;
};

// --- unit: the private rewriter ---
$m = new ReflectionMethod(Database::class, 'expandRepeatedParams');
$m->setAccessible(true);
[$sql, $params] = $m->invoke($db,
    "SELECT * FROM t WHERE a <= :m AND (b IS NULL OR b > :m) AND c = :x",
    [':m' => 5, ':x' => 9]);
$ok('first :m kept, repeat renamed', substr_count($sql, ':m ') >= 1 && str_contains($sql, ':m_r2'), $sql);
$ok(':x (single use) untouched', substr_count($sql, ':x') === 1 && !str_contains($sql, ':x_r'));
$ok('renamed placeholder bound to same value', ($params[':m_r2'] ?? null) === 5);
$ok(':month not clipped by :m rule',
    str_contains($m->invoke($db, "WHERE x=:month AND y=:month", [':month' => 1])[0], ':month_r2'));

// --- behavioural: a reused-param query executes and returns the row ---
try {
    $row = $db->one(
        "SELECT EmpCode FROM " . lt('employee') .
        " WHERE ID = :id AND (ID = :id OR ID = 0)", [':id' => 202]);
    $ok('reused-param query executes', is_array($row) || $row === null);
} catch (\Throwable $e) {
    $ok('reused-param query executes', false, substr($e->getMessage(), 0, 60));
}

echo "\n" . ($fail ? "REUSED-PARAM CHECK: $fail FAILED\n" : "REUSED-PARAM CHECK: ALL PASSED\n");
exit($fail ? 1 : 0);
