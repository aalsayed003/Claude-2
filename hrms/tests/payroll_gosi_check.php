<?php
/**
 * GOSI category + effective-dating check.
 *   Bahraini : 7% SI + 1% unemployment = 8% employee
 *   Retiree  : 1% SI
 *   Expat    : 1% SI
 * A future rate change applies only to months on/after its effective date.
 *
 * Run: HRMS_TEST_DB=... php tests/payroll_gosi_check.php
 */
require __DIR__ . '/../app/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\Database;
use App\Payroll\Repositories\StatutoryRepository;
use App\Payroll\Services\GosiCalculator;

$db = Database::app();
$repo = new StatutoryRepository($db);
$gosi = new GosiCalculator($repo);
$fail = 0;
$ok = function (string $l, bool $c, string $x = '') use (&$fail) {
    echo ($c ? "  ok    " : "  FAIL  ") . str_pad($l, 52) . " $x\n"; if (!$c) $fail++;
};

// category resolution
$ok('category: retiree wins', GosiCalculator::category(['IsBahraini' => 1, 'IsRetiree' => 1]) === 'retiree');
$ok('category: bahraini', GosiCalculator::category(['IsBahraini' => 1]) === 'bahraini');
$ok('category: expat (default)', GosiCalculator::category(['IsBahraini' => 0]) === 'expat');

// rate lookup
$b = $repo->gosiRate('bahraini', '2026-08-01');
$ok('bahraini split 7% SI + 1% unemp', $b['social_emp_pct'] == 7 && $b['unemp_emp_pct'] == 1 && $b['employee_pct'] == 8);
$ok('retiree 1% SI',  $repo->gosiRate('retiree', '2026-08-01')['employee_pct'] == 1);
$ok('expat 1% SI',    $repo->gosiRate('expat', '2026-08-01')['employee_pct'] == 1);

// computed deduction on a 500 contributory wage
$bhr = $gosi->compute(500, ['IsBahraini' => 1], '2026-08-01');
$ok('bahraini 500 -> 40 (35 SI + 5 unemp)', $bhr['employee'] == 40.0
    && $bhr['social_employee'] == 35.0 && $bhr['unemployment_employee'] == 5.0, '(' . $bhr['employee'] . ')');
$ret = $gosi->compute(500, ['IsBahraini' => 1, 'IsRetiree' => 1], '2026-08-01');
$ok('retiree 500 -> 5', $ret['employee'] == 5.0 && $ret['category'] === 'retiree');
$exp = $gosi->compute(500, ['IsBahraini' => 0], '2026-08-01');
$ok('expat 500 -> 5', $exp['employee'] == 5.0 && $exp['category'] === 'expat');

// effective-dating: add a future Bahraini 9% SI rate, 2030-02
$repo->saveGosiRate([
    'effective_from' => '2030-02-01', 'category' => 'bahraini',
    'social_emp_pct' => 9, 'unemp_emp_pct' => 1, 'social_er_pct' => 12, 'unemp_er_pct' => 1,
    'min_wage' => 0, 'max_wage' => 4000, 'notes' => 'test 2030 step',
]);
$ok('2026 month still 8% (old rate)', $repo->gosiRate('bahraini', '2026-08-01')['employee_pct'] == 8);
$ok('2030-03 month now 10% (9 SI + 1 unemp)', $repo->gosiRate('bahraini', '2030-03-01')['employee_pct'] == 10);
$ok('2030-01 (before change) still 8%', $repo->gosiRate('bahraini', '2030-01-01')['employee_pct'] == 8);

echo "\n" . ($fail ? "GOSI CHECK: $fail FAILED\n" : "GOSI CHECK: ALL PASSED\n");
exit($fail ? 1 : 0);
